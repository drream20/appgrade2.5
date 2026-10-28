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
require_once __DIR__ . "/../instructor/includes/grades.php";

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$assessmentID = (int)($data['AssessmentID'] ?? $data['assessment_id'] ?? 0);
$type = $data['Type'] ?? $data['type'] ?? null;
$title = $data['Title'] ?? $data['title'] ?? null;
$maxScore = (int)($data['MaxScore'] ?? $data['Maxscore'] ?? $data['max_score'] ?? 0);
$scores = $data['Scores'] ?? $data['scores'] ?? [];

if (!$assessmentID || !$type || !$title) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields: AssessmentID, Type, Title"
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    // Update assessment
    $stmt = $conn->prepare("
        UPDATE assessment_activities
        SET record_type = ?, title = ?, max_score = ?
        WHERE id = ?
    ");
    $stmt->execute([$type, $title, $maxScore, $assessmentID]);

    // Update individual scores
    $updatedEnrollmentIds = [];
    $termIdForRecompute = 0;
    if (!empty($scores)) {
        $assessStmt = $conn->prepare("
            SELECT term_id
            FROM assessment_activities
            WHERE id = ?
            LIMIT 1
        ");
        $assessStmt->execute([$assessmentID]);
        $termIdForRecompute = (int)($assessStmt->fetchColumn() ?: 0);

        $recordEnrollStmt = $conn->prepare("
            SELECT enrollment_id FROM assessment_records WHERE id = ? LIMIT 1
        ");
        $updateScore = $conn->prepare("
            UPDATE assessment_records
            SET score = ?, max_score = ?, record_type = ?, title = ?, recorded_at = NOW()
            WHERE id = ? 
        ");
        foreach ($scores as $recordID => $score) {
            if ($recordID && $score !== null) {
                $recordEnrollStmt->execute([(int)$recordID]);
                $enrollmentId = (int)($recordEnrollStmt->fetchColumn() ?: 0);
                $updateScore->execute([
                    (int)$score,
                    $maxScore,
                    $type,
                    $title,
                    (int)$recordID
                ]);
                if ($enrollmentId > 0) {
                    $updatedEnrollmentIds[$enrollmentId] = true;
                }
            }
        }
    }

    if ($termIdForRecompute > 0) {
        foreach (array_keys($updatedEnrollmentIds) as $eid) {
            recomputeTermGrade($conn, (int)$eid, $termIdForRecompute);
        }
    }

    $conn->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Assessment and scores updated successfully",
        "assessmentID" => $assessmentID
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}