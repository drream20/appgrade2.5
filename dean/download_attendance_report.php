<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
deanRequireLogin();

$db = getDb();

function validDateYmd(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

// --- Inputs ---
$classSectionId = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
$attendanceDate = isset($_GET['attendance_date']) ? (string)$_GET['attendance_date'] : '';

if ($classSectionId <= 0 || $termId <= 0 || !validDateYmd($attendanceDate)) {
    header('Location: sections.php');
    exit;
}

$programDepartment = deanSelectedProgramDepartment();
$activeAY = getActiveAcademicYear($db);
$activeAyId = $activeAY ? (int)$activeAY['id'] : null;
if (!$activeAyId) {
    header('Location: sections.php');
    exit;
}

// --- Fetch class/instructor/AY info with dean scoping ---
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

$sql = "
    SELECT
        cs.id AS class_section_id,
        cs.year_level,
        cs.section,
        cs.department AS class_department,
        cs.academic_year_id,
        s.code AS subject_code,
        s.title AS subject_title,
        i.full_name AS instructor_name,
        ay.school_year,
        ay.semester
    FROM class_sections cs
    INNER JOIN subjects s ON s.id = cs.subject_id
    LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
    LEFT JOIN instructors i ON i.id = isub.instructor_id
    LEFT JOIN academic_years ay ON ay.id = cs.academic_year_id
    WHERE cs.id = :classSectionId
      AND cs.academic_year_id = :ayId
";
$params = [
    ':classSectionId' => $classSectionId,
    ':ayId' => $activeAyId,
];

if ($programDepartment) {
    $deptExpr = [];
    if (!empty($programDeptFilters)) {
        $placeholders = [];
        foreach ($programDeptFilters as $idx => $deptVal) {
            $k = ':dept_' . $idx;
            $placeholders[] = $k;
            $params[$k] = $deptVal;
        }
        $deptExpr[] = "cs.department IN (" . implode(',', $placeholders) . ")";
        $deptExpr[] = "s.department IN (" . implode(',', $placeholders) . ")";
    }
    if (!empty($programCourses)) {
        $likeExpr = [];
        foreach ($programCourses as $idx => $courseCode) {
            $k = ':code_' . $idx;
            $likeExpr[] = "s.code LIKE $k";
            $params[$k] = $courseCode . '%';
        }
        $deptExpr[] = '(' . implode(' OR ', $likeExpr) . ')';
    }

    if (!empty($deptExpr)) {
        $sql .= " AND (" . implode(' OR ', $deptExpr) . ")";
    } else {
        $sql .= " AND 0 = 1";
    }
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$info) {
    header('Location: sections.php');
    exit;
}

$termName = '';
try {
    $stmtT = $db->prepare("SELECT name FROM terms WHERE id = ? LIMIT 1");
    $stmtT->execute([$termId]);
    $termName = (string)($stmtT->fetch(PDO::FETCH_ASSOC)['name'] ?? '');
} catch (Throwable $e) {
    $termName = (string)$termId;
}

// --- Students enrolled in this class ---
$students = [];
$enrollmentIds = [];
try {
    $stmtS = $db->prepare("
        SELECT
            e.id AS enrollment_id,
            st.student_id,
            st.full_name
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        WHERE e.class_section_id = ?
          AND e.status = 'enrolled'
        ORDER BY st.full_name, st.student_id
    ");
    $stmtS->execute([(int)$classSectionId]);
    $students = $stmtS->fetchAll(PDO::FETCH_ASSOC);
    $enrollmentIds = array_map(static fn($s) => (int)$s['enrollment_id'], $students);
} catch (Throwable $e) {
    $students = [];
    $enrollmentIds = [];
}

// --- Attendance records for that date + term ---
$attendanceByEnrollment = [];
if (!empty($enrollmentIds)) {
    $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
    $params = array_merge($enrollmentIds, [(int)$termId, $attendanceDate]);

    $stmtA = $db->prepare("
        SELECT enrollment_id, attendance_time, status, remarks
        FROM attendance_records
        WHERE enrollment_id IN ($placeholders)
          AND term_id = ?
          AND attendance_date = ?
    ");
    $stmtA->execute($params);
    while ($r = $stmtA->fetch(PDO::FETCH_ASSOC)) {
        $eid = (int)$r['enrollment_id'];
        $existing = $attendanceByEnrollment[$eid] ?? null;

        // If multiple slots exist the same day, keep the latest time.
        $newTime = (string)($r['attendance_time'] ?? '');
        $oldTime = (string)($existing['attendance_time'] ?? '');
        if ($existing === null || ($newTime !== '' && ($oldTime === '' || strcmp($newTime, $oldTime) > 0))) {
            $attendanceByEnrollment[$eid] = [
                'attendance_time' => $newTime,
                'status' => (string)($r['status'] ?? ''),
                'remarks' => (string)($r['remarks'] ?? ''),
            ];
        }
    }
}

// --- CSV output (Excel-friendly for specific cell positions) ---
$fileName = sprintf(
    'Dean_Attendance_%s_Term_%s_%s.csv',
    preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($info['subject_code'] ?? '')),
    preg_replace('/[^A-Za-z0-9._-]/', '_', $termName !== '' ? $termName : (string)$termId),
    $attendanceDate
);

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel compatibility
fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

// A1, A2, A3 for "SECTION", "INSTRUCTOR", "ACADEMIC YEAR"
fputcsv($out, ["SECTION", "", ""]);
fputcsv($out, [isset($info['section']) ? (string)$info['section'] : "", "", ""]);
fputcsv($out, ["INSTRUCTOR", "", ""]);
fputcsv($out, [isset($info['instructor_name']) ? (string)$info['instructor_name'] : "", "", ""]);
fputcsv($out, ["ACADEMIC YEAR", "", ""]);
fputcsv($out, [isset($info['school_year']) ? (string)$info['school_year'] : "", "", ""]);

// Add two blank rows to reach row 6 (A6/B6)
fputcsv($out, ["", "", ""]);
fputcsv($out, ["", "", ""]);

// Row 6: A6 header for students, B6 header for status
fputcsv($out, ["STUDENTS", "STATUS"]);

// Start from row 7: Student name in A, status in B
foreach ($students as $s) {
    $eid = (int)$s['enrollment_id'];
    $att = $attendanceByEnrollment[$eid] ?? null;
    fputcsv($out, [
        (string)($s['full_name'] ?? ''),
        $att ? (string)$att['status'] : ''
    ]);
}

fclose($out);
exit;
