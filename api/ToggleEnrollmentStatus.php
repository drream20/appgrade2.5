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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['mis_user_id'])) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Enrollment changes are restricted to MIS. Instructors cannot change enrollment status via this API.",
    ]);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$enrollmentId = (int)($data['enrollment_id'] ?? 0);
$newStatus = strtolower(trim($data['status'] ?? ''));

if ($enrollmentId <= 0 || !in_array($newStatus, ['enrolled', 'dropped'], true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Required: enrollment_id, status (enrolled or dropped)"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, status FROM enrollments WHERE id = ? LIMIT 1");
    $stmt->execute([$enrollmentId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current) {
        echo json_encode(["status" => "error", "message" => "Enrollment not found"]);
        exit;
    }

    $currentStatus = strtolower((string)$current['status']);
    if ($currentStatus === $newStatus) {
        echo json_encode(["status" => "error", "message" => "No changes needed"]);
        exit;
    }

    $upd = $conn->prepare("UPDATE enrollments SET status = :status WHERE id = :id");
    $upd->execute([
        ':status' => $newStatus,
        ':id' => $enrollmentId
    ]);

    echo json_encode(["status" => "success"]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
