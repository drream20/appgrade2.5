<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$db = getDb();
$instructorId = currentInstructorId();

$activeAY = getActiveAcademicYear($db);
$academicYearId = $activeAY ? (int)$activeAY['id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

if ($academicYearId <= 0) {
    echo json_encode([
        'ok' => false,
        'error' => 'No active semester',
        'rows' => [],
    ]);
    exit;
}

$where = [
    "ar.status = 'absent'",
    "isub.instructor_id = :instructorId",
    "cs.academic_year_id = :ayId",
];
$params = [
    ':instructorId' => $instructorId,
    ':ayId' => $academicYearId,
];

if ($sectionId > 0) {
    $where[] = 'cs.id = :sectionId';
    $params[':sectionId'] = $sectionId;
}

// Week start is Monday (consistent with WEEKDAY()).
$sql = "
    SELECT
      DATE_SUB(ar.attendance_date, INTERVAL WEEKDAY(ar.attendance_date) DAY) AS week_start,
      COUNT(*) AS absences
    FROM attendance_records ar
    INNER JOIN enrollments e ON e.id = ar.enrollment_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    INNER JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    WHERE " . implode(' AND ', $where) . "
    GROUP BY week_start
    ORDER BY week_start ASC
";

try {
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'week_start' => $r['week_start'],
            'absences' => (int)($r['absences'] ?? 0),
        ];
    }

    echo json_encode([
        'ok' => true,
        'rows' => $out,
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => 'Query failed',
        'rows' => [],
    ]);
}
