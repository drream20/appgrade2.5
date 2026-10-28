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

// Terms map
$terms = [];
try {
    $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $terms[(int)$r['id']] = $r['name'];
    }
} catch (Throwable $e) {}

$where = ["ar.status = 'absent'"];
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
      st.student_id,
      st.full_name,
      st.email,
      cs.year_level,
      cs.section,
      s.code AS subject_code,
      s.title AS subject_title,
      ar.term_id,
      COUNT(*) AS absences
    FROM attendance_records ar
    JOIN enrollments e ON e.id = ar.enrollment_id
    JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON s.id = isub.subject_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY
      st.student_id, st.full_name, st.email,
      cs.year_level, cs.section,
      s.code, s.title,
      ar.term_id
    HAVING COUNT(*) >= 3
    ORDER BY cs.year_level, cs.section, st.full_name, s.code
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dean_absences_3_plus.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Student ID',
    'Student Name',
    'Email',
    'Year Level',
    'Section',
    'Subject Code',
    'Subject Title',
    'Term',
    'Absences',
]);

foreach ($rows as $r) {
    $tid = (int) ($r['term_id'] ?? 0);
    $termName = $terms[$tid] ?? '';
    fputcsv($out, [
        $r['student_id'],
        $r['full_name'],
        $r['email'],
        $r['year_level'],
        $r['section'],
        $r['subject_code'],
        $r['subject_title'],
        $termName,
        $r['absences'],
    ]);
}

fclose($out);
exit;

