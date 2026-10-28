<?php
/**
 * Database configuration — single source of truth for the whole app.
 *
 * Local (XAMPP): defaults (localhost, appgrade, root, no password).
 * Hostinger: use instructor/config/database.local.php (see database.local.example.php)
 *   OR SetEnv in root .htaccess (variables also read from $_SERVER — Hostinger/LiteSpeed compatible).
 */

/**
 * Read config from getenv() or $_SERVER (SetEnv in Apache/LiteSpeed often appears in $_SERVER only).
 */
if (!function_exists('appgrade_env')) {
    function appgrade_env(string $key): ?string {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        return null;
    }
}

$local = __DIR__ . '/database.local.php';
if (is_file($local)) {
    require $local;
} else {
    $host = appgrade_env('APPGRADE_DB_HOST');
    $name = appgrade_env('APPGRADE_DB_NAME');
    $user = appgrade_env('APPGRADE_DB_USER');
    $pass = appgrade_env('APPGRADE_DB_PASS');

    define('DB_HOST', $host ?? 'localhost');
    define('DB_NAME', $name ?? 'appgrade');
    define('DB_USER', $user ?? 'root');
    define('DB_PASS', $pass !== null ? $pass : '');
    define('DB_CHARSET', 'utf8mb4');
}

$baseUrlEnv = appgrade_env('APPGRADE_BASE_URL');
if (!defined('BASE_URL')) {
    define(
        'BASE_URL',
        $baseUrlEnv !== null
            ? rtrim($baseUrlEnv, '/')
            : rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/')
    );
}

function getDb(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

$conn = getDb();
