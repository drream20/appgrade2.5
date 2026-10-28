<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/auth.php';
registrarLogout();
logout();
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . $base . '/login.php');
exit;
