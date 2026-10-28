<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/password_payload.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$email = trim($data['email'] ?? '');
$token = trim($data['token'] ?? '');
$newPassword = $data['new_password'] ?? '';

if ($email === '' || $token === '' || $newPassword === '') {
    echo json_encode(["status" => "error", "message" => "Email, token and new_password are required"]);
    exit;
}

try {
    $tokenHash = hash('sha256', $token);

    $stmt = $conn->prepare("
        SELECT id FROM password_resets
        WHERE email = ? AND token_hash = ? AND used = 0 AND expires_at > NOW()
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$email, $tokenHash]);
    $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        echo json_encode(["status" => "error", "message" => "Invalid or expired token"]);
        exit;
    }

    $hash = hash_password_for_storage($newPassword);
    $upd = $conn->prepare("UPDATE instructors SET password_hash = ? WHERE email = ?");
    $upd->execute([$hash, $email]);

    $mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
    $mark->execute([$resetRow['id']]);

    echo json_encode(["status" => "success", "message" => "Password updated"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
