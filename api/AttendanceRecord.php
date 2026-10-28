<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
include_once __DIR__ . '/../config/database.php';
$conn = getDb(); 
$section = $_GET['section'] ?? null;
$subID = $_GET['subject_ID'] ?? null;
$term = $_GET['term'] ?? ($_GET['termid'] ?? null);
$datetime = $_GET['datetime'] ?? null; // For fetching specific datetime details
$attendanceId = $_GET['attendance_id'] ?? ($_GET['id'] ?? null);

if (!$subID || !$term) {
    echo json_encode([
        "status" => "error",
        "data" => [],
        "message" => "Missing parameters"
    ]);
    exit;
}

// helper to map status values to short codes
if (!function_exists('mapStatusShort')) {
    function mapStatusShort(string $status): string {
        $s = strtolower($status);
        return $s === 'present' ? 'P' : ($s === 'absent' ? 'A' : ($s === 'late' ? 'L' : ($s === 'excused' ? 'E' : $status)));
    }
}

try {
    // first, find enrollments for this subject/section to limit scope
    $enrollStmt = $conn->prepare("SELECT e.id AS enrollment_id, s.student_id AS student_number
        FROM enrollments e
        JOIN students s ON s.id = e.student_id
        WHERE e.instructor_subject_id IN (SELECT id FROM instructor_subjects WHERE subject_id = :sub)
          OR e.class_section_id IN (SELECT id FROM class_sections WHERE subject_id = :sub AND section = :section)");
    $enrollStmt->execute([':sub' => $subID, ':section' => $section]);
    $enrollRows = $enrollStmt->fetchAll(PDO::FETCH_ASSOC);

    $enrollmentIds = [];
    $studentToEnrollment = [];
    foreach ($enrollRows as $er) {
        $enrollmentIds[] = (int)$er['enrollment_id'];
        $studentToEnrollment[$er['student_number']] = (int)$er['enrollment_id'];
    }

    if ($attendanceId !== null) {
        // Resolve date/time by attendance_id
        $stmt = $conn->prepare("SELECT attendance_date, attendance_time FROM attendance_records WHERE id = ?");
        $stmt->execute([(int)$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(["status"=>"success","data"=>[]]);
            exit;
        }
        $datePart = date('Y-m-d', strtotime($row['attendance_date']));
        $timePart = $row['attendance_time'] ?? '00:00:00';
    } elseif ($datetime !== null) {
        // fetch individual student records for the datetime
        $dt = strtotime($datetime);
        $datePart = date('Y-m-d', $dt);
        $timePart = date('H:i:s', $dt);
    }

    if ($attendanceId !== null || $datetime !== null) {
        if (empty($enrollmentIds)) {
            echo json_encode(["status"=>"success","data"=>[]]);
            exit;
        }

        $inParams = [];
        $inPlaceholders = [];
        foreach ($enrollmentIds as $i => $id) {
            $key = ":en{$i}";
            $inPlaceholders[] = $key;
            $inParams[$key] = (int)$id;
        }

        $stmt = $conn->prepare("SELECT ar.id AS attendance_id,e.id AS enrollment_id, s.student_id AS studentNumber, ar.status
            FROM attendance_records ar
            JOIN enrollments e ON e.id = ar.enrollment_id
            JOIN students s ON s.id = e.student_id
            WHERE ar.enrollment_id IN (" . implode(',', $inPlaceholders) . ")
              AND ar.term_id = :term
              AND DATE(ar.attendance_date) = :date
              AND (ar.attendance_time = :time OR (:time2 = '00:00:00' AND ar.attendance_time IS NULL))
            ORDER BY s.student_id");

        $params = $inParams + [
            ':term' => (int)$term,
            ':date' => $datePart,
            ':time' => $timePart,
            ':time2' => $timePart,
        ];

        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $attendanceMap = [];
        foreach ($rows as $row) {
            $attendanceMap[$row['studentNumber']] = mapStatusShort($row['status']);
        }

        echo json_encode(["status"=>"success","data"=>$attendanceMap]);
        exit;
    }

    // otherwise, list sessions grouped by date/time
    if (empty($enrollmentIds)) {
        echo json_encode(["status"=>"success","data"=>[]]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
    $params = array_merge($enrollmentIds, [(int)$term]);

    $sql = "SELECT MIN(ar.id) AS attendance_id, ar.attendance_date AS date, ar.attendance_time AS time, CONCAT(ar.attendance_date, ' ', COALESCE(ar.attendance_time,'00:00:00')) AS fullDateTime, ar.status, COUNT(*) AS cnt
        FROM attendance_records ar
        WHERE ar.enrollment_id IN ($placeholders)
          AND ar.term_id = ?
        GROUP BY ar.attendance_date, ar.attendance_time, ar.status
        ORDER BY fullDateTime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sessions = [];
    foreach ($rows as $r) {
        $date = date('Y-m-d', strtotime($r['date']));
        $time = $r['time'];
        $full = $r['fullDateTime'];
        if (!isset($sessions[$full])) {
            $sessions[$full] = [
                'attendance_id' => $r['attendance_id'],
                'date' => $date,
                'time' => $time,
                'datetime' => $full,
                'P' => 0,'A'=>0,'L'=>0,'E'=>0
            ];
        }
        $short = mapStatusShort($r['status']);
        if (!isset($sessions[$full][$short])) $sessions[$full][$short] = 0;
        $sessions[$full][$short] = (int)$r['cnt'];
    }

    // sort by datetime descending
    uksort($sessions, function($a,$b){ return strtotime($b) - strtotime($a); });

    echo json_encode(["status"=>"success","data"=>array_values($sessions)]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "data" => [],
        "message" => $e->getMessage()
    ]);
}
