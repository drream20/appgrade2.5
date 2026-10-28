<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
if (currentMisUserId()) {
    logActivity('mis', currentMisUserId(), 'logout');
}
misLogout();
$root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . $root . '/login.php');
exit;
