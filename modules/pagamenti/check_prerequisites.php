<?php
/**
 * Versione semplificata e sicura del check prerequisites
 */

header('Content-Type: application/json; charset=utf-8');

try {
    require __DIR__ . '/../../auth.php';
    require_login();
    
    $user = current_user();
    $userRole = isset($user['ruolo']) ? $user['ruolo'] : '';
    if ($userRole !== 'admin') {
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => 'Solo admin'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $pdo = require __DIR__ . '/../../config/db.php';
    $config = require __DIR__ . '/../../config/config.php';
    
    $checks = array();
    $allOk = true;
    
    // 1. Database columns
    $stmt = $pdo->query("SHOW COLUMNS FROM fatture");
    $columns = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }
    
    $neededColumns = array('metodo_pagamento', 'stripe_session_id', 'stripe_payment_intent_id', 'stripe_checkout_url');
    $missingColumns = array_diff($neededColumns, $columns);
    
    $checks['database'] = array(
        'name' => 'Migrazione Database',
        'ok' => empty($missingColumns),
        'message' => empty($missingColumns) 
            ? 'Tutte le colonne Stripe presenti' 
            : 'Colonne mancanti: ' . implode(', ', $missingColumns),
        'action' => empty($missingColumns) ? null : 'Esegui migrazione da Impostazioni'
    );
    if (!$checks['database']['ok']) {
        $allOk = false;
    }
    
    // 2. Stripe config
    $secretPath = __DIR__ . '/../../config/secret.php';
    $stripeKey = null;
    
    if (file_exists($secretPath)) {
        $secretConfig = require $secretPath;
        if (isset($secretConfig['stripe']['secret_key'])) {
            $stripeKey = $secretConfig['stripe']['secret_key'];
        }
    }
    
    $stripeConfigured = !empty($stripeKey) && strpos($stripeKey, 'sk_') === 0;
    
    $checks['stripe'] = array(
        'name' => 'Configurazione Stripe',
        'ok' => $stripeConfigured,
        'message' => $stripeConfigured ? 'Secret Key configurata' : 'Secret Key mancante',
        'action' => $stripeConfigured ? null : 'Configura Stripe in config/secret.php'
    );
    if (!$checks['stripe']['ok']) {
        $allOk = false;
    }
    
    // 3. FIC config
    $ficConfigured = false;
    $companyId = '';
    
    if (file_exists(__DIR__ . '/../fatture/actions/fic_api.php')) {
        try {
            require_once __DIR__ . '/../fatture/actions/fic_api.php';
            $ficCfg = fic_config();
            if (isset($ficCfg['company_id'])) {
                $companyId = (string)$ficCfg['company_id'];
            }
            if (empty($companyId)) {
                $companyId = (string)fic_get_company_id();
            }
            $ficConfigured = !empty($companyId);
        } catch (Exception $e) {
            $ficConfigured = false;
        }
    }
    
    $checks['fic'] = array(
        'name' => 'Fatture in Cloud',
        'ok' => $ficConfigured,
        'message' => $ficConfigured ? 'Company ID: ' . $companyId : 'Company ID non configurato',
        'action' => $ficConfigured ? null : 'Connetti FIC da Impostazioni'
    );
    if (!$checks['fic']['ok']) {
        $allOk = false;
    }
    
    // 4. Tables
    $hasPagamenti = $pdo->query("SHOW TABLES LIKE 'pagamenti'")->rowCount() > 0;
    $hasSettings = $pdo->query("SHOW TABLES LIKE 'invoice_send_settings'")->rowCount() > 0;
    
    $checks['tables'] = array(
        'name' => 'Tabelle Database',
        'ok' => $hasPagamenti && $hasSettings,
        'message' => ($hasPagamenti && $hasSettings) ? 'Tutte le tabelle presenti' : 'Alcune tabelle mancanti',
        'action' => ($hasPagamenti && $hasSettings) ? null : 'Le tabelle verranno create automaticamente'
    );
    
    // 5. SMTP (optional)
    $smtpConfigured = file_exists(__DIR__ . '/../../config/mail.php');
    
    $checks['smtp'] = array(
        'name' => 'Configurazione SMTP',
        'ok' => $smtpConfigured,
        'optional' => true,
        'message' => $smtpConfigured ? 'SMTP configurato' : 'SMTP non configurato',
        'action' => $smtpConfigured ? null : 'Configura SMTP da Impostazioni per invio email'
    );
    
    // 6. Auto-send (optional)
    $autoSendEnabled = false;
    if ($hasSettings) {
        $stmt = $pdo->prepare('SELECT send_mode, enabled FROM invoice_send_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute(array('fatture_send'));
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($settings) {
            $sendMode = isset($settings['send_mode']) ? (string)$settings['send_mode'] : 'manuale';
            $enabled = isset($settings['enabled']) ? (int)$settings['enabled'] : 0;
            $autoSendEnabled = ($sendMode === 'automatico') && ($enabled === 1);
        }
    }
    
    $checks['auto_send'] = array(
        'name' => 'Invio Automatico Email',
        'ok' => $autoSendEnabled,
        'optional' => true,
        'message' => $autoSendEnabled ? 'Attivo' : 'Disattivo (invio manuale)',
        'action' => $autoSendEnabled ? null : 'Attiva da Impostazioni'
    );
    
    // 7. Stripe clients (conta clienti con pagamenti Stripe)
    $stripeClients = 0;
    if ($hasPagamenti) {
        $stmt = $pdo->query("SELECT COUNT(DISTINCT cliente_id) as cnt FROM pagamenti WHERE cliente_id IS NOT NULL");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stripeClients = (int)$row['cnt'];
    }
    
    $checks['clients'] = array(
        'name' => 'Clienti con Pagamenti',
        'ok' => true,
        'info' => true,
        'message' => $stripeClients . ' clienti con pagamenti Stripe',
        'action' => null
    );
    
    // 8. Stripe invoices
    $stripeFatture = 0;
    if (in_array('metodo_pagamento', $columns)) {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM fatture WHERE metodo_pagamento LIKE 'Stripe%'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stripeFatture = (int)$row['cnt'];
    }
    
    $checks['invoices'] = array(
        'name' => 'Fatture Stripe Esistenti',
        'ok' => true,
        'info' => true,
        'message' => $stripeFatture . ' fatture Stripe create',
        'action' => null
    );
    
    // Summary
    $passed = 0;
    $failed = 0;
    $optionalFailed = 0;
    
    foreach ($checks as $check) {
        if ($check['ok']) {
            $passed++;
        } elseif (isset($check['optional']) && $check['optional']) {
            $optionalFailed++;
        } else {
            $failed++;
        }
    }
    
    echo json_encode(array(
        'ok' => $allOk,
        'ready' => $allOk,
        'checks' => $checks,
        'summary' => array(
            'total' => count($checks),
            'passed' => $passed,
            'failed' => $failed,
            'optional_failed' => $optionalFailed
        ),
        'message' => $allOk 
            ? 'Tutti i prerequisiti soddisfatti. Pronto per sincronizzare Stripe!' 
            : 'Alcuni prerequisiti non sono soddisfatti. Completa le azioni richieste.',
        'run_at' => date('c')
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'ok' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
