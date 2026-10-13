<?php
/**
 * Rebuild completo sicuro FIC/SDI:
 * - preview: mostra cosa verra' toccato
 * - execute: cancella documenti FIC collegati localmente, resetta tracking locale,
 *   ricrea i documenti su FIC e ritenta invio SDI per fatture pagate.
 */
ob_start();
require_once __DIR__ . '/../../../auth.php';
require_login();

ini_set('display_errors', '0');

function fic_rebuild_json_response(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int)($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    fic_rebuild_json_response([
        'ok' => false,
        'error' => 'Errore fatale rebuild: ' . substr((string)($err['message'] ?? 'sconosciuto'), 0, 350),
    ], 500);
});

if (!has_admin_access()) {
    fic_rebuild_json_response(['ok' => false, 'error' => 'Accesso non autorizzato'], 403);
}

require_once __DIR__ . '/fic_api.php';
require_once __DIR__ . '/fic_mail_helpers.php';
require_once __DIR__ . '/fic_audit.php';

$pdo = require __DIR__ . '/../../../config/db.php';
$mode = strtolower(trim((string)($_REQUEST['mode'] ?? 'preview')));

// Hosting condiviso: evita che un singolo chunk resti troppo a lungo in attesa API.
$GLOBALS['FIC_API_MAX_ATTEMPTS'] = 3;
$GLOBALS['FIC_API_CONNECT_TIMEOUT'] = 6;
$GLOBALS['FIC_API_TIMEOUT'] = 18;

function fic_rebuild_is_db_gone_away(Throwable $e): bool
{
    $msg = strtolower((string)$e->getMessage());
    return (strpos($msg, 'server has gone away') !== false)
        || (strpos($msg, 'lost connection to mysql server') !== false)
        || (strpos($msg, 'sqlstate[hy000]') !== false && strpos($msg, '2006') !== false);
}

