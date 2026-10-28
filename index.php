<?php
/**
 * Entry point - redirect to appropriate dashboard or unified login
 */
require __DIR__ . '/instructor/config/database.php';
require __DIR__ . '/instructor/includes/auth.php';
require __DIR__ . '/registrar/includes/auth.php';
require __DIR__ . '/mis/includes/auth.php';
require __DIR__ . '/student/includes/auth.php';
require __DIR__ . '/dean/includes/auth.php';

$baseUrl = defined('BASE_URL') ? BASE_URL : rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

if (currentMisUserId()) {
    header('Location: ' . $baseUrl . '/mis/dashboard.php');
    exit;
}
if (currentDeanId()) {
    header('Location: ' . $baseUrl . '/dean/dashboard.php');
    exit;
}
if (currentInstructorId()) {
    header('Location: ' . $baseUrl . '/instructor/dashboard.php');
    exit;
}
if (currentRegistrarId()) {
    header('Location: ' . $baseUrl . '/registrar/dashboard.php');
    exit;
}
if (currentStudentId()) {
    header('Location: ' . $baseUrl . '/student/dashboard.php');
    exit;
}
header('Location: ' . $baseUrl . '/login.php');
exit;
