<?php
/**
 * Simple file-based cache for dashboard Stripe metrics
 */

function get_cache_path(string $key): string {
    $cacheDir = __DIR__ . '/../../tmp/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    return $cacheDir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $key) . '.cache';
}

function cache_get(string $key, int $ttlSeconds = 600): ?array {
    $path = get_cache_path($key);
    if (!file_exists($path)) {
        return null;
    }
    
    $mtime = filemtime($path);
    if ($mtime === false || (time() - $mtime) > $ttlSeconds) {
        @unlink($path);
        return null;
    }
    
    $data = @file_get_contents($path);
    if ($data === false) {
        return null;
    }
    
    return @json_decode($data, true) ?: null;
}

function cache_set(string $key, array $data): void {
    $path = get_cache_path($key);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents($path, $json, LOCK_EX);
}

function cache_delete(string $key): void {
    $path = get_cache_path($key);
    @unlink($path);
}
