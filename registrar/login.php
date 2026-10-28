<?php
// Redirect to unified login
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
header('Location: ' . $base . '/login.php');
exit;
