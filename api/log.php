<?php
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/password_payload.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Content-Type: application/json");

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$username = $_GET['username'] ?? '';
$password = normalize_password_input((string)($_GET['password'] ?? ''));

if ($username === '' || $password === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Missing username or password"
    ]);
    exit;
}

// Match web login behavior: validate password using password_hash
$stmt = $conn->prepare("SELECT id, email, full_name, department, avatar_path, password_hash FROM instructors WHERE email = ?");
$stmt->execute([$username]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || !verify_password_payload($password, $row['password_hash'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid credentials"
    ]);
    exit;
}

echo json_encode([
    "status" => "success",
    "role" => "instructor",
    "email" => $row['email'],
    "department" => $row['department'],
    "InstructorID" => $row['id'],
    "full_name" => $row['full_name'],
    "avatar_path" => $row['avatar_path'],
    // Keep a boolean flag for backwards compatibility if the app expects it
    "password" => true,
]);
