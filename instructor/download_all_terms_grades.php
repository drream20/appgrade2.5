<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require __DIR__ . '/../includes/active_academic_year.php';
require_once __DIR__ . '/includes/subject_code_lab.php';

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
    die('Spreadsheet export library is not available.');
}

use PhpOffice\PhpSpreadsheet\IOFactory;

requireLogin();

$db = getDb();
$instructorId = currentInstructorId();
$activeAY = getActiveAcademicYear($db);

$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : 0;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;

// Signatory names (editable from instructor modal)
$defaultExecutiveDean = 'DR. LYLANI S. CLARO';
$defaultVpAcademicAffairs = 'DR. RAQUEL T. STA. INES.';
$defaultProgramChairperson = 'MR. JOE MARIEL MEJIA';

$executiveDeanName = isset($_GET['executive_dean']) ? trim((string)$_GET['executive_dean']) : '';
$vpAcademicAffairsName = isset($_GET['vp_academic_affairs']) ? trim((string)$_GET['vp_academic_affairs']) : '';
$programChairpersonName = isset($_GET['program_chairperson']) ? trim((string)$_GET['program_chairperson']) : '';

if ($executiveDeanName === '') $executiveDeanName = $defaultExecutiveDean;
if ($vpAcademicAffairsName === '') $vpAcademicAffairsName = $defaultVpAcademicAffairs;
if ($programChairpersonName === '') $programChairpersonName = $defaultProgramChairperson;

if ($instructorSubjectId <= 0) {
    header('Location: records.php');
    exit;
}

$stmt = $db->prepare("
    SELECT isub.id, isub.subject_id, isub.academic_year_id, isub.school_year, isub.semester, s.code
    FROM instructor_subjects isub
    INNER JOIN subjects s ON s.id = isub.subject_id
    WHERE isub.id = ? AND isub.instructor_id = ?
    LIMIT 1
");
$stmt->execute([$instructorSubjectId, $instructorId]);
$instructorSubjectRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$instructorSubjectRow || instructor_subject_code_is_lab_suffix($instructorSubjectRow['code'] ?? '')) {
    header('Location: records.php');
    exit;
}
if (!$activeAY) {
    header('Location: records.php');
    exit;
}
$isActiveTerm =
    ((int)($instructorSubjectRow['academic_year_id'] ?? 0) === (int)$activeAY['id']) ||
    (empty($instructorSubjectRow['academic_year_id']) && ($instructorSubjectRow['school_year'] ?? null) === ($activeAY['school_year'] ?? null) && ($instructorSubjectRow['semester'] ?? null) === ($activeAY['semester'] ?? null));
if (!$isActiveTerm) {
    header('Location: records.php');
    exit;
}

$termsOrdered = [];
$stmtTerms = $db->query("SELECT id, name FROM terms ORDER BY sort_order, id");
while ($row = $stmtTerms->fetch(PDO::FETCH_ASSOC)) {
    $termsOrdered[(int)$row['id']] = (string)$row['name'];
}
$termIds = array_keys($termsOrdered);
if (empty($termIds)) {
    header('Location: records.php');
    exit;
}

