<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if (currentInstructorId()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
} else {
    header('Location: ' . $base . '/login.php');
}
exit;
