<?php
require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$email = $_GET['email'] ?? '';
if ($email === '') {
    echo json_encode([
        "status" => "error",
        "message" => "Missing email"
    ]);
    exit;
}

$stmt = $conn->prepare("SELECT id, email, full_name, department, avatar_path FROM instructors WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode([
        "status" => "error",
        "message" => "Instructor not found"
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
]);
