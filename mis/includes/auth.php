<?php
/**
 * MIS Authentication
 */
require_once __DIR__ . '/../../includes/password_payload.php';
if (!function_exists('getDb')) {
    require __DIR__ . '/../../instructor/config/database.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function misRequireLogin(): void {
    if (!empty($_SESSION['mis_user_id'])) return;
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if (!empty($_SESSION['instructor_id'])) {
        header('Location: ' . $root . '/instructor/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['registrar_id'])) {
        header('Location: ' . $root . '/registrar/dashboard.php');
        exit;
    }
    header('Location: ' . $root . '/login.php');
    exit;
}

function currentMisUserId(): int {
    return (int) ($_SESSION['mis_user_id'] ?? 0);
}

function currentMisUser(): ?array {
    if (empty($_SESSION['mis_user_id'])) return null;
    $db = getDb();
    require_once __DIR__ . '/../../includes/welcome_modal_db.php';
    welcomeModalEnsureColumns($db);
    $stmt = $db->prepare('SELECT id, email, full_name, employee_id, welcome_modal_ack FROM mis_users WHERE id = ?');
    $stmt->execute([$_SESSION['mis_user_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function misLogin(string $email, string $password): bool {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, password_hash FROM mis_users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !verify_password_payload($password, $row['password_hash'])) return false;
    $_SESSION['mis_user_id'] = (int) $row['id'];
    return true;
}

function misLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