function fic_rebuild_open_statements(PDO $pdo): array
{
    $updReset = $pdo->prepare("UPDATE fatture
                               SET fic_document_id = NULL,
                                   fic_status = NULL,
                                   fic_pdf_url = NULL,
                                   fic_synced_at = NULL,
                                   ei_status = NULL,
                                   ei_error = NULL,
                                   ei_sent_at = NULL
                               WHERE id = :id");
    $righeStmt = $pdo->prepare('SELECT * FROM fatture_righe WHERE fattura_id = :id ORDER BY id ASC');
    return [$updReset, $righeStmt];
}

function fic_rebuild_reconnect(PDO &$pdo, &$updReset, &$righeStmt): void
{
    unset($GLOBALS['_tdg_pdo']);
    $pdo = require __DIR__ . '/../../../config/db.php';
    [$updReset, $righeStmt] = fic_rebuild_open_statements($pdo);
}

function fic_rebuild_db_retry(callable $fn, PDO &$pdo, &$updReset, &$righeStmt)
{
    try {
        return $fn();
    } catch (Throwable $e) {
        if (!fic_rebuild_is_db_gone_away($e)) {
            throw $e;
        }
        fic_rebuild_reconnect($pdo, $updReset, $righeStmt);
        return $fn();
    }
}

try {
    $cfg = fic_config();
    $companyId = (string)($cfg['company_id'] ?? '');
    if ($companyId === '') {
        $companyId = (string)fic_get_company_id();
    }
    if ($companyId === '') {
        throw new RuntimeException('company_id FIC mancante');
    }

    if ($mode === 'preview') {
        $totalInvoices = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice'")->fetchColumn();
        $linkedDocs = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice' AND fic_document_id IS NOT NULL AND TRIM(fic_document_id) <> ''")->fetchColumn();
        $duplicateUnresolved = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice' AND LOWER(COALESCE(fic_status,'')) = 'duplicate_unresolved'")->fetchColumn();
        $paidInvoices = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice' AND LOWER(COALESCE(stato,'')) = 'pagata'")->fetchColumn();
        $pendingSdi = (int)$pdo->query("SELECT COUNT(*) FROM fatture WHERE document_type = 'invoice' AND LOWER(COALESCE(stato,'')) = 'pagata' AND LOWER(COALESCE(NULLIF(ei_status,''),'todo')) NOT IN ('sent','processing','accepted','ok')")->fetchColumn();
        fic_rebuild_json_response([
            'ok' => true,
            'mode' => 'preview',
            'summary' => [
                'total_invoices' => $totalInvoices,
                'linked_docs' => $linkedDocs,
                'duplicate_unresolved' => $duplicateUnresolved,
                'paid_invoices' => $paidInvoices,
                'pending_sdi_paid' => $pendingSdi,
            ],
            'warning' => 'Operazione distruttiva su FIC: elimina i documenti collegati localmente e li ricrea.',
        ]);
    }

    if (!in_array($mode, ['execute', 'execute_chunk'], true)) {
        fic_rebuild_json_response(['ok' => false, 'error' => 'Mode non valido'], 422);
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        fic_rebuild_json_response(['ok' => false, 'error' => 'Metodo non valido'], 405);
    }

    $confirmText = trim((string)($_POST['confirm_text'] ?? ''));
    if ($confirmText !== 'RIGENERA COMPLETO') {
        fic_rebuild_json_response(['ok' => false, 'error' => 'Conferma testuale non valida'], 422);
    }

    @set_time_limit(120);

    $chunk = (int)($_POST['chunk'] ?? 5);
    if ($chunk < 1) {
        $chunk = 1;
    }
    if ($chunk > 4) {
        $chunk = 4;
    }

    $afterId = (int)($_POST['after_id'] ?? 0);

        $updReset = null;
        $righeStmt = null;
        fic_rebuild_reconnect($pdo, $updReset, $righeStmt);

        $fatture = fic_rebuild_db_retry(static function () use (&$pdo, $afterId, $chunk) {
                $sel = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                                                            FROM fatture f
                                                            JOIN clienti c ON c.id = f.cliente_id
                                                            WHERE f.document_type = 'invoice'
                                                                AND f.id > :after_id
                                                            ORDER BY f.id ASC
                                                            LIMIT :lim");
                $sel->bindValue(':after_id', $afterId, PDO::PARAM_INT);
                $sel->bindValue(':lim', $chunk, PDO::PARAM_INT);
                $sel->execute();
                return $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }, $pdo, $updReset, $righeStmt);

    if (!$fatture) {
        $remaining = (int)fic_rebuild_db_retry(static function () use (&$pdo, $afterId) {
            $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type='invoice' AND id > :after_id");
            $remainingStmt->execute([':after_id' => $afterId]);
            return (int)$remainingStmt->fetchColumn();
        }, $pdo, $updReset, $righeStmt);
        fic_rebuild_json_response([
            'ok' => true,
            'mode' => 'execute_chunk',
            'processed' => 0,
            'done' => true,
            'remaining' => $remaining,
            'next_after_id' => $afterId,
            'message' => 'Nessuna fattura invoice trovata.',
        ]);
    }

    $stats = [
        'processed' => 0,
        'deleted_fic' => 0,
        'missing_fic' => 0,
        'delete_failed' => 0,
        'recreated_fic' => 0,
        'recreate_failed' => 0,
        'sdi_sent' => 0,
        'sdi_failed' => 0,
    ];
    $errors = [];

    $lastProcessedId = $afterId;

    foreach ($fatture as $fattura) {
        $fatturaId = (int)($fattura['id'] ?? 0);
        if ($fatturaId <= 0) {
            continue;
        }
        $lastProcessedId = $fatturaId;
        $stats['processed']++;

        $oldDocId = trim((string)($fattura['fic_document_id'] ?? ''));
        if ($oldDocId !== '') {
            try {
                $delResp = fic_request('DELETE', "/c/{$companyId}/issued_documents/{$oldDocId}");
                $delStatus = (int)($delResp['status'] ?? 0);
                if ($delStatus >= 200 && $delStatus < 300) {
                    $stats['deleted_fic']++;
                } elseif ($delStatus === 404) {
                    $stats['missing_fic']++;
                } else {
                    $stats['delete_failed']++;
                    if (count($errors) < 15) {
                        $msg = fic_response_error_snippet($delResp, 180);
                        $errors[] = 'Delete FIC #' . ($fattura['numero'] ?? $fatturaId) . ': HTTP ' . $delStatus . ' ' . $msg;
                    }
                }
            } catch (Throwable $e) {
                $stats['delete_failed']++;
                if (count($errors) < 15) {
                    $errors[] = 'Delete FIC #' . ($fattura['numero'] ?? $fatturaId) . ': ' . substr((string)$e->getMessage(), 0, 180);
                }
            }
        }

        $fresh = fic_rebuild_db_retry(static function () use (&$updReset, &$pdo, $fatturaId) {
            $updReset->execute([':id' => $fatturaId]);

            $stmtF = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                                    FROM fatture f
                                    JOIN clienti c ON c.id = f.cliente_id
                                    WHERE f.id = :id LIMIT 1");
            $stmtF->execute([':id' => $fatturaId]);
            return $stmtF->fetch(PDO::FETCH_ASSOC);
        }, $pdo, $updReset, $righeStmt);
        if (!$fresh) {
            $stats['recreate_failed']++;
            if (count($errors) < 15) {
                $errors[] = 'Reload fattura #' . $fatturaId . ' fallito.';
            }
            continue;
        }

        $righe = fic_rebuild_db_retry(static function () use (&$righeStmt, $fatturaId) {
            $righeStmt->execute([':id' => $fatturaId]);
            return $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }, $pdo, $updReset, $righeStmt);

        try {
            $attempt = 0;
            while (true) {
                try {
                    [$newDocId] = fic_create_if_missing($pdo, $fresh, $righe, $cfg, $companyId);
                    if (!$newDocId) {
                        throw new RuntimeException('Documento FIC non creato');
                    }
                    $stats['recreated_fic']++;

                    if (strtolower(trim((string)($fresh['stato'] ?? ''))) === 'pagata') {
                        try {
                            fic_send_invoice_electronic($pdo, $fresh, $righe, $cfg, $companyId, 'manual_rebuild_full');
                            $stats['sdi_sent']++;
                        } catch (Throwable $e) {
                            $stats['sdi_failed']++;
                            if (count($errors) < 15) {
                                $errors[] = 'SDI #' . ($fresh['numero'] ?? $fatturaId) . ': ' . substr((string)$e->getMessage(), 0, 180);
                            }
                        }
                    }
                    break;
                } catch (Throwable $e) {
                    if ($attempt >= 1 || !fic_rebuild_is_db_gone_away($e)) {
                        throw $e;
                    }

                    // Reconnect DB and reload data once for this invoice, then retry.
                    fic_rebuild_reconnect($pdo, $updReset, $righeStmt);
                    $fresh = fic_rebuild_db_retry(static function () use (&$updReset, &$pdo, $fatturaId) {
                        $updReset->execute([':id' => $fatturaId]);

                        $stmtF = $pdo->prepare("SELECT f.*, c.nome, c.email, c.indirizzo, c.piva, c.azienda, c.codice_sdi, c.pec
                                                FROM fatture f
                                                JOIN clienti c ON c.id = f.cliente_id
                                                WHERE f.id = :id LIMIT 1");
                        $stmtF->execute([':id' => $fatturaId]);
                        return $stmtF->fetch(PDO::FETCH_ASSOC);
                    }, $pdo, $updReset, $righeStmt);

                    $righe = fic_rebuild_db_retry(static function () use (&$righeStmt, $fatturaId) {
                        $righeStmt->execute([':id' => $fatturaId]);
                        return $righeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    }, $pdo, $updReset, $righeStmt);

                    $attempt++;
                }
            }
        } catch (Throwable $e) {
            $stats['recreate_failed']++;
            if (count($errors) < 15) {
                $errors[] = 'Recreate #' . ($fresh['numero'] ?? $fatturaId) . ': ' . substr((string)$e->getMessage(), 0, 180);
            }
        }

        usleep(120000);
    }

    $remaining = (int)fic_rebuild_db_retry(static function () use (&$pdo, $lastProcessedId) {
        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM fatture WHERE document_type='invoice' AND id > :after_id");
        $remainingStmt->execute([':after_id' => $lastProcessedId]);
        return (int)$remainingStmt->fetchColumn();
    }, $pdo, $updReset, $righeStmt);

    fic_rebuild_json_response([
        'ok' => true,
        'mode' => 'execute_chunk',
        'stats' => $stats,
        'errors' => $errors,
        'done' => $remaining <= 0,
        'remaining' => $remaining,
        'next_after_id' => $lastProcessedId,
        'message' => $remaining <= 0 ? 'Rebuild completo terminato' : 'Chunk rebuild completato',
    ]);
} catch (Throwable $e) {
    fic_rebuild_json_response(['ok' => false, 'error' => substr((string)$e->getMessage(), 0, 500)], 500);
}
