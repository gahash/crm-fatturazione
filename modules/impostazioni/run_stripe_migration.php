<?php
/**
 * Script di migrazione automatica per aggiungere colonne Stripe alla tabella fatture
 * Visitare questo URL una sola volta per eseguire la migrazione
 */

require __DIR__ . '/../../auth.php';
require_login();

$user = current_user();
if (($user['ruolo'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Solo admin può eseguire la migrazione');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrazione Database Stripe</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 900px; margin: 40px auto; padding: 20px; line-height: 1.6; }
        .card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        .success { background: #d1fae5; border-left: 4px solid #10b981; padding: 12px; margin: 12px 0; border-radius: 4px; }
        .error { background: #fee2e2; border-left: 4px solid #ef4444; padding: 12px; margin: 12px 0; border-radius: 4px; }
        .info { background: #dbeafe; border-left: 4px solid #3b82f6; padding: 12px; margin: 12px 0; border-radius: 4px; }
        .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin: 12px 0; border-radius: 4px; }
        pre { background: #f9fafb; padding: 12px; border: 1px solid #e5e7eb; border-radius: 4px; overflow-x: auto; font-size: 0.9em; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .btn { display: inline-block; background: #3b82f6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; border: none; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #2563eb; }
        .btn-secondary { background: #6b7280; }
        .btn-secondary:hover { background: #4b5563; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table th, table td { padding: 8px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        table th { background: #f9fafb; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🔧 Migrazione Database Stripe</h1>
        <p>Questo script aggiunge le colonne necessarie alla tabella <code>fatture</code> per supportare i pagamenti Stripe.</p>
    </div>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_migration'])) {
    try {
        $pdo = require __DIR__ . '/../../config/db.php';
        
        echo '<div class="card"><h2>📋 Esecuzione Migrazione</h2>';
        
        // Verifica colonne esistenti
        echo '<h3>1. Verifica colonne esistenti</h3>';
        $existing = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM fatture");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Field'];
        }
        
        $needed = [
            'data_pagamento' => "DATETIME NULL",
            'metodo_pagamento' => "VARCHAR(50) NULL",
            'stripe_session_id' => "VARCHAR(255) NULL",
            'stripe_payment_intent_id' => "VARCHAR(255) NULL",
            'stripe_checkout_url' => "TEXT NULL"
        ];
        
        $toAdd = [];
        $alreadyExists = [];
        
        foreach ($needed as $col => $def) {
            if (in_array($col, $existing, true)) {
                $alreadyExists[] = $col;
            } else {
                $toAdd[$col] = $def;
            }
        }
        
        if (!empty($alreadyExists)) {
            echo '<div class="info">✅ Colonne già esistenti: <code>' . implode('</code>, <code>', $alreadyExists) . '</code></div>';
        }
        
        if (empty($toAdd)) {
            echo '<div class="success"><strong>✅ Migrazione non necessaria!</strong><br>Tutte le colonne Stripe sono già presenti nella tabella fatture.</div>';
        } else {
            echo '<div class="warning">⚠️ Colonne da aggiungere: <code>' . implode('</code>, <code>', array_keys($toAdd)) . '</code></div>';
            
            echo '<h3>2. Aggiunta colonne</h3>';
            $added = [];
            $failed = [];
            
            foreach ($toAdd as $col => $def) {
                try {
                    $sql = "ALTER TABLE fatture ADD COLUMN {$col} {$def}";
                    $pdo->exec($sql);
                    $added[] = $col;
                    echo '<div class="success">✅ Aggiunta colonna <code>' . htmlspecialchars($col) . '</code></div>';
                } catch (Exception $e) {
                    $failed[] = ['column' => $col, 'error' => $e->getMessage()];
                    echo '<div class="error">❌ Errore aggiungendo <code>' . htmlspecialchars($col) . '</code>: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
            
            echo '<h3>3. Riepilogo</h3>';
            if (!empty($added)) {
                echo '<div class="success"><strong>✅ Colonne aggiunte con successo:</strong> <code>' . implode('</code>, <code>', $added) . '</code></div>';
            }
            if (!empty($failed)) {
                echo '<div class="error"><strong>❌ Colonne non aggiunte:</strong><ul>';
                foreach ($failed as $f) {
                    echo '<li><code>' . htmlspecialchars($f['column']) . '</code>: ' . htmlspecialchars($f['error']) . '</li>';
                }
                echo '</ul></div>';
            }
        }
        
        // Mostra struttura finale
        echo '<h3>4. Struttura finale tabella fatture</h3>';
        echo '<table><thead><tr><th>Colonna</th><th>Tipo</th><th>Null</th><th>Default</th></tr></thead><tbody>';
        $stmt = $pdo->query("SHOW COLUMNS FROM fatture");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $isNew = in_array($row['Field'], array_merge($added, array_keys($needed)), true);
            $style = $isNew ? ' style="background:#d1fae5;font-weight:600;"' : '';
            echo '<tr' . $style . '>';
            echo '<td><code>' . htmlspecialchars($row['Field']) . '</code></td>';
            echo '<td>' . htmlspecialchars($row['Type']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Null']) . '</td>';
            echo '<td>' . htmlspecialchars($row['Default'] ?? 'NULL') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        echo '<div style="margin-top:20px;">';
        echo '<a href="../impostazioni/index.php" class="btn">Torna alle Impostazioni</a> ';
        echo '<a href="../fatture/actions/test_fic_sync.php" class="btn-secondary btn">Esegui Test di Verifica</a>';
        echo '</div>';
        
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="card">';
        echo '<div class="error"><strong>❌ Errore fatale:</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>';
        echo '<h3>Stack trace:</h3>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '<a href="index.php" class="btn-secondary btn" style="margin-top:12px;">Torna alle Impostazioni</a>';
        echo '</div>';
    }
} else {
    // Form di conferma
    try {
        $pdo = require __DIR__ . '/../../config/db.php';
        
        echo '<div class="card">';
        echo '<h2>📊 Analisi Tabella Fatture</h2>';
        
        $stmt = $pdo->query("SHOW COLUMNS FROM fatture");
        $existing = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existing[] = $row['Field'];
        }
        
        $needed = [
            'data_pagamento' => 'Data e ora del pagamento',
            'metodo_pagamento' => 'Identifica il metodo di pagamento usato (es. Stripe, Bonifico, ecc.)',
            'stripe_session_id' => 'ID della sessione Checkout Stripe',
            'stripe_payment_intent_id' => 'ID del Payment Intent Stripe',
            'stripe_checkout_url' => 'URL della pagina di checkout Stripe'
        ];
        
        echo '<h3>Colonne necessarie per Stripe:</h3>';
        echo '<table><thead><tr><th>Colonna</th><th>Stato</th><th>Descrizione</th></tr></thead><tbody>';
        
        $missingCount = 0;
        foreach ($needed as $col => $desc) {
            $exists = in_array($col, $existing, true);
            if (!$exists) $missingCount++;
            
            echo '<tr>';
            echo '<td><code>' . htmlspecialchars($col) . '</code></td>';
            echo '<td>' . ($exists ? '<span style="color:#10b981;font-weight:600;">✅ Presente</span>' : '<span style="color:#ef4444;font-weight:600;">❌ Mancante</span>') . '</td>';
            echo '<td>' . htmlspecialchars($desc) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        if ($missingCount === 0) {
            echo '<div class="success"><strong>✅ Tutte le colonne sono già presenti!</strong><br>La migrazione non è necessaria.</div>';
            echo '<a href="index.php" class="btn" style="margin-top:12px;">Torna alle Impostazioni</a>';
        } else {
            echo '<div class="warning"><strong>⚠️ Trovate ' . $missingCount . ' colonne mancanti</strong></div>';
            
            echo '<h3>Cosa farà la migrazione:</h3>';
            echo '<ol style="margin:12px 0 12px 20px;">';
            echo '<li>Aggiungerà le colonne mancanti alla tabella <code>fatture</code></li>';
            echo '<li>Le colonne saranno <code>NULL</code> per default (compatibile con dati esistenti)</li>';
            echo '<li>Nessun dato esistente sarà modificato o cancellato</li>';
            echo '<li>Operazione reversibile (le colonne possono essere rimosse successivamente se necessario)</li>';
            echo '</ol>';
            
            echo '<div class="info"><strong>💡 Nota:</strong> Questa è un\'operazione sicura. Le colonne saranno aggiunte senza modificare dati esistenti.</div>';
            
            echo '<form method="post" style="margin-top:20px;">';
            echo '<button type="submit" name="confirm_migration" value="1" class="btn">🚀 Esegui Migrazione</button> ';
            echo '<a href="index.php" class="btn-secondary btn">Annulla</a>';
            echo '</form>';
        }
        
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="card">';
        echo '<div class="error"><strong>❌ Errore connessione database:</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>';
        echo '<a href="index.php" class="btn-secondary btn" style="margin-top:12px;">Torna alle Impostazioni</a>';
        echo '</div>';
    }
}
?>

    <div class="card" style="background:#f9fafb;border:none;">
        <p style="color:#6b7280;font-size:0.9rem;margin:0;"><strong>Script:</strong> modules/impostazioni/run_stripe_migration.php</p>
    </div>
</body>
</html>
