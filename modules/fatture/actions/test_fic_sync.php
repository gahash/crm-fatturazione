<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Solo admin');
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = require __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/fic_api.php';
    require_once __DIR__ . '/fic_alert_helpers.php';
    require_once __DIR__ . '/fic_mail_helpers.php';
    
    // Test 1: Verifica configurazione FIC
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    
    if ($companyId === '') {
        echo json_encode([
            'ok' => false,
            'error' => 'company_id FIC non configurato',
            'config' => $cfg
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Test 2: Verifica connessione FIC
    try {
        $testResp = fic_request('GET', "/c/{$companyId}/info/account");
        $accountInfo = [
            'status' => $testResp['status'],
            'company_name' => $testResp['json']['data']['company_name'] ?? null,
            'company_email' => $testResp['json']['data']['company_email'] ?? null,
        ];
    } catch (Throwable $e) {
        echo json_encode([
            'ok' => false,
            'error' => tdg_sdi_normalize_reason_text('Errore connessione FIC: ' . $e->getMessage()),
            'company_id' => $companyId
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Test 3: Verifica se la colonna metodo_pagamento esiste
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM fatture LIKE 'metodo_pagamento'");
    $hasMetodoPagamento = $columnsStmt->rowCount() > 0;
    
    // Test 4: Cerca fatture Stripe senza fic_document_id
    if ($hasMetodoPagamento) {
        $stmt = $pdo->prepare("
            SELECT f.id, f.numero, f.document_type, f.data_emissione, f.totale, 
                   f.fic_document_id, f.fic_status, f.mail_status, f.mail_error, f.metodo_pagamento,
                   c.nome, c.azienda, c.email, c.piva
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
              AND f.metodo_pagamento LIKE 'Stripe%'
            ORDER BY f.id DESC
            LIMIT 10
        ");
    } else {
        // Fallback: cerca tutte le fatture invoice
        $stmt = $pdo->prepare("
            SELECT f.id, f.numero, f.document_type, f.data_emissione, f.totale, 
                   f.fic_document_id, f.fic_status, f.mail_status, f.mail_error,
                   c.nome, c.azienda, c.email, c.piva
            FROM fatture f
            JOIN clienti c ON c.id = f.cliente_id
            WHERE f.document_type = 'invoice'
            ORDER BY f.id DESC
            LIMIT 10
        ");
    }
    $stmt->execute();
    $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($fatture as $f) {
        $result = [
            'id' => $f['id'],
            'numero' => $f['numero'],
            'cliente' => $f['azienda'] ?: $f['nome'],
            'totale' => $f['totale'],
            'fic_document_id' => $f['fic_document_id'],
            'fic_status' => $f['fic_status'],
            'mail_status' => $f['mail_status'],
            'mail_error' => tdg_sdi_normalize_reason_text((string)($f['mail_error'] ?? '')),
            'has_email' => !empty($f['email']),
            'has_piva' => !empty($f['piva']),
            'test' => null,
        ];
        
        // Se non ha fic_document_id, prova a crearlo
        if (empty($f['fic_document_id'])) {
            try {
                $righeStmt = $pdo->prepare("SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC");
                $righeStmt->execute([':id' => $f['id']]);
                $righe = $righeStmt->fetchAll(PDO::FETCH_ASSOC);
                
                [$docId, $status] = fic_create_if_missing($pdo, $f, $righe, $cfg, $companyId);
                
                $result['test'] = [
                    'ok' => true,
                    'created_fic_document_id' => $docId,
                    'status' => $status,
                    'message' => 'Documento FIC creato con successo'
                ];
            } catch (Throwable $e) {
                $result['test'] = [
                    'ok' => false,
                    'error' => tdg_sdi_normalize_reason_text($e->getMessage()),
                    'trace' => explode("\n", $e->getTraceAsString())
                ];
            }
        } else {
            // Verifica che il documento esista su FIC
            try {
                $checkResp = fic_request('GET', "/c/{$companyId}/issued_documents/{$f['fic_document_id']}");
                $result['test'] = [
                    'ok' => $checkResp['status'] >= 200 && $checkResp['status'] < 300,
                    'status_code' => $checkResp['status'],
                    'message' => $checkResp['status'] >= 200 && $checkResp['status'] < 300 
                        ? 'Documento FIC raggiungibile' 
                        : 'Documento FIC non raggiungibile'
                ];
            } catch (Throwable $e) {
                $result['test'] = [
                    'ok' => false,
                    'error' => tdg_sdi_normalize_reason_text('Errore verifica documento FIC: ' . $e->getMessage())
                ];
            }
        }
        
        $results[] = $result;
    }
    
    echo json_encode([
        'ok' => true,
        'company_id' => $companyId,
        'account_info' => $accountInfo,
        'has_metodo_pagamento_column' => $hasMetodoPagamento,
        'fatture_checked' => count($fatture),
        'migration_needed' => !$hasMetodoPagamento,
        'results' => $results
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => tdg_sdi_normalize_reason_text($e->getMessage()),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
