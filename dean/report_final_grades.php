<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
deanRequireLogin();

$db = getDb();

// Active academic year (optional filter)
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
    $where[] = 'cs.academic_year_id = :ayId';
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
      s.code AS subject_code,
      s.title AS subject_title,
      st.student_id,
      st.full_name,
      cs.year_level,
      cs.section,
      tg1.term_grade_computed AS prelim,
      tg2.term_grade_computed AS midterm,
      tg3.term_grade_computed AS final
    FROM enrollments e
    JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON s.id = isub.subject_id
    LEFT JOIN term_grades tg1 ON tg1.enrollment_id = e.id AND tg1.term_id = 1
    LEFT JOIN term_grades tg2 ON tg2.enrollment_id = e.id AND tg2.term_id = 2
    LEFT JOIN term_grades tg3 ON tg3.enrollment_id = e.id AND tg3.term_id = 3
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.code, cs.year_level, cs.section, st.full_name
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dean_final_grades.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Subject Code',
    'Subject Title',
    'Year Level',
    'Section',
    'Student ID',
    'Student Name',
    'Prelim',
    'Midterm',
    'Final',
    'Overall (30/30/40)',
]);

foreach ($rows as $r) {
    $p = is_numeric($r['prelim'] ?? null) ? (float) $r['prelim'] : null;
    $m = is_numeric($r['midterm'] ?? null) ? (float) $r['midterm'] : null;
    $f = is_numeric($r['final'] ?? null) ? (float) $r['final'] : null;
    $overall = ($p !== null && $m !== null && $f !== null) ? computeFinalGrade($p, $m, $f) : null;

    fputcsv($out, [
        $r['subject_code'],
        $r['subject_title'],
        $r['year_level'],
        $r['section'],
        $r['student_id'],
        $r['full_name'],
        $p !== null ? number_format($p, 2) : '',
        $m !== null ? number_format($m, 2) : '',
        $f !== null ? number_format($f, 2) : '',
        $overall !== null ? number_format($overall, 2) : '',
    ]);
}

fclose($out);
exit;

