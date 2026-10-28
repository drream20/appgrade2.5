<?php
require __DIR__ . '/includes/auth.php';
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (currentStudentId()) {
    header('Location: ' . $baseUrl . '/student/dashboard.php');
    exit;
}
header('Location: ' . $baseUrl . '/login.php');
exit;
