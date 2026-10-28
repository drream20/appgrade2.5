<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();

$db = getDb();

// Active academic year
$activeAyId = null;
try {
    $stmt = $db->query("SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activeAyId = (int) $row['id'];
    }
} catch (Throwable $e) {
    $activeAyId = null;
}

$where = ['1=1'];
$params = [];
if ($activeAyId) {
    $where[] = 'isub.academic_year_id = :ayId';
    $params[':ayId'] = $activeAyId;
}
// Program context
$programDepartment = deanSelectedProgramDepartment();
if ($programDepartment) {
    $where[] = 'cs.department = :dept';
    $params[':dept'] = $programDepartment;
}

$sql = "
    SELECT
      i.full_name,
      i.email,
      s.code AS subject_code,
      s.title AS subject_title,
      cs.year_level,
      cs.section,
      COUNT(DISTINCT e.id) AS enrolled,
      COUNT(DISTINCT CASE WHEN tg.enrollment_id IS NOT NULL THEN tg.enrollment_id END) AS graded_enrollments
    FROM instructors i
    JOIN instructor_subjects isub ON isub.instructor_id = i.id
    JOIN subjects s ON s.id = isub.subject_id
    LEFT JOIN class_sections cs ON cs.instructor_subject_id = isub.id
    LEFT JOIN enrollments e
      ON e.instructor_subject_id = isub.id
      OR e.class_section_id = cs.id
    LEFT JOIN term_grades tg ON tg.enrollment_id = e.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY
      i.full_name, i.email,
      s.code, s.title,
      cs.year_level, cs.section
    ORDER BY i.full_name, s.code, cs.year_level, cs.section
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dean_submission_status.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Instructor Name',
    'Instructor Email',
    'Subject Code',
    'Subject Title',
    'Year Level',
    'Section',
    'Enrolled Students',
    'Graded Enrollments',
    'Status',
]);

foreach ($rows as $r) {
    $enrolled = (int) ($r['enrolled'] ?? 0);
    $graded = (int) ($r['graded_enrollments'] ?? 0);
    if ($enrolled === 0) {
        $status = 'No Enrollments';
    } elseif ($graded > 0) {
        $status = 'Submitted';
    } else {
        $status = 'Not Yet Submitted';
    }

    fputcsv($out, [
        $r['full_name'],
        $r['email'],
        $r['subject_code'],
        $r['subject_title'],
        $r['year_level'],
        $r['section'],
        $enrolled,
        $graded,
        $status,
    ]);
}

fclose($out);
exit;