if ($sectionId > 0) {
    $stmtInfo = $db->prepare("
        SELECT s.code, s.title, s.description AS subject_description, s.units,
               i.full_name AS instructor_name, ay.school_year, ay.semester,
               cs.section, cs.year_level, cs.department
        FROM instructor_subjects isub
        INNER JOIN subjects s ON s.id = isub.subject_id
        INNER JOIN instructors i ON i.id = isub.instructor_id
        LEFT JOIN academic_years ay ON ay.id = isub.academic_year_id
        INNER JOIN class_sections cs ON cs.id = ? AND cs.instructor_subject_id = isub.id
        WHERE isub.id = ?
        LIMIT 1
    ");
    $stmtInfo->execute([$sectionId, $instructorSubjectId]);
} else {
    $stmtInfo = $db->prepare("
        SELECT s.code, s.title, s.description AS subject_description, s.units,
               i.full_name AS instructor_name, ay.school_year, ay.semester,
               cs.section, cs.year_level, cs.department
        FROM instructor_subjects isub
        INNER JOIN subjects s ON s.id = isub.subject_id
        INNER JOIN instructors i ON i.id = isub.instructor_id
        LEFT JOIN academic_years ay ON ay.id = isub.academic_year_id
        LEFT JOIN class_sections cs ON cs.instructor_subject_id = isub.id
        WHERE isub.id = ?
        LIMIT 1
    ");
    $stmtInfo->execute([$instructorSubjectId]);
}
$info = $stmtInfo->fetch(PDO::FETCH_ASSOC) ?: [];

$sectionLabel = (string)($info['section'] ?? '');
$scheduleParams = [$instructorSubjectId];
$scheduleSql = "
    SELECT day_of_week, start_time, end_time
    FROM schedule
    WHERE instructor_subject_id = ?
";
if ($sectionId > 0 && $sectionLabel !== '') {
    $scheduleSql .= " AND section_name = ?";
    $scheduleParams[] = $sectionLabel;
}
$scheduleSql .= " ORDER BY day_of_week, start_time";
$stmtSchedule = $db->prepare($scheduleSql);
$stmtSchedule->execute($scheduleParams);
$scheduleRows = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);

// Prepare day abbreviations: 1 => 'M', 2 => 'T', 3 => 'W', 4 => 'TH', 5 => 'F', 6 => 'S', 7 => 'SU'
$dayAbbr = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'TH', 5 => 'F', 6 => 'S', 7 => 'SU'];

// Deduplicate and format schedule times
$scheduleUnique = [];
foreach ($scheduleRows as $slot) {
    $day = (int)($slot['day_of_week'] ?? 0);
    $abbr = $dayAbbr[$day] ?? ('Day' . $day); // fallback to "DayX"
    $startRaw = (string)($slot['start_time'] ?? '');
    $endRaw = (string)($slot['end_time'] ?? '');
    if ($startRaw === '' && $endRaw === '') continue;

    // Compose the time interval string in 24-hour format, then deduplicate per day and time
    $interval = '';
    if ($startRaw !== '' && $endRaw !== '') {
        // Output e.g. '8:00 - 10:00'
        $interval = date('G:i', strtotime($startRaw)) . ' - ' . date('G:i', strtotime($endRaw));
    } elseif ($startRaw !== '') {
        $interval = date('G:i', strtotime($startRaw));
    } elseif ($endRaw !== '') {
        $interval = date('G:i', strtotime($endRaw));
    }
    if ($interval === '') continue;

    // Use (abbr, interval) pair as key for deduplication
    $key = $abbr . '|' . $interval;
    $scheduleUnique[$key] = [$abbr, $interval];
}

// Now build the output array: S, 8:00 - 10:00
$scheduleTexts = [];
foreach ($scheduleUnique as [$abbr, $interval]) {
    $scheduleTexts[] = $abbr . ', ' . $interval;
}
$scheduleSummary = !empty($scheduleTexts) ? implode(', ', $scheduleTexts) : '';

$semester = (string)($info['semester'] ?? '');
$normalizedSemester = $semester;
if ($normalizedSemester === '1st') {
    $normalizedSemester = '1ST';
} elseif ($normalizedSemester === '2nd') {
    $normalizedSemester = '2ND';
}

$selectParts = [
    'e.id AS enrollment_id',
    'st.student_id',
    'st.full_name',
];
foreach ($termIds as $tid) {
    $tid = (int)$tid;
    $selectParts[] = "MAX(CASE WHEN tg.term_id = $tid THEN tg.term_grade_computed END) AS term_$tid";
}

if ($sectionId > 0) {
    $where = 'e.class_section_id = ?';
    $params = [$sectionId];
} else {
    $where = '(e.instructor_subject_id = ? OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?))';
    $params = [$instructorSubjectId, $instructorSubjectId];
}

