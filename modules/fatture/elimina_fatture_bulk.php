<?php
require __DIR__ . '/../../auth.php';
require_login();
$pdo = require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../dashboard/cache_utils.php';

$idsRaw = $_POST['ids'] ?? [];
if (!is_array($idsRaw)) {
    $idsRaw = [];
}

$ids = [];
foreach ($idsRaw as $value) {
    $id = (int)$value;
    if ($id > 0) {
        $ids[$id] = $id;
    }
}
$ids = array_values($ids);

if (!$ids) {
    header('Location: index.php?bulk_delete_error=nessuna_selezione');
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

$selectSql = "SELECT id, stato FROM fatture WHERE id IN ($placeholders)";
$selectStmt = $pdo->prepare($selectSql);
foreach ($ids as $i => $id) {
    $selectStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
}
$selectStmt->execute();
$rows = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

$deletableIds = [];
$skipped = 0;
foreach ($rows as $row) {
    $stato = (string)($row['stato'] ?? '');
    if ($stato === 'pagata') {
        $skipped++;
        continue;
    }
    $deletableIds[] = (int)$row['id'];
}

$deleted = 0;
if ($deletableIds) {
    $deletePlaceholders = implode(',', array_fill(0, count($deletableIds), '?'));
    $deleteSql = "DELETE FROM fatture WHERE id IN ($deletePlaceholders)";
    $deleteStmt = $pdo->prepare($deleteSql);
    foreach ($deletableIds as $i => $id) {
        $deleteStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
    }
    $deleteStmt->execute();
    $deleted = (int)$deleteStmt->rowCount();
    if ($deleted > 0) {
        tdg_dashboard_cache_clear();
    }
}

header('Location: index.php?bulk_delete_ok=' . $deleted . '&bulk_delete_skipped=' . $skipped);
exit;
