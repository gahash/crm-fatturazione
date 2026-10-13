<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../dashboard/cache_utils.php';
$id = (int)($_GET['id'] ?? 0);
$return = $_GET['return'] ?? '';

if ($id) {
    $check = $pdo->prepare("SELECT stato FROM fatture WHERE id = :id");
    $check->execute([':id' => $id]);
    $fattura = $check->fetch(PDO::FETCH_ASSOC);

    if ($fattura && (string)($fattura['stato'] ?? '') === 'pagata') {
        $redirect = $return !== '' ? $return : 'index.php';
        $redirect .= (strpos($redirect, '?') === false ? '?' : '&') . 'del_error=paid';
        header('Location: ' . $redirect);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM fatture WHERE id = :id");
    $stmt->execute([':id' => $id]);
    tdg_dashboard_cache_clear();
}

$redirect = $return !== '' ? $return : 'index.php';
header('Location: ' . $redirect);
exit;

