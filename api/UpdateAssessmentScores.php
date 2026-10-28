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

// Accept both camelCase/snake_case variants to align with app payloads
$assessmentID = isset($data['AssessmentID'])
    ? (int)$data['AssessmentID']
    : (int)($data['assessment_id'] ?? 0);
$type = $data['Type'] ?? $data['type'] ?? null;
$title = $data['Title'] ?? $data['title'] ?? null;
$maxScore = isset($data['MaxScore'])
    ? (int)$data['MaxScore']
    : (int)($data['Maxscore'] ?? ($data['max_score'] ?? 0));
$scores = $data['Scores'] ?? $data['scores'] ?? []; // { recordID: score }

if (!$assessmentID || !$type || !$title) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields: AssessmentID, Type, Title"
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    // Update assessment details (type, title, max_score)
    $stmt = $conn->prepare("
        UPDATE assessment_activities
        SET record_type = ?, title = ?, max_score = ?
        WHERE id = ?
    ");
    $stmt->execute([$type, $title, $maxScore, $assessmentID]);

    // Update individual student scores
    $updatedEnrollmentIds = [];
    $termIdForRecompute = 0;
    if (!empty($scores)) {
        // Get the assessment info to use for updates
        $assessStmt = $conn->prepare("
            SELECT instructor_subject_id, term_id, activity_date
            FROM assessment_activities
            WHERE id = ?
        ");
        $assessStmt->execute([$assessmentID]);
        $assessInfo = $assessStmt->fetch(PDO::FETCH_ASSOC);

        if ($assessInfo) {
            $termIdForRecompute = (int)$assessInfo['term_id'];
            $updateByRecord = $conn->prepare("
                UPDATE assessment_records
                SET score = ?, max_score = ?, record_type = ?, title = ?, recorded_at = NOW()
                WHERE id = ? AND activity_id = ?
            ");
            $findByRecord = $conn->prepare("
                SELECT id, enrollment_id FROM assessment_records WHERE id = ? AND activity_id = ? LIMIT 1
            ");
            $findByEnroll = $conn->prepare("
                SELECT id, enrollment_id FROM assessment_records WHERE enrollment_id = ? AND activity_id = ? LIMIT 1
            ");
            $insertByEnroll = $conn->prepare("
                INSERT INTO assessment_records
                    (enrollment_id, term_id, activity_id, record_type, score, max_score, title, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            foreach ($scores as $keyId => $score) {
                if ($keyId === '' || $keyId === null || $score === null) continue;
                $keyIdInt = (int)$keyId;

                // Prefer record ID update if it exists for this activity
                $findByRecord->execute([$keyIdInt, $assessmentID]);
                $foundRecord = $findByRecord->fetch(PDO::FETCH_ASSOC);
                if ($foundRecord) {
                    $updateByRecord->execute([
                        (int)$score,
                        $maxScore,
                        $type,
                        $title,
                        (int)$foundRecord['id'],
                        $assessmentID
                    ]);
                    $updatedEnrollmentIds[(int)$foundRecord['enrollment_id']] = true;
                    continue;
                }

                // Otherwise treat key as enrollment_id and upsert
                $findByEnroll->execute([$keyIdInt, $assessmentID]);
                $existing = $findByEnroll->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $updateByRecord->execute([
                        (int)$score,
                        $maxScore,
                        $type,
                        $title,
                        (int)$existing['id'],
                        $assessmentID
                    ]);
                    $updatedEnrollmentIds[(int)$existing['enrollment_id']] = true;
                } else {
                    $insertByEnroll->execute([
                        $keyIdInt,
                        (int)$assessInfo['term_id'],
                        $assessmentID,
                        $type,
                        (int)$score,
                        $maxScore,
                        $title
                    ]);
                    $updatedEnrollmentIds[$keyIdInt] = true;
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
        "message" => "Assessment and scores updated successfully"
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
