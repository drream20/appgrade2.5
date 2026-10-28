<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanLogout();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
header('Location: ' . $baseUrl . '/../login.php');
exit;

