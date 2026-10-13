<?php
$page = $page ?? 1;
$totalPages = $totalPages ?? 1;
$perPage = $perPage ?? 10;
$perPageOptions = $perPageOptions ?? [10, 20, 30, 50, 100];
if ($totalPages < 2) return;

function qpage(int $p): string {
    $query = $_GET;
    unset($query['refresh_sdi'], $query['refresh_links']);
    $query['page'] = $p;
    return 'index.php?' . http_build_query($query);
}
?>
<div class="card">
    <form method="get" style="display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php foreach ($_GET as $k => $v): ?>
            <?php if ($k !== 'per_page' && $k !== 'page' && $k !== 'refresh_sdi' && $k !== 'refresh_links'): ?>
                <input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars($v); ?>">
            <?php endif; ?>
        <?php endforeach; ?>
        <label for="per_page" class="muted">Mostra</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $perPage == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
        </select>
        <span class="muted">per pagina</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php if ($page > 1): ?>
                <a class="btn secondary" href="<?php echo qpage(1); ?>">&laquo;</a>
                <a class="btn secondary" href="<?php echo qpage($page - 1); ?>">&lsaquo;</a>
            <?php endif; ?>
            <span class="btn" style="pointer-events:none;opacity:.7;"> <?php echo $page; ?> / <?php echo $totalPages; ?> </span>
            <?php if ($page < $totalPages): ?>
                <a class="btn secondary" href="<?php echo qpage($page + 1); ?>">&rsaquo;</a>
                <a class="btn secondary" href="<?php echo qpage($totalPages); ?>">&raquo;</a>
            <?php endif; ?>
        </div>
    </form>
</div>

