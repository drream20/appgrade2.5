<?php
/**
 * Student authentication - Student Portal
 */
require_once __DIR__ . '/../../includes/password_payload.php';
if (!function_exists('getDb')) {
    require __DIR__ . '/../../instructor/config/database.php';
}
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function studentRequireLogin(): void {
    if (!empty($_SESSION['student_db_id'])) {
        return;
    }
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    header('Location: ' . $root . '/login.php');
    exit;
}

/**
 * Redirect to Profile until required fields are filled and default password is changed.
 * Allowed without redirect: profile.php, logout.php.
 */
function studentRequireProfileComplete(): void {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, ['profile.php', 'logout.php'], true)) {
        return;
    }
    if (empty($_SESSION['student_db_id'])) {
        return;
    }
    require_once __DIR__ . '/profile_requirements.php';
    $db = getDb();
    $row = student_fetch_profile_row($db, currentStudentId());
    if (!$row || student_portal_access_allowed($row)) {
        return;
    }
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    header('Location: ' . $root . '/student/profile.php?required=1');
    exit;
}

function currentStudentId(): int {
    return (int) ($_SESSION['student_db_id'] ?? 0);
}

function currentStudent(): ?array {
    if (empty($_SESSION['student_db_id'])) return null;
    $db = getDb();
    require_once __DIR__ . '/../../includes/welcome_modal_db.php';
    welcomeModalEnsureColumns($db);
    // Include image_path so layouts can display the student's profile image
    $stmt = $db->prepare('SELECT id, student_id, full_name, email, image_path, welcome_modal_ack FROM students WHERE id = ?');
    $stmt->execute([$_SESSION['student_db_id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Login by student_id (student number) or email + password
 */
function studentLogin(string $login, string $password): bool {
    $db = getDb();
    // First try student_id
    $stmt = $db->prepare('SELECT id, password_hash FROM students WHERE student_id = ?');
    $stmt->execute([trim($login)]);
    $row = $stmt->fetch();
    if (!$row && strpos($login, '@') !== false) {
        // Try email
        $stmt = $db->prepare('SELECT id, password_hash FROM students WHERE email = ?');
        $stmt->execute([trim($login)]);
        $row = $stmt->fetch();
    }
    if (!$row) return false;
    $hash = $row['password_hash'] ?? '';
    if (empty($hash)) return false;
    if (!verify_password_payload($password, $hash)) return false;
    $_SESSION['student_db_id'] = (int) $row['id'];
    return true;
}
