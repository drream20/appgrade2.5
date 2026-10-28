<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();
header('Location: dashboard.php');
exit;
