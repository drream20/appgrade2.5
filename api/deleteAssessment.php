<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");
include_once __DIR__ . '/../config/database.php';
$conn = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

 
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$assessmentId = isset($data['AssessmentID']) ? (int)$data['AssessmentID'] : (int)($data['assessment_id'] ?? 0);

if (!$assessmentId) {
    echo json_encode(["status" => "error", "message" => "Missing AssessmentID"]);
    exit;
}

try {
    $conn->beginTransaction();

    $delRecords = $conn->prepare("DELETE FROM assessment_records WHERE activity_id = ?");
    $delRecords->execute([$assessmentId]);

    $delActivity = $conn->prepare("DELETE FROM assessment_activities WHERE id = ?");
    $delActivity->execute([$assessmentId]);

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Assessment deleted successfully"
    ]);
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
