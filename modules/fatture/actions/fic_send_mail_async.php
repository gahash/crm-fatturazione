<?php
require __DIR__ . '/../../../auth.php';
require_login();

$pdo = require __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

header('Content-Type: application/json');

$action = trim($_GET['action'] ?? '');

// Endpoint per iniziare invio asincrono
if ($action === 'start') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'ID fattura non valido']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
        $stmt->execute([':id' => $id]);
        $fattura = $stmt->fetch();

        if (!$fattura) {
            http_response_code(404);
            echo json_encode(['error' => 'Fattura non trovata']);
            exit;
        }

        // Marca lo stato come "in_progress" nel DB
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'pending',
                ':err' => null,
                ':id' => $id,
            ]);
        } catch (Throwable $e) {
        }

        echo json_encode([
            'success' => true,
            'id' => $id,
            'numero' => $fattura['numero'] ?? 'N/A',
            'message' => 'Invio avviato...'
        ]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Errore preparazione: ' . $e->getMessage()]);
        exit;
    }
}

// Endpoint per controllare stato invio
if ($action === 'status') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID fattura non valido']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, mail_status, mail_error, mail_sent_at FROM fatture WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Fattura non trovata']);
        exit;
    }

    echo json_encode([
        'id' => $id,
        'status' => $row['mail_status'] ?? 'pending',
        'error' => $row['mail_error'] ?? null,
        'sent_at' => $row['mail_sent_at'] ?? null,
    ]);
    exit;
}

// Endpoint per eseguire l'invio reale
if ($action === 'execute') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID fattura non valido']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec FROM fatture f JOIN clienti c ON c.id = f.cliente_id WHERE f.id = :id");
    $stmt->execute([':id' => $id]);
    $fattura = $stmt->fetch();

    if (!$fattura) {
        http_response_code(404);
        echo json_encode(['error' => 'Fattura non trovata']);
        exit;
    }

    $mailPath = __DIR__ . '/../../../config/mail.php';
    if (!file_exists($mailPath)) {
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'error',
                ':err' => 'Configurazione SMTP mancante.',
                ':id' => $id,
            ]);
        } catch (Throwable $e) {
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'Configurazione SMTP mancante.',
            'code' => 'SMTP_CONFIG_MISSING',
        ]);
        exit;
    }

    $cfgMail = require $mailPath;
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }

    if ($companyId === '') {
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'error',
                ':err' => 'company_id Fatture in Cloud mancante.',
                ':id' => $id,
            ]);
        } catch (Throwable $e) {
        }
        http_response_code(500);
        echo json_encode([
            'error' => 'company_id Fatture in Cloud mancante.',
            'code' => 'FIC_CONFIG_MISSING',
        ]);
        exit;
    }

    $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
    $righeStmt->execute([':id' => $id]);
    $righe = $righeStmt->fetchAll();

    $wasAlreadySent = !empty($fattura['mail_status']) && $fattura['mail_status'] === 'sent';

    try {
        fic_send_invoice_email($pdo, $fattura, $righe, $cfg, $companyId, $cfgMail, 'manuale');
        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err, mail_sent_at = NOW() WHERE id = :id");
            $upd->execute([
                ':status' => 'sent',
                ':err' => null,
                ':id' => $id,
            ]);
        } catch (Throwable $e) {
        }
        fatture_log($pdo, $id, 'send_mail_async', 'ok', $wasAlreadySent ? 'Reinvio email completato (async).' : 'Invio email completato (async).', [
            'numero' => $fattura['numero'] ?? null,
            'email' => $fattura['email'] ?? null,
            'resend' => $wasAlreadySent,
        ]);

        echo json_encode([
            'success' => true,
            'status' => 'sent',
            'numero' => $fattura['numero'] ?? null,
            'email' => $fattura['email'] ?? null,
        ]);
        exit;
    } catch (Throwable $e) {
        $msg = (string)$e->getMessage();
        $err = substr($msg, 0, 1000);

        try {
            $upd = $pdo->prepare("UPDATE fatture SET mail_status = :status, mail_error = :err WHERE id = :id");
            $upd->execute([
                ':status' => 'error',
                ':err' => $err,
                ':id' => $id,
            ]);
        } catch (Throwable $ex) {
        }

        fatture_log($pdo, $id, 'send_mail_async', 'error', 'Errore invio async: ' . substr($err, 0, 180));

        http_response_code(400);
        echo json_encode([
            'error' => $err,
            'status' => 'error',
        ]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Action non valida']);
exit;
