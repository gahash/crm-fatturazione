<?php
// Helper per risolvere i percorsi in modo affidabile
// Usa DOCUMENT_ROOT se disponibile, altrimenti fallback a __DIR__

function config_path(string $file = ''): string
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..';
    $configDir = $root . '/config';
    return $file === '' ? $configDir : $configDir . '/' . $file;
}

function tmp_path(string $file = ''): string
{
    $root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..';
    $tmpDir = $root . '/tmp';
    return $file === '' ? $tmpDir : $tmpDir . '/' . $file;
}
