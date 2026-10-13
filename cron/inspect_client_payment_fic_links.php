<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/cron_secret.php';

$key = (string)($_GET['key'] ?? '');
if ($key === '' || !hash_equals(CRON_KEY, $key)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Chiave non valida']);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$clienteId = max(0, (int)($_GET['cliente_id'] ?? 0));
if ($search === '' && $clienteId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'search o cliente_id richiesti']);
    exit;
}

$pdo = require __DIR__ . '/../config/db.php';

try {
    $sql = "SELECT
                p.id AS pagamento_id,
                p.stripe_id,
                p.importo,
                p.valuta,
                p.stato,
                p.metodo,
                p.created_at,
                c.id AS cliente_id,
                TRIM(COALESCE(NULLIF(c.azienda, ''), NULLIF(c.nome, ''), CONCAT('Cliente #', c.id))) AS cliente,
                c.email,
                f.id AS fattura_id,
                f.numero AS fattura_numero,
                f.fic_document_id,
                f.fic_status,
                f.ei_status,
                f.ei_error,
                f.mail_error,
                f.data_emissione
            FROM pagamenti p
            LEFT JOIN clienti c ON c.id = p.cliente_id
            LEFT JOIN fatture f ON f.stripe_payment_intent_id = p.stripe_id
            WHERE 1=1";
    $params = [];

    if ($clienteId > 0) {
        $sql .= ' AND c.id = :cliente_id';
        $params[':cliente_id'] = $clienteId;
    }

    if ($search !== '') {
        $sql .= ' AND (c.email LIKE :q_email OR c.nome LIKE :q_nome OR c.azienda LIKE :q_azienda)';
        $like = '%' . $search . '%';
        $params[':q_email'] = $like;
        $params[':q_nome'] = $like;
        $params[':q_azienda'] = $like;
    }

    $sql .= ' ORDER BY p.created_at DESC, p.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $distinctPayments = [];
    $distinctInvoices = [];
    $distinctFicDocs = [];
    $sharedFicDocs = [];

    foreach ($rows as $row) {
        $stripeId = trim((string)($row['stripe_id'] ?? ''));
        $fatturaId = (int)($row['fattura_id'] ?? 0);
        $ficDocumentId = trim((string)($row['fic_document_id'] ?? ''));

        if ($stripeId !== '') {
            $distinctPayments[$stripeId] = true;
        }
        if ($fatturaId > 0) {
            $distinctInvoices[$fatturaId] = true;
        }
        if ($ficDocumentId !== '') {
            $distinctFicDocs[$ficDocumentId] = true;
            if (!isset($sharedFicDocs[$ficDocumentId])) {
                $sharedFicDocs[$ficDocumentId] = [];
            }
            if ($fatturaId > 0) {
                $sharedFicDocs[$ficDocumentId][$fatturaId] = (string)($row['fattura_numero'] ?? '');
            }
        }
    }

    $duplicateLinkedDocs = [];
    foreach ($sharedFicDocs as $ficDocumentId => $fatture) {
        if (count($fatture) > 1) {
            $duplicateLinkedDocs[] = [
                'fic_document_id' => $ficDocumentId,
                'fatture' => array_map(static function ($fatturaId, $numero): array {
                    return [
                        'fattura_id' => (int)$fatturaId,
                        'numero' => $numero,
                    ];
                }, array_keys($fatture), array_values($fatture)),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'search' => $search,
        'cliente_id' => $clienteId > 0 ? $clienteId : null,
        'summary' => [
            'rows' => count($rows),
            'distinct_payments' => count($distinctPayments),
            'distinct_local_invoices' => count($distinctInvoices),
            'distinct_linked_fic_documents' => count($distinctFicDocs),
            'shared_fic_document_count' => count($duplicateLinkedDocs),
        ],
        'shared_fic_documents' => $duplicateLinkedDocs,
        'rows_data' => $rows,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => substr((string)$e->getMessage(), 0, 300),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}