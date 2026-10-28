<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
deanRequireLogin();
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
    http_response_code(500);
    exit;
}

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$db = getDb();
$classSectionId = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;
$deanSignatoryName = isset($_GET['dean_name']) ? trim((string)$_GET['dean_name']) : '';
$executiveDeanName = isset($_GET['executive_dean']) ? trim((string)$_GET['executive_dean']) : 'DR. LYLANI S. CLARO';
$vpAcademicAffairsName = isset($_GET['vp_academic_affairs']) ? trim((string)$_GET['vp_academic_affairs']) : 'DR. RAQUEL T. STA. INES.';
if ($executiveDeanName === '') {
    $executiveDeanName = 'DR. LYLANI S. CLARO';
}
if ($vpAcademicAffairsName === '') {
    $vpAcademicAffairsName = 'DR. RAQUEL T. STA. INES.';
}
if ($classSectionId <= 0) {
    header('Location: class_records.php');
    exit;
}

$programDepartment = deanSelectedProgramDepartment();
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

// -- Add subject_description and subject units to SELECT so it's available --
$sql = "
    SELECT
        cs.id AS class_section_id,
        cs.year_level,
        cs.section,
        cs.department AS class_department,
        cs.instructor_subject_id,
        cs.academic_year_id,
        s.id AS subject_id,
        s.code AS subject_code,
        s.title AS subject_title,
        s.description AS subject_description,
        s.department AS subject_department,
        s.units AS subject_units,
        i.full_name AS instructor_name,
        i.department AS instructor_department,
        ay.school_year,
        ay.semester
    FROM class_sections cs
    INNER JOIN subjects s ON s.id = cs.subject_id
    LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
    LEFT JOIN instructors i ON i.id = isub.instructor_id
    LEFT JOIN academic_years ay ON ay.id = cs.academic_year_id
    WHERE cs.id = :classSectionId
";
$params = [':classSectionId' => $classSectionId];
if ($programDepartment) {
    $expr = [];
    if (!empty($programDeptFilters)) {
        $csPh = [];
        $subPh = [];
        foreach ($programDeptFilters as $idx => $dept) {
            $k1 = ':deptcs' . $idx;
            $k2 = ':deptsub' . $idx;
            $csPh[] = $k1;
            $subPh[] = $k2;
            $params[$k1] = $dept;
            $params[$k2] = $dept;
        }
        $expr[] = "cs.department IN (" . implode(',', $csPh) . ")";
        $expr[] = "s.department IN (" . implode(',', $subPh) . ")";
    }
    if (!empty($programCourses)) {
        $like = [];
        foreach ($programCourses as $idx => $course) {
            $k = ':codelike' . $idx;
            $like[] = "s.code LIKE " . $k;
            $params[$k] = $course . '%';
        }
        $expr[] = '(' . implode(' OR ', $like) . ')';
    }
    $sql .= !empty($expr) ? (" AND (" . implode(' OR ', $expr) . ")") : " AND 0 = 1";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$classInfo) {
    header('Location: class_records.php');
    exit;
}

// Get the subject units and prepare for E5
$subjectUnits = '';
if (isset($classInfo['subject_units'])) {
    $subjectUnits = trim((string)$classInfo['subject_units']);
}

$scheduleSql = "
    SELECT day_of_week, start_time, end_time
    FROM schedule
    WHERE instructor_subject_id = ?
";
$scheduleParams = [(int)($classInfo['instructor_subject_id'] ?? 0)];
if (!empty($classInfo['section'])) {
    $scheduleSql .= " AND section_name = ?";
    $scheduleParams[] = (string)$classInfo['section'];
}
$scheduleSql .= " ORDER BY day_of_week, start_time";
$stmtSchedule = $db->prepare($scheduleSql);
$stmtSchedule->execute($scheduleParams);
$scheduleRows = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);
// Show only one unique schedule (no duplicates)
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$scheduleTexts = [];
$scheduleSeen = []; // for uniqueness
foreach ($scheduleRows as $slot) {
    $day = (int)($slot['day_of_week'] ?? 0);
    $dayLabel = $dayNames[$day] ?? ('Day ' . $day);
    $startRaw = (string)($slot['start_time'] ?? '');
    $endRaw = (string)($slot['end_time'] ?? '');
    $startLabel = $startRaw !== '' ? date('g:i A', strtotime($startRaw)) : '';
    $endLabel = $endRaw !== '' ? date('g:i A', strtotime($endRaw)) : '';
    if ($startLabel !== '' || $endLabel !== '') {
        $schedStr = trim($dayLabel . ' ' . $startLabel . ' - ' . $endLabel);
        if (!isset($scheduleSeen[$schedStr])) {
            $scheduleTexts[] = $schedStr;
            $scheduleSeen[$schedStr] = true;
        }
    }
}
// Only one schedule must show
$scheduleSummary = !empty($scheduleTexts) ? $scheduleTexts[0] : 'No schedule set';

