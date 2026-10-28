<?php
/**
 * Authentication helpers - Instructor Recording System
 */
require_once __DIR__ . '/../../includes/password_payload.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireLogin(): void {
    if (!empty($_SESSION['instructor_id'])) {
        return; // Instructor logged in - OK
    }
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if (!empty($_SESSION['mis_user_id'])) {
        header('Location: ' . $root . '/mis/dashboard.php');
        exit;
    }
    if (!empty($_SESSION['registrar_id'])) {
        header('Location: ' . $root . '/registrar/dashboard.php');
        exit;
    }
    header('Location: ' . $root . '/login.php');
    exit;
}

function currentInstructorId(): int {
    return (int) ($_SESSION['instructor_id'] ?? 0);
}

function currentInstructor(): ?array {
    if (empty($_SESSION['instructor_id'])) return null;
    $db = getDb();
    require_once __DIR__ . '/../../includes/welcome_modal_db.php';
    welcomeModalEnsureColumns($db);
    $stmt = $db->prepare('SELECT id, email, full_name, employee_id, department, avatar_path, welcome_modal_ack FROM instructors WHERE id = ?');
    $stmt->execute([$_SESSION['instructor_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function login(string $email, string $password): bool {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, password_hash FROM instructors WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !verify_password_payload($password, $row['password_hash'])) return false;
    $_SESSION['instructor_id'] = (int) $row['id'];
    return true;
}

function logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
