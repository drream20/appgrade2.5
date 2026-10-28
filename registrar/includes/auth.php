<?php
/**
 * Registrar authentication
 */
require_once __DIR__ . '/../../includes/password_payload.php';
if (!function_exists('getDb')) {
    require __DIR__ . '/../config/database.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function registrarRequireLogin(): void {
    if (!empty($_SESSION['registrar_id'])) {
        return; // Registrar logged in - OK
    }
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if (!empty($_SESSION['mis_user_id'])) {
        header('Location: ' . $root . '/mis/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['instructor_id'])) {
        header('Location: ' . $root . '/instructor/dashboard.php');
        exit;
    }
    header('Location: ' . $root . '/login.php');
    exit;
}

function currentRegistrarId(): int {
    return (int) ($_SESSION['registrar_id'] ?? 0);
}

function registrarColumns(PDO $db, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = [];
    foreach ($db->query("SHOW COLUMNS FROM `registrars`") as $c) {
        $cache[] = $c['Field'];
    }
    return $cache;
}

function ensureRegistrarProfileColumns(PDO $db): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $cols = registrarColumns($db);
    $newCols = [
        'phone'       => "ALTER TABLE `registrars` ADD COLUMN `phone` VARCHAR(30) DEFAULT NULL AFTER `employee_id`",
        'address'     => "ALTER TABLE `registrars` ADD COLUMN `address` VARCHAR(500) DEFAULT NULL AFTER `phone`",
        'avatar_path' => "ALTER TABLE `registrars` ADD COLUMN `avatar_path` VARCHAR(500) DEFAULT NULL AFTER `address`",
    ];
    $changed = false;
    foreach ($newCols as $name => $sql) {
        if (!in_array($name, $cols, true)) {
            $db->exec($sql);
            $changed = true;
        }
    }
    if ($changed) {
        registrarColumns($db, true);
    }
    $ensured = true;
}

function resetCurrentRegistrarCache(): void {
    $GLOBALS['__current_registrar_cache__'] = null;
}

function currentRegistrar(): ?array {
    if (empty($_SESSION['registrar_id'])) return null;
    if (array_key_exists('__current_registrar_cache__', $GLOBALS) && $GLOBALS['__current_registrar_cache__'] !== null) {
        return $GLOBALS['__current_registrar_cache__'];
    }
    $db = getDb();
    require_once __DIR__ . '/../../includes/welcome_modal_db.php';
    welcomeModalEnsureColumns($db);

    $cols = registrarColumns($db);
    $select = ['id', 'email', 'full_name', 'employee_id', 'welcome_modal_ack'];
    foreach (['phone', 'address', 'avatar_path'] as $extra) {
        if (in_array($extra, $cols, true)) {
            $select[] = $extra;
        }
    }

    $stmt = $db->prepare('SELECT ' . implode(', ', $select) . ' FROM registrars WHERE id = ?');
    $stmt->execute([$_SESSION['registrar_id']]);
    $row = $stmt->fetch();
    $GLOBALS['__current_registrar_cache__'] = $row ?: null;
    return $GLOBALS['__current_registrar_cache__'];
}

function registrarLogin(string $email, string $password): bool {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, password_hash FROM registrars WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !verify_password_payload($password, $row['password_hash'])) return false;
    $_SESSION['registrar_id'] = (int) $row['id'];
    return true;
}

function registrarLogout(): void {
    unset($_SESSION['registrar_id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