// --- Fetch enrolled students AND their departments ----
$stmt = $db->prepare("
    SELECT
        st.student_id,
        st.full_name,
        st.department AS student_department,
        tg1.term_grade_computed AS prelim,
        tg2.term_grade_computed AS midterm,
        tg3.term_grade_computed AS final
    FROM enrollments e
    INNER JOIN students st ON st.id = e.student_id
    LEFT JOIN term_grades tg1 ON tg1.enrollment_id = e.id AND tg1.term_id = 1
    LEFT JOIN term_grades tg2 ON tg2.enrollment_id = e.id AND tg2.term_id = 2
    LEFT JOIN term_grades tg3 ON tg3.enrollment_id = e.id AND tg3.term_id = 3
    WHERE e.class_section_id = ?
    ORDER BY st.full_name, st.student_id
");
$stmt->execute([$classSectionId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count the number of students enrolled in this class section
$studentCount = count($rows);

// Get unique departments of enrolled students
$studentDepartments = [];
foreach ($rows as $row) {
    $dep = trim((string)($row['student_department'] ?? ''));
    if ($dep !== '' && !in_array($dep, $studentDepartments, true)) {
        $studentDepartments[] = $dep;
    }
}

if (empty($studentDepartments)) {
    $programString = 'Program: ';
} else {
    $programString = 'Program: ' . implode(', ', $studentDepartments);
}

$templatePath = __DIR__ . '/templates/grade_report.xlsx';
if (!file_exists($templatePath)) {
    http_response_code(500);
    exit;
}
$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);

$normalize = static function ($value): string {
    $v = strtolower(trim((string)$value));
    $v = preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim($v);
};

$highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
$headerRow = null;
$headerCols = [
    'student_id' => null,
    'student_name' => null,
    'prelim' => null,
    'midterm' => null,
    'final' => null,
    'overall' => null,
];

for ($r = 1; $r <= 60; $r++) {
    $rowCols = $headerCols;
    for ($c = 1; $c <= $highestCol; $c++) {
        $coord = Coordinate::stringFromColumnIndex($c) . $r;
        $text = $normalize($sheet->getCell($coord)->getFormattedValue());
        if ($text === '') continue;
        if ($rowCols['student_id'] === null && (strpos($text, 'student id') !== false || $text === 'id')) {
            $rowCols['student_id'] = $c;
        }
        if ($rowCols['student_name'] === null && (strpos($text, 'student name') !== false || strpos($text, 'name') !== false)) {
            $rowCols['student_name'] = $c;
        }
        if ($rowCols['prelim'] === null && strpos($text, 'prelim') !== false) {
            $rowCols['prelim'] = $c;
        }
        if ($rowCols['midterm'] === null && strpos($text, 'midterm') !== false) {
            $rowCols['midterm'] = $c;
        }
        if ($rowCols['final'] === null && strpos($text, 'final') !== false) {
            $rowCols['final'] = $c;
        }
        if ($rowCols['overall'] === null && strpos($text, 'overall') !== false) {
            $rowCols['overall'] = $c;
        }
    }
    if ($rowCols['student_name'] !== null && ($rowCols['prelim'] !== null || $rowCols['midterm'] !== null || $rowCols['final'] !== null)) {
        $headerRow = $r;
        $headerCols = $rowCols;
        break;
    }
}

if ($headerRow === null) {
    $headerRow = 9;
    $headerCols = [
        'student_id' => 1,
        'student_name' => 2,
        'prelim' => 3,
        'midterm' => 4,
        'final' => 5,
        'overall' => 6,
    ];
}

// Fill likely metadata cells without depending on strict placeholders, including subject description if available.
// Set the "program" in E6 as department(s) of students enrolled in the subject.
// Place subject description in cell E4 as required.
// NOW GET THE UNITS OF THE SUBJECT CODE THEN PUT IT IN THE CELL E5
$metaMap = [
    'E3' => (string)($classInfo['subject_code'] ?? ''),
    'E4' => (string)($classInfo['subject_description'] ?? ''), // NOW GET THE SUBJECT DESCRIPTION PUT IN CELL E4
    'E5' => $subjectUnits, // UNITS GO IN CELL E5
    'E2' => (string)($classInfo['instructor_name'] ?? ''),
    'K4' => $scheduleSummary,
    'K3' => (string)($classInfo['school_year'] ?? ''),
    'K2' => (string)($classInfo['semester'] ?? ''),
    'K6' => (string)($classInfo['year_level'] ?? ''),
    'K6' => (string)($classInfo['section'] ?? ''),
    'E6' => $programString, // NOW SET AS department(s) of students enrolled
];
// --- ADD: Place the student count in cell K5 ---
try {
    $sheet->setCellValue('K5', $studentCount);
} catch (Throwable $e) {
    // Ignore errors when setting the student count
}
foreach ($metaMap as $cell => $value) {
    try {
        if (trim((string)$sheet->getCell($cell)->getValue()) === '') {
            $sheet->setCellValue($cell, $value);
        }
    } catch (Throwable $e) {
    }
}

// Put instructor name (from E2) also into F63 automatically
try {
    // Try to set F63 to whatever is in E2
    $instructorName = (string)$sheet->getCell('E2')->getValue();
    // If E2 is empty, fallback to DB value
    if (trim($instructorName) === '') {
        $instructorName = (string)($classInfo['instructor_name'] ?? '');
    }
    $sheet->setCellValue('F63', $instructorName);
} catch (Throwable $e) {
    // Ignore any possible error here
}

try {
    $deanName = $deanSignatoryName;
    if ($deanName === '') {
        if (isset($_SESSION['dean_name']) && trim($_SESSION['dean_name']) !== '') {
            $deanName = trim($_SESSION['dean_name']);
        } else {
            $deanId = isset($_SESSION['dean_id']) ? intval($_SESSION['dean_id']) : 0;
            if ($deanId > 0) {
                $stmtDean = $db->prepare("SELECT full_name FROM deans WHERE id = ?");
                $stmtDean->execute([$deanId]);
                $deanRow = $stmtDean->fetch(PDO::FETCH_ASSOC);
                if ($deanRow && isset($deanRow['full_name'])) {
                    $deanName = trim($deanRow['full_name']);
                }
            }
        }
    }
    if ($deanName === '') {
        $deanName = 'Dean';
    }
    $sheet->setCellValue('K63', $deanName);
} catch (Throwable $e) {}

try {
    $sheet->setCellValue('F69', $executiveDeanName);
    $sheet->setCellValue('K69', $vpAcademicAffairsName);
} catch (Throwable $e) {
    // Ignore any possible error here
}

$dataStartRow = $headerRow + 1;
foreach ($rows as $index => $row) {
    $r = $dataStartRow + $index;
    if ($headerCols['student_id']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['student_id']) . $r;
        $sheet->setCellValue($coord, (string)($row['student_id'] ?? ''));
    }
    if ($headerCols['student_name']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['student_name']) . $r;
        $sheet->setCellValue($coord, (string)($row['full_name'] ?? ''));
    }

    $p = is_numeric($row['prelim'] ?? null) ? (float)$row['prelim'] : null;
    $m = is_numeric($row['midterm'] ?? null) ? (float)$row['midterm'] : null;
    $f = is_numeric($row['final'] ?? null) ? (float)$row['final'] : null;
    $overall = ($p !== null && $m !== null && $f !== null) ? computeFinalGrade($p, $m, $f) : null;

    if ($headerCols['prelim']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['prelim']) . $r;
        $sheet->setCellValue($coord, $p !== null ? round($p, 2) : '');
    }
    if ($headerCols['midterm']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['midterm']) . $r;
        $sheet->setCellValue($coord, $m !== null ? round($m, 2) : '');
    }
    if ($headerCols['final']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['final']) . $r;
        $sheet->setCellValue($coord, $f !== null ? round($f, 2) : '');
    }
    if ($headerCols['overall']) {
        $coord = Coordinate::stringFromColumnIndex((int)$headerCols['overall']) . $r;
        $sheet->setCellValue($coord, $overall !== null ? round($overall, 2) : '');
    }
}

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

$safeSubject = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($classInfo['subject_code'] ?? 'Subject'));
$safeSection = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($classInfo['section'] ?? 'Section'));
$filename = 'Dean_GradeReport_' . $safeSubject . '_' . $safeSection . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;

