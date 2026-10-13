<?php

function tdg_dashboard_cache_clear(): void
{
    $dir = __DIR__ . '/../../tmp/dashboard_cache';
    if (!is_dir($dir)) {
        return;
    }

    $pattern = $dir . '/*.json';
    $files = glob($pattern);
    if (!is_array($files)) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