$sql = "
    SELECT " . implode(', ', $selectParts) . "
    FROM enrollments e
    INNER JOIN students st ON st.id = e.student_id
    LEFT JOIN term_grades tg ON tg.enrollment_id = e.id
    WHERE $where
    GROUP BY e.id, st.student_id, st.full_name
    ORDER BY st.full_name, st.student_id
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Template space is limited; avoid overwriting signatory cells lower in the sheet.
$maxDataRows = 51; // rows 10..60 inclusive
if (count($rows) > $maxDataRows) {
    $rows = array_slice($rows, 0, $maxDataRows);
}

$templatePath = __DIR__ . '/templates/ins_grade_report.xlsx';
if (!file_exists($templatePath)) {
    http_response_code(500);
    exit('Grade report template not found.');
}

$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);

$subjectDesc = trim((string)($info['subject_description'] ?? ''));
if ($subjectDesc === '') {
    $subjectDesc = (string)($info['title'] ?? '');
}

$unitsVal = $info['units'] ?? null;
$unitsDisplay = '';
if ($unitsVal !== null && $unitsVal !== '') {
    $unitsDisplay = is_numeric($unitsVal) ? (float)$unitsVal : (string)$unitsVal;
}

$academicYearLine = trim((string)($info['school_year'] ?? ''));

$sheet->setCellValue('E2', (string)($info['instructor_name'] ?? ''));
$sheet->setCellValue('K2', $normalizedSemester);
$sheet->setCellValue('E3', (string)($info['code'] ?? ''));
$sheet->setCellValue('K3', $academicYearLine);
$sheet->setCellValue('E4', $subjectDesc);
$sheet->setCellValue('K4', $scheduleSummary !== '' ? $scheduleSummary : '');
$sheet->setCellValue('E5', $unitsDisplay !== '' ? $unitsDisplay : '');
$sheet->setCellValue('K5', count($rows));
$sheet->setCellValue('E6', (string)($info['department'] ?? ''));
$sheet->setCellValue('K6', (string)($info['section'] ?? ''));

$termIdFirst3 = array_slice($termIds, 0, 3);
$headerCols = ['H','I','J'];
foreach ($termIdFirst3 as $idx => $tid) {
    if (isset($headerCols[$idx])) {
        $label = strtoupper(trim((string)($termsOrdered[$tid] ?? '')));
        if ($label === '') {
            $label = ['PRELIM', 'MIDTERM', 'FINALS'][$idx] ?? 'TERM';
        }
        $sheet->setCellValue($headerCols[$idx] . '8', $label);
    }
}

$dataStartRow = 10;
$clearEndRow = min(
    60,
    max($dataStartRow + 50, $dataStartRow + count($rows))
);
for ($r = $dataStartRow; $r <= $clearEndRow; $r++) {
    foreach (['A', 'B', 'C', 'H', 'I', 'J', 'K', 'L', 'M'] as $col) {
        $sheet->setCellValue($col . $r, '');
    }
}

// --- START: Percentage/weights-based (cell L10) computation adaptation ---

// Get the weights/percentages for prelim/midterm/finals from cell L10
// Format expected in L10: "30,30,40" (for example: prelim=30%, midterm=30%, finals=40%)
$weightsRaw = $sheet->getCell('L10')->getValue();

// Default fallback
$termWeights = [0.33, 0.33, 0.34];

// Parse the comma-separated weights if present
if (is_string($weightsRaw) && preg_match('/^(\d+)(?:\s*,\s*(\d+))?(?:\s*,\s*(\d+))?/', $weightsRaw, $m)) {
    $w0 = isset($m[1]) ? intval($m[1]) : 0;
    $w1 = isset($m[2]) ? intval($m[2]) : 0;
    $w2 = isset($m[3]) ? intval($m[3]) : 0;
    $sum = ($w0 + $w1 + $w2);
    if ($sum > 0) {
        $termWeights = [
            $w0 / 100,
            $w1 / 100,
            $w2 / 100
        ];
    }
}

