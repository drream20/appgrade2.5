<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/password_payload.php";

$instructorId =
    $_POST['instructor_id'] ??
    $_POST['InstructorID'] ??
    $_POST['id'] ??
    null;

$fullName = trim($_POST['full_name'] ?? '');
$employeeId = trim($_POST['employee_id'] ?? '');
$department = trim($_POST['department'] ?? '');
$newPassword = $_POST['new_password'] ?? '';
$confirmPassword = $_POST['confirm_password'] ?? '';

if (!$instructorId) {
    echo json_encode([
        "success" => false,
        "message" => "Missing instructor ID"
    ]);
    exit;
}

if ($newPassword !== '' && $newPassword !== $confirmPassword) {
    echo json_encode([
        "success" => false,
        "message" => "New password and confirmation do not match"
    ]);
    exit;
}

try {
    $db = $conn;
    $db->beginTransaction();

    $avatarPath = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['profile_image']['tmp_name'];
        $fileName = basename($_FILES['profile_image']['name']);
        $mimeType = mime_content_type($tmpName);
        $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];

        if (!in_array($mimeType, $allowed, true)) {
            throw new Exception("Invalid image format. Only PNG, JPG, JPEG, and WEBP are allowed.");
        }

        $uploadsDir = __DIR__ . '/../instructor/assets/uploads/instructors/';
        if (!is_dir($uploadsDir)) {
            @mkdir($uploadsDir, 0777, true);
        }

        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = $mimeType === 'image/png' ? 'png' : ($mimeType === 'image/webp' ? 'webp' : 'jpg');
        }

        $fileSave = $uploadsDir . 'instructor_' . $instructorId . '.' . $ext;
        if (!move_uploaded_file($tmpName, $fileSave)) {
            throw new Exception("Failed to save uploaded image.");
        }

        // Store a web-accessible path that matches BASE_URL host deployment.
        $avatarPath = '/instructor/assets/uploads/instructors/instructor_' . $instructorId . '.' . $ext;
    }

    $fields = [];
    $params = [];

    if ($fullName !== '') {
        $fields[] = "full_name = ?";
        $params[] = $fullName;
    }
    if ($employeeId !== '') {
        $fields[] = "employee_id = ?";
        $params[] = $employeeId;
    }
    if ($department !== '') {
        $fields[] = "department = ?";
        $params[] = $department;
    }
    if ($avatarPath !== null) {
        $fields[] = "avatar_path = ?";
        $params[] = $avatarPath;
    }
    if ($newPassword !== '') {
        $fields[] = "password_hash = ?";
        $params[] = hash_password_for_storage($newPassword);
    }

    if (!empty($fields)) {
        $fields[] = "updated_at = NOW()";
        $params[] = (int)$instructorId;
        $stmt = $db->prepare("UPDATE instructors SET " . implode(", ", $fields) . " WHERE id = ?");
        $stmt->execute($params);
    }

    $db->commit();

    // Always return the currently stored avatar path, even when no new upload happens.
    $avatarStmt = $db->prepare("SELECT avatar_path FROM instructors WHERE id = ? LIMIT 1");
    $avatarStmt->execute([(int)$instructorId]);
    $storedAvatarPath = $avatarStmt->fetchColumn();

    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully",
        "avatar_path" => $storedAvatarPath,
    ]);
} catch (Exception $e) {
    if ($db && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
