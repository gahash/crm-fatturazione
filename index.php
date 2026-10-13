<?php
require __DIR__ . '/auth.php';
$config = require __DIR__ . '/config/config.php';
$baseUrl = rtrim($config['app']['base_url'] ?? '', '/');

if (!current_user()) {
	header('Location: ' . $baseUrl . '/login.php');
	exit;
}

header('Location: ' . $baseUrl . '/modules/clienti/clienti.php');
exit;

