<?php if ($totalPages > 1): ?>
    <form method="get" style="margin-top:12px;display:flex;justify-content:center;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="q" value="<?php echo htmlspecialchars($q); ?>">
        <label for="per_page" class="muted">Mostra</label>
        <select name="per_page" id="per_page" onchange="this.form.submit()">
            <?php foreach ($perPageOptions as $opt): ?>
                <option value="<?php echo $opt; ?>" <?php echo $perPage == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
        </select>
        <span class="muted">per pagina</span>
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
            <?php if ($page > 1): ?>
                <a class="btn secondary" href="<?php echo 'index.php?' . http_build_query(['q' => $q, 'per_page' => $perPage, 'page' => 1]); ?>">&laquo;</a>
                <a class="btn secondary" href="<?php echo 'index.php?' . http_build_query(['q' => $q, 'per_page' => $perPage, 'page' => $page - 1]); ?>">&lsaquo;</a>
            <?php endif; ?>
            <span class="btn" style="pointer-events:none;opacity:.7;"> <?php echo $page; ?> / <?php echo $totalPages; ?> </span>
            <?php if ($page < $totalPages): ?>
                <a class="btn secondary" href="<?php echo 'index.php?' . http_build_query(['q' => $q, 'per_page' => $perPage, 'page' => $page + 1]); ?>">&rsaquo;</a>
                <a class="btn secondary" href="<?php echo 'index.php?' . http_build_query(['q' => $q, 'per_page' => $perPage, 'page' => $totalPages]); ?>">&raquo;</a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>
