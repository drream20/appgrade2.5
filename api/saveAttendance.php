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
require_once __DIR__ . "/../instructor/includes/record_date_guard.php";
require_once __DIR__ . "/../instructor/includes/academic_year.php";

$data = json_decode(file_get_contents("php://input"), true);

$section = $data['section'] ?? null;
$subject_ID = $data['subject_ID'] ?? null;
$term = $data['term'] ?? null;
$attendance = $data['attendance'] ?? [];
$sessionDate = $data['date'] ?? date('Y-m-d H:i:s'); // default to now
$edit = $data['edit'] ?? false;
$attendanceId = $data['attendance_id'] ?? ($data['id'] ?? null);

if (!$term || empty($attendance) || !$subject_ID) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid data received"
    ]);
    exit;
}

function mapStatusFull($s) {
    $s2 = strtoupper(trim((string)$s));
    if ($s2 === 'P') return 'present';
    if ($s2 === 'A') return 'absent';
    if ($s2 === 'L') return 'late';
    if ($s2 === 'E') return 'excused';
    $sLower = strtolower($s2);
    return in_array($sLower, ['present','absent','late','excused']) ? $sLower : $s2;
}

$dt = strtotime($sessionDate);
$datePart = $dt !== false ? date('Y-m-d', $dt) : '';
$timePart = $dt !== false ? date('H:i:s', $dt) : '00:00:00';

if ($datePart === '' || !record_date_is_not_future($datePart)) {
    echo json_encode([
        "status" => "error",
        "message" => "Cannot record attendance for a future date. Use today or a past date.",
    ]);
    exit;
}

try {
    $conn->beginTransaction();

    // get enrollments mapping for this subject/section
    $enrollStmt = $conn->prepare("SELECT e.id AS enrollment_id, s.student_id AS student_number
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        WHERE e.instructor_subject_id IN (SELECT id FROM instructor_subjects WHERE subject_id = :sub)
          OR e.class_section_id IN (SELECT id FROM class_sections WHERE subject_id = :sub AND section = :section)");
    $enrollStmt->execute([':sub' => $subject_ID, ':section' => $section]);
    $rows = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);
    $studentToEnrollment = [];
    foreach ($rows as $r) {
        $studentToEnrollment[$r['student_number']] = (int)$r['enrollment_id'];
    }

    if (!empty($rows)) {
        $sampleEid = (int) $rows[0]['enrollment_id'];
        $isubForLock = getInstructorSubjectIdFromEnrollment($conn, $sampleEid);
        if ($isubForLock && !canRecordTermForInstructorSubject($conn, $isubForLock, (int) $term)) {
            throw new Exception('Recording is disabled for this term (locked by MIS) or the semester is locked.');
        }
    }

    $oldDatePart = null;
    $oldTimePart = null;
    if ($edit && $attendanceId !== null) {
        $stmt = $conn->prepare("SELECT attendance_date, attendance_time FROM attendance_records WHERE id = ?");
        $stmt->execute([(int)$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $oldDatePart = date('Y-m-d', strtotime($row['attendance_date']));
            $oldTimePart = $row['attendance_time'] ?? '00:00:00';
        }
    }

    foreach ($attendance as $studentNumber => $status) {
        if (!isset($studentToEnrollment[$studentNumber])) continue;
        $enrollmentId = $studentToEnrollment[$studentNumber];
        $statusFull = mapStatusFull($status);

        if ($edit && $oldDatePart !== null && $oldTimePart !== null) {
            $upd = $conn->prepare("UPDATE attendance_records
                SET attendance_date = ?, attendance_time = ?, status = ?
                WHERE enrollment_id = ? AND term_id = ?
                  AND DATE(attendance_date) = ?
                  AND (attendance_time = ? OR (? = '00:00:00' AND attendance_time IS NULL))");
            $upd->execute([
                $datePart, $timePart, $statusFull,
                $enrollmentId, (int)$term,
                $oldDatePart, $oldTimePart, $oldTimePart
            ]);

            if ($upd->rowCount() === 0) {
                $ins = $conn->prepare("INSERT INTO attendance_records (enrollment_id, term_id, attendance_date, attendance_time, status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), attendance_time=VALUES(attendance_time)");
                $ins->execute([$enrollmentId, (int)$term, $datePart, $timePart, $statusFull]);
            }
        } else {
            $ins = $conn->prepare("INSERT INTO attendance_records (enrollment_id, term_id, attendance_date, attendance_time, status) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), attendance_time=VALUES(attendance_time)");
            $ins->execute([$enrollmentId, (int)$term, $datePart, $timePart, $statusFull]);
        }
    }

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Attendance saved successfully"
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