// Only support the first 3 terms for the computation
if (!function_exists('gradeEquivalentFromPercent')) {
    /**
     * Convert percentage grade to institutional grade equivalent.
     */
    function gradeEquivalentFromPercent(float $percent): string
    {
        if ($percent >= 98) return '1.00';
        if ($percent >= 95) return '1.25';
        if ($percent >= 92) return '1.50';
        if ($percent >= 89) return '1.75';
        if ($percent >= 86) return '2.00';
        if ($percent >= 83) return '2.25';
        if ($percent >= 80) return '2.50';
        if ($percent >= 77) return '2.75';
        if ($percent >= 75) return '3.00';
        return '5.00';
    }
}

foreach ($rows as $idx => $r) {
    $rowNum = $dataStartRow + $idx;
    $sheet->setCellValue('A' . $rowNum, $idx + 1);
    $sheet->setCellValue('B' . $rowNum, (string)($r['student_id'] ?? ''));
    $sheet->setCellValue('C' . $rowNum, (string)($r['full_name'] ?? ''));

    $gradeVals = [];
    foreach ($termIds as $tid) {
        $key = 'term_' . $tid;
        $v = $r[$key] ?? null;
        if ($v !== null && $v !== '' && is_numeric($v)) {
            $gradeVals[] = (float)$v;
        } else {
            $gradeVals[] = null;
        }
    }

    // Fill columns H, I, J
    for ($colIdx = 0; $colIdx < 3; $colIdx++) {
        $col = ['H', 'I', 'J'][$colIdx];
        $val = isset($gradeVals[$colIdx]) && $gradeVals[$colIdx] !== null ? round($gradeVals[$colIdx], 2) : '';
        $sheet->setCellValue($col . $rowNum, $val);
    }

    // Compute overall using termWeights from L10
    $g0 = $gradeVals[0] ?? null;
    $g1 = $gradeVals[1] ?? null;
    $g2 = $gradeVals[2] ?? null;

    $overall = '';
    if ($g0 !== null && $g1 !== null && $g2 !== null) {
        $overallVal = $g0 * $termWeights[0] + $g1 * $termWeights[1] + $g2 * $termWeights[2];
        $overall = round($overallVal, 2);
    }
    $sheet->setCellValue('K' . $rowNum, $overall !== '' ? $overall : '');
    $equivalent = '';
    if ($overall !== '' && is_numeric($overall)) {
        $equivalent = gradeEquivalentFromPercent((float)$overall);
    }
    $sheet->setCellValue('L' . $rowNum, $equivalent);

    $remark = '';
    if ($overall !== '' && is_numeric($overall)) {
        $remark = (float)$overall >= 75.0 ? 'PASSED' : 'FAILED';
    }
    $sheet->setCellValue('M' . $rowNum, $remark);
}

// --- END: Percentage/weights-based computation adaptation ---

$sheet->setCellValue('F69', $executiveDeanName);
// $sheet->setCellValue('F70', $executiveDeanName); // some template versions
$sheet->setCellValue('K69', $vpAcademicAffairsName);
// $sheet->setCellValue('K70', $vpAcademicAffairsName); // some template versions
$sheet->setCellValue('K63', $programChairpersonName);
// $sheet->setCellValue('K64', $programChairpersonName); // some template versions

// Set instructor name automatically to cell F63
$sheet->setCellValue('F63', (string)($info['instructor_name'] ?? ''));

$totalPages = 1;
$sheet->setCellValue('I72', 'PAGE 1 OF ' . $totalPages);

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

$subjectCode = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($info['code'] ?? 'Subject'));
$sectionFile = $sectionId > 0
    ? preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($info['section'] ?? 'Section'))
    : 'AllSections';
$fileName = 'Instructor_AllTermsGrades_' . $subjectCode . '_' . $sectionFile . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;