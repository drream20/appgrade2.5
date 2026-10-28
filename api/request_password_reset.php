<?php
require_once __DIR__ . "/../config/database.php";

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
if ($email === '') {
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

try {
    // Ensure reset table exists
    $conn->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(255) NOT NULL,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX (email),
            INDEX (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $conn->prepare("SELECT id FROM instructors WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["status" => "error", "message" => "Email not found"]);
        exit;
    }

    $token = bin2hex(random_bytes(16));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');

    $ins = $conn->prepare("
        INSERT INTO password_resets (email, token_hash, expires_at, used)
        VALUES (?, ?, ?, 0)
    ");
    $ins->execute([$email, $tokenHash, $expiresAt]);

    $subject = "Password Reset درخواست";
    $message = "We received a password reset request for your account.\n\n"
        . "Reset Code: $token\n\n"
        . "This code will expire in 1 hour.\n\n"
        . "If you did not request this, you can ignore this email.";

    $headers = "From: no-reply@yourdomain.com\r\n"
        . "Reply-To: no-reply@yourdomain.com\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    @mail($email, $subject, $message, $headers);

    echo json_encode([
        "status" => "success",
        "message" => "Reset code sent to email"
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
