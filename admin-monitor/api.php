    case 'fic_error_log':
        $stmt = $pdo->prepare("SELECT l.creato_il, l.fattura_id, l.utente_nome, l.messaggio FROM fatture_log l WHERE l.azione LIKE 'fic_sync%' AND l.esito = 'error' ORDER BY l.creato_il DESC LIMIT 30");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        break;
<?php
// API endpoint per tutte le azioni AJAX di admin-monitor
require __DIR__ . '/../auth.php';
$pdo = require __DIR__ . '/../config/db.php';
$mailCfg = require __DIR__ . '/../config/mail.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Sessione scaduta']);
    exit;
}
if (!has_admin_access()) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
        case 'invia_fic':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                echo json_encode(['error' => 'Metodo non consentito']);
                break;
            }
            $input = json_decode(file_get_contents('php://input'), true);
            $id = isset($input['id']) ? (int)$input['id'] : 0;
            if ($id <= 0) {
                echo json_encode(['error' => 'ID non valido']);
                break;
            }
            // Qui va la logica reale di invio a Fatture in Cloud
            // Per ora stub: aggiorna solo lo stato come se fosse inviata
            $upd = $pdo->prepare("UPDATE fatture SET fic_status = 'ok', fic_synced_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $id]);
            // Logga l'azione
            $log = $pdo->prepare("INSERT INTO fatture_log (fattura_id, azione, esito, messaggio, utente_nome) VALUES (:fid, 'invia_fic', 'ok', 'Fattura inviata a FIC (stub)', :uname)");
            $log->execute([':fid' => $id, ':uname' => $user['nome'] ?? 'admin']);
            echo json_encode(['ok' => true]);
            break;
    case 'fatture_non_inviate':
        $stmt = $pdo->prepare("SELECT f.id, f.numero, f.data, f.mail_to, f.mail_subject, f.mail_status, c.ragione_sociale, c.email FROM fatture f LEFT JOIN clienti c ON f.cliente_id = c.id WHERE (f.mail_status IS NULL OR f.mail_status = '' OR f.mail_status != 'sent') AND f.mail_to IS NOT NULL AND f.mail_to != '' ORDER BY f.data DESC, f.id DESC LIMIT 50");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'invia_mail_fattura':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'Metodo non consentito']);
            break;
        }
        $input = json_decode(file_get_contents('php://input'), true);
        $id = isset($input['id']) ? (int)$input['id'] : 0;
        if ($id <= 0) {
            echo json_encode(['error' => 'ID non valido']);
            break;
        }
        $stmt = $pdo->prepare("SELECT f.id, f.mail_to, f.mail_subject, f.mail_status, f.mail_body, c.ragione_sociale FROM fatture f LEFT JOIN clienti c ON f.cliente_id = c.id WHERE f.id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $fattura = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fattura) {
            echo json_encode(['error' => 'Fattura non trovata']);
            break;
        }
        $to = $fattura['mail_to'];
        $subject = $fattura['mail_subject'] ?: 'Fattura elettronica';
        $body = $fattura['mail_body'] ?: 'Gentile cliente, in allegato trova la sua fattura.';
        $from = $mailCfg['from_email'] ?? 'noreply@tuodominio.com';
        $fromName = $mailCfg['from_name'] ?? 'CRM Fatturazione';
        $smtpHost = $mailCfg['smtp_host'] ?? '';
        $smtpUser = $mailCfg['smtp_user'] ?? '';
        $smtpPass = $mailCfg['smtp_pass'] ?? '';
        $smtpPort = $mailCfg['smtp_port'] ?? 587;
        $smtpSecure = $mailCfg['smtp_secure'] ?? 'tls';
        $ok = false;
        $err = '';
        if ($smtpHost && $smtpUser && $smtpPass) {
            if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
                require_once __DIR__ . '/../vendor/autoload.php';
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = $smtpHost;
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpUser;
                    $mail->Password = $smtpPass;
                    $mail->SMTPSecure = $smtpSecure;
                    $mail->Port = $smtpPort;
                    $mail->setFrom($from, $fromName);
                    $mail->addAddress($to);
                    $mail->Subject = $subject;
                    $mail->Body = $body;
                    $mail->CharSet = 'UTF-8';
                    $mail->isHTML(false);
                    $mail->send();
                    $ok = true;
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                }
            } else {
                $headers = "From: $from\r\nContent-Type: text/plain; charset=UTF-8";
                $ok = mail($to, $subject, $body, $headers);
                if (!$ok) $err = 'Errore invio email (mail())';
            }
        } else {
            $err = 'SMTP non configurato';
        }
        if ($ok) {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = 'sent', mail_sent_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $id]);
            echo json_encode(['ok' => true]);
        } else {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = 'error' WHERE id = :id");
            $upd->execute([':id' => $id]);
            echo json_encode(['error' => $err ?: 'Errore invio email']);
        }
        break;
    // ...altre azioni da spostare qui...
    default:
        echo json_encode(['error' => 'Azione non valida']);
        break;
}
