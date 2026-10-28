<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../instructor/includes/grades.php";
require_once __DIR__ . "/../instructor/includes/record_date_guard.php";
require_once __DIR__ . "/../instructor/includes/academic_year.php";

$data = json_decode(file_get_contents("php://input"), true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON payload",
    ]);
    exit;
}

$subjectID = $data['Subject_ID'] ?? $data['subject_id'] ?? null;
$instructorSubjectId = $data['InstructorSubjectID'] ?? $data['instructor_subject_id'] ?? null;
$section   = $data['Section'] ?? $data['section'] ?? null;
$title     = $data['Title'] ?? $data['title'] ?? null;
$typeRaw   = trim((string)($data['Type'] ?? $data['type'] ?? ''));
$date      = $data['Date'] ?? $data['date'] ?? null;
$scores    = $data['Scores'] ?? $data['scores'] ?? [];
$maxscore  = $data['MaxScore'] ?? $data['max_score'] ?? null;
$termId    = $data['Term'] ?? $data['term'] ?? null;

function normalize_record_type(string $raw): string {
    $normalized = strtolower(trim($raw));
    $normalized = str_replace(' ', '_', $normalized);
    switch ($normalized) {
        case 'class_participation':
            return 'class_participation';
        case 'graded_activity':
        case 'grade_activity':
            return 'graded_activity';
        case 'exercise':
        case 'excercise':
            return 'exercise';
        case 'values':
        case 'project':
        case 'assignment':
        case 'exam':
        case 'lab_exam':
        case 'labexam':
            return $normalized === 'labexam' ? 'lab_exam' : $normalized;
        case 'quiz':
            return $normalized;
        default:
            return 'quiz';
    }
}

$type = normalize_record_type($typeRaw);

// Temporary payload debug: append ?debug=1 when calling this endpoint.
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    error_log('saveAssessment payload: ' . json_encode($data));
    error_log('saveAssessment Type: ' . $type);
}

if ((!$subjectID && !$instructorSubjectId) || !$title || $type === '' || !$date || !is_array($scores) || empty($scores) || !$termId) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing data",
        "received_type" => $type
    ]);
    exit;
}

$dateStr = is_string($date) ? substr(trim($date), 0, 10) : '';
if (!record_date_is_not_future($dateStr)) {
    echo json_encode([
        "status" => "error",
        "message" => "Cannot record assessments for a future date. Use today or a past date.",
    ]);
    exit;
}
$date = $dateStr;

try {
    $conn->beginTransaction();

    // find an instructor_subject_id for this subject
    $isubRow = null;
    if ($instructorSubjectId) {
        $isubRow = (int)$instructorSubjectId;
    }

    if (!$isubRow && $subjectID) {
        $isub = $conn->prepare("SELECT id FROM instructor_subjects WHERE subject_id = :sid LIMIT 1");
        $isub->execute([':sid'=>$subjectID]);
        $isubRow = $isub->fetch(PDO::FETCH_COLUMN);
    }

    if (!$isubRow && $subjectID) {
        // try class_sections lookup to find instructor_subject_id
        $cs = $conn->prepare("SELECT instructor_subject_id FROM class_sections WHERE subject_id = :sid AND section = :section LIMIT 1");
        $cs->execute([':sid'=>$subjectID, ':section'=>$section]);
        $isubRow = $cs->fetch(PDO::FETCH_COLUMN);
    }

    if (!$isubRow) {
        throw new Exception('Unable to determine instructor_subject for this subject/section');
    }

    if (!canRecordTermForInstructorSubject($conn, (int) $isubRow, (int) $termId)) {
        throw new Exception('Recording is disabled for this term (locked by MIS) or the semester is locked.');
    }

    // If exam, lab exam, or values already exists for this subject + term, block creating another.
    if ($type === 'exam' || $type === 'lab_exam' || $type === 'values') {
        $chk = $conn->prepare("SELECT id, title, max_score, activity_date FROM assessment_activities WHERE instructor_subject_id = :isub AND term_id = :term AND record_type = :rtype LIMIT 1");
        $chk->execute([
            ':isub' => $isubRow,
            ':term' => (int)$termId,
            ':rtype' => $type,
        ]);
        $existingAssessment = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existingAssessment) {
            $msg = $type === 'values' ? 'values is existed' : ($type === 'lab_exam' ? 'lab exam is existed' : 'exam is existed');
            echo json_encode([
                "status" => "error",
                "message" => $msg,
                "existing_exam" => $existingAssessment,
            ]);
            exit;
        }
    }

    // insert activity
    // Equivalent of INSERT ... Type ... in this schema is record_type in assessment_activities.
    $insAct = $conn->prepare("INSERT INTO assessment_activities (instructor_subject_id, term_id, record_type, title, max_score, activity_date) VALUES (?,?,?,?,?,?)");
    $insAct->execute([$isubRow, (int)$termId, $type, $title, $maxscore, $date]);
    $activityId = $conn->lastInsertId();

    // get enrollments for this instructor_subject / class_section
    $enrollStmt = $conn->prepare("SELECT e.id AS enrollment_id, s.student_id AS student_number
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        LEFT JOIN class_sections cs ON cs.id = e.class_section_id
        WHERE e.instructor_subject_id = :isub
           OR (cs.instructor_subject_id = :isub AND cs.section = :section)");
    $enrollStmt->execute([':isub'=>$isubRow, ':section'=>$section]);
    $enrs = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentToEnrollment = [];
    $validEnrollmentIds = [];
    foreach ($enrs as $r) {
        $studentToEnrollment[$r['student_number']] = (int)$r['enrollment_id'];
        $validEnrollmentIds[(int)$r['enrollment_id']] = true;
    }

    $insRec = $conn->prepare("INSERT INTO assessment_records (enrollment_id, term_id, activity_id, record_type, score, max_score, title, recorded_at) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE score=VALUES(score), max_score=VALUES(max_score), title=VALUES(title)");

    $updatedEnrollmentIds = [];
    foreach ($scores as $key => $score) {
        if ($key === '' || $key === null) continue;
        $keyStr = (string)$key;
        $eid = null;

        // Prefer enrollment_id when provided (most reliable)
        $keyAsInt = (int)$keyStr;
        if ($keyAsInt > 0 && isset($validEnrollmentIds[$keyAsInt])) {
            $eid = $keyAsInt;
        } elseif (isset($studentToEnrollment[$keyStr])) {
            // Fallback to student number
            $eid = $studentToEnrollment[$keyStr];
        }

        if (!$eid) continue;
        $insRec->execute([$eid, (int)$termId, $activityId, $type, $score, $maxscore, $title, date('Y-m-d H:i:s')]);
        $updatedEnrollmentIds[$eid] = true;
    }

    foreach (array_keys($updatedEnrollmentIds) as $eid) {
        recomputeTermGrade($conn, (int)$eid, (int)$termId);
    }

    $conn->commit();
    echo json_encode(["status"=>"success"]);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
