<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
include_once __DIR__ . '/../config/database.php';
$conn = getDb(); 

$subjectID = intval($_GET['Subject_ID'] ?? 0); // Cast to integer
$section = $_GET['section'] ?? null;
$termId = isset($_GET['termid']) ? (int) $_GET['termid'] : 0;

if (!$subjectID) {
    echo json_encode([
        "status" => "error",
        "data" => [],
        "message" => "Missing subject ID"
    ]);
    exit;
}

try {
    // Debug logging
    error_log("AssessmentRecord - Params: subjectID=$subjectID, section=$section");
    
    // First, find all relevant instructor_subject_ids for this subject
    $subStmt = $conn->prepare("
        SELECT DISTINCT id FROM instructor_subjects 
        WHERE subject_id = :sid
    ");
    $subStmt->execute([':sid' => $subjectID]);
    $instructorSubjects = $subStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $instructorSubjectIds = array_column($instructorSubjects, 'id');
    error_log("AssessmentRecord - Found " . count($instructorSubjectIds) . " instructor subjects");
    
    if (empty($instructorSubjectIds)) {
        // No instructor subjects found - return empty but successful
        echo json_encode([
            "status" => "success",
            "data" => []
        ]);
        exit;
    }
    
    // Now fetch assessment activities for those instructor subjects (optionally filtered by term)
    $placeholders = implode(',', array_fill(0, count($instructorSubjectIds), '?'));
    $where = "aa.instructor_subject_id IN ($placeholders)";
    $params = $instructorSubjectIds;
    if ($termId > 0) {
        $where .= " AND aa.term_id = ?";
        $params[] = $termId;
    }

    $stmt = $conn->prepare("
        SELECT 
            aa.id AS AssessmentID,
            aa.title AS Title,
            aa.record_type AS Type,
            aa.max_score AS Maxscore,
            DATE_FORMAT(aa.activity_date, '%Y-%m-%d') AS Date,
            COALESCE(aa.activity_time, '00:00:00') AS Time,
            COUNT(ar.id) AS students,
            AVG(ar.score) AS avgScore
        FROM assessment_activities aa
        LEFT JOIN assessment_records ar ON ar.activity_id = aa.id
        WHERE $where
        GROUP BY aa.id, aa.title, aa.record_type, aa.max_score, aa.activity_date, aa.activity_time
        ORDER BY aa.activity_date DESC, aa.activity_time DESC
    ");
    
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("AssessmentRecord - Returning " . count($results) . " assessments");

    echo json_encode([
        "status" => "success",
        "data" => $results
    ]);
} catch (Exception $e) {
    error_log("AssessmentRecord - Error: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "data" => [],
        "message" => $e->getMessage()
    ]);
}
