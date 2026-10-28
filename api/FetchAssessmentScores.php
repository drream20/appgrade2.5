<?php
// DEBUG: Remove next line in production!
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

// Early error logging
function log_error($msg) {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir.'/FetchAssessmentScores-error.log', date('[Y-m-d H:i:s] ').$msg.PHP_EOL, FILE_APPEND);
}

// Convert PHP warnings/notices into exceptions for consistent JSON error output
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Catch fatal errors that bypass try/catch
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        log_error("Fatal: {$err['message']} in {$err['file']}:{$err['line']}");
        if (!headers_sent()) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Fatal error on server.",
                "debug" => $err['message']
            ]);
        }
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";

// Accepts both camelCase and snake_case
$assessmentID = isset($_GET['AssessmentID']) ? (int)$_GET['AssessmentID'] : (isset($_GET['assessment_id']) ? (int)$_GET['assessment_id'] : 0);
$subjectID = isset($_GET['Subject_ID']) ? (int)$_GET['Subject_ID'] : (isset($_GET['subjectID']) ? (int)$_GET['subjectID'] : 0);
$section = isset($_GET['section']) ? trim($_GET['section']) : '';

if (!$assessmentID || !$subjectID) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing AssessmentID or Subject_ID"
    ]);
    exit;
}

if (empty($section)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing section"
    ]);
    exit;
}

try {
    // Fetch assessment details
    $assessStmt = $conn->prepare("
        SELECT 
            aa.id AS AssessmentID,
            aa.title AS Title,
            aa.record_type AS Type,
            CAST(aa.max_score AS UNSIGNED) AS MaxScore,
            DATE_FORMAT(aa.activity_date, '%Y-%m-%d') AS Date,
            aa.instructor_subject_id
        FROM assessment_activities aa
        WHERE aa.id = ?
        LIMIT 1
    ");
    $assessStmt->execute([$assessmentID]);
    $assessment = $assessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$assessment) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "Assessment not found"
        ]);
        exit;
    }

    $instructorSubjectId = $assessment['instructor_subject_id'];

    $rows = [];
    $queryOk = false;

    // Primary: class_sections.instructor_subject_id
    try {
        $stmt = $conn->prepare("
            SELECT 
                s.id AS studentID,
                s.student_id AS studentNumber,
                s.given_name AS first_name,
                s.last_name,
                e.id AS enrollment_id,
                ar.score AS score,
                ar.id AS recordID
            FROM enrollments e
            JOIN students s ON s.id = e.student_id
            JOIN class_sections cs ON cs.id = e.class_section_id
            LEFT JOIN assessment_records ar
                ON ar.enrollment_id = e.id AND ar.activity_id = ?
            WHERE cs.instructor_subject_id = ?
              AND cs.section = ?
            ORDER BY s.last_name ASC, s.given_name ASC
        ");
        $stmt->execute([$assessmentID, $instructorSubjectId, $section]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $queryOk = true;
    } catch (PDOException $e) {
        log_error("Primary query failed: " . $e->getMessage());
    }

    // Fallback: class_sections.subject_id
    if (!$queryOk) {
        try {
            $stmt = $conn->prepare("
                SELECT 
                    s.id AS studentID,
                    s.student_id AS studentNumber,
                    s.given_name AS first_name,
                    s.last_name,
                    e.id AS enrollment_id,
                    ar.score AS score,
                    ar.id AS recordID
                FROM enrollments e
                JOIN students s ON s.id = e.student_id
                JOIN class_sections cs ON cs.id = e.class_section_id
                LEFT JOIN assessment_records ar
                    ON ar.enrollment_id = e.id AND ar.activity_id = ?
                WHERE cs.subject_id = ?
                  AND cs.section = ?
                ORDER BY s.last_name ASC, s.given_name ASC
            ");
            $stmt->execute([$assessmentID, $subjectID, $section]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $queryOk = true;
        } catch (PDOException $e) {
            log_error("Fallback query failed: " . $e->getMessage());
        }
    }

    // Last resort: instructor_subject_id only (no section filter) to avoid 500
    if (!$queryOk) {
        $stmt = $conn->prepare("
            SELECT 
                s.id AS studentID,
                s.student_id AS studentNumber,
                s.given_name AS first_name,
                s.last_name,
                e.id AS enrollment_id,
                ar.score AS score,
                ar.id AS recordID
            FROM enrollments e
            JOIN students s ON s.id = e.student_id
            LEFT JOIN assessment_records ar
                ON ar.enrollment_id = e.id AND ar.activity_id = ?
            WHERE e.instructor_subject_id = ?
            ORDER BY s.last_name ASC, s.given_name ASC
        ");
        $stmt->execute([$assessmentID, $instructorSubjectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $students = [];
    $scores = [];
    $seenEnroll = [];
    foreach ($rows as $r) {
        $enrollId = $r['enrollment_id'];
        if (!isset($seenEnroll[$enrollId])) {
            $students[] = [
                'studentID' => $r['studentID'],
                'studentNumber' => $r['studentNumber'],
                'first_name' => $r['first_name'],
                'last_name' => $r['last_name'],
                'enrollment_id' => $enrollId
            ];
            $seenEnroll[$enrollId] = true;
        }
        $scores[] = [
            'enrollment_id' => $enrollId,
            'score' => $r['score'],
            'recordID' => $r['recordID']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "assessment" => $assessment,
        "students" => $students,
        "scores" => $scores
    ]);
} catch (Exception $e) {
    http_response_code(500);
    log_error("Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    echo json_encode([
        "status" => "error",
        "message" => "An error occurred on the server.",
        "debug" => $e->getMessage()
    ]);
}
