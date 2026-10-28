<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../instructor/includes/grades.php';
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
    die('Spreadsheet export library is not available.');
}

use PDO;
// use PhpOffice\PhpSpreadsheet\IOFactory;
// use PhpOffice\PhpSpreadsheet\Spreadsheet;

$db = getDb();
$programDepartment = deanSelectedProgramDepartment();
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

$classSectionId = isset($_GET['class_section_id']) ? (int)$_GET['class_section_id'] : 0;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
$allTerms = !empty($_GET['all_terms']);

if ($classSectionId <= 0 || (!$allTerms && $termId <= 0)) {
    header('Location: class_records.php');
    exit;
}

$sql = "
    SELECT
        cs.id AS class_section_id,
        cs.subject_id,
        cs.instructor_subject_id,
        cs.year_level,
        cs.section,
        cs.department AS class_department,
        cs.academic_year_id,
        s.code AS subject_code,
        s.title AS subject_title,
        s.department AS subject_department,
        i.full_name AS instructor_name,
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
    if (!empty($expr)) {
        $sql .= " AND (" . implode(' OR ', $expr) . ")";
    } else {
        $sql .= " AND 0 = 1";
    }
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$info = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$info) {
    header('Location: class_records.php');
    exit;
}

$scheduleSql = "
    SELECT day_of_week, start_time, end_time
    FROM schedule
    WHERE instructor_subject_id = ?
";
$scheduleParams = [(int)($info['instructor_subject_id'] ?? 0)];
if (!empty($info['section'])) {
    $scheduleSql .= " AND section_name = ?";
    $scheduleParams[] = (string)$info['section'];
}
$scheduleSql .= " ORDER BY day_of_week, start_time";
$stmtSchedule = $db->prepare($scheduleSql);
$stmtSchedule->execute($scheduleParams);
$scheduleRows = $stmtSchedule->fetchAll(PDO::FETCH_ASSOC);
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$scheduleTexts = [];
foreach ($scheduleRows as $slot) {
    $day = (int)($slot['day_of_week'] ?? 0);
    $dayLabel = $dayNames[$day] ?? ('Day ' . $day);
    $startRaw = (string)($slot['start_time'] ?? '');
    $endRaw = (string)($slot['end_time'] ?? '');
    $startLabel = $startRaw !== '' ? date('g:i A', strtotime($startRaw)) : '';
    $endLabel = $endRaw !== '' ? date('g:i A', strtotime($endRaw)) : '';
    if ($startLabel !== '' || $endLabel !== '') {
        $scheduleTexts[] = trim($dayLabel . ' ' . $startLabel . ' - ' . $endLabel);
    }
}
$scheduleSummary = !empty($scheduleTexts) ? implode(', ', $scheduleTexts) : 'No schedule set';

$terms = [];
$stmtTerms = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
while ($row = $stmtTerms->fetch(PDO::FETCH_ASSOC)) {
    $terms[(int)$row['id']] = (string)$row['name'];
}

$templatePath = __DIR__ . '/templates/term-template.xlsx';
if (!file_exists($templatePath)) {
    die('Term record template file not found.');
}

$columnIndexToLetter = function (int $index): string {
    $letter = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int)(($index - $mod) / 26);
    }
    return $letter;
};

$shortTypeCodes = [
    'class_participation' => 'C',
    'quiz' => 'Q',
    'project' => 'P',
    'graded_activity' => 'GA',
    'assignment' => 'AS',
    'exercise' => 'EX',
];

$normalizedSemester = (string)($info['semester'] ?? '');
if ($normalizedSemester === '1st') {
    $normalizedSemester = 'FIRST (1ST)';
} elseif ($normalizedSemester === '2nd') {
    $normalizedSemester = 'SECOND (2ND)';
}

// We need to collect all tabular content and export as CSV.
// Let's adjust $buildTermSheet so it returns structured data we can easily output as CSV instead of populating a PHPExcel sheet.

$buildTermSheetData = function (
    int $termIdForSheet, string $termSheetName
) use (
    $db,
    $classSectionId,
    $info,
    $scheduleSummary,
    $shortTypeCodes
) {
    $subjectCode = (string)($info['subject_code'] ?? '');
    $instructorName = strtoupper((string)($info['instructor_name'] ?? ''));
    $schoolYear = (string)($info['school_year'] ?? '');
    $semester = (string)($info['semester'] ?? '');
    if ($semester === '1st') $semester = 'FIRST (1ST)';
    if ($semester === '2nd') $semester = 'SECOND (2ND)';
    $yearLevel = (string)($info['year_level'] ?? '');
    $department = (string)($info['class_department'] ?? '');
    $section = (string)($info['section'] ?? '');

    // Prepare meta/header section (as metadata rows above table)
    $meta = [
        ["Subject Code", $subjectCode],
        ["Instructor Name", $instructorName],
        ["School Year", $schoolYear],
        ["Semester", $semester],
        ["Schedule", $scheduleSummary],
        ["Year/Department/Section", $yearLevel . '' . $department . '-' . $section],
    ];

    // Activities columns
    $activities = [];
    if (!empty($info['instructor_subject_id'])) {
        $stmtA = $db->prepare("
            SELECT id, record_type, title, max_score
            FROM assessment_activities
            WHERE instructor_subject_id = ?
              AND term_id = ?
            ORDER BY activity_date, id
        ");
        $stmtA->execute([(int)$info['instructor_subject_id'], $termIdForSheet]);
        $activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);
    }

    $examMaxFromAssessment = 0;
    foreach ($activities as $a) {
        $rt = strtoupper(trim((string) $a['record_type']));
        if ($rt === 'EXAM' || $rt === 'LAB_EXAM') {
            $examMaxFromAssessment += (float) $a['max_score'];
        }
    }

    $stmtE = $db->prepare("
        SELECT e.id as enrollment_id, st.full_name, st.student_id
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        WHERE e.class_section_id = ?
        ORDER BY st.full_name, st.student_id
    ");
    $stmtE->execute([$classSectionId]);
    $students = $stmtE->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($students) && !empty($activities)) {
        $enrollmentIds = array_column($students, 'enrollment_id');
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $sqlFilter = "
            SELECT DISTINCT activity_id
            FROM assessment_records
            WHERE enrollment_id IN ($placeholders)
              AND term_id = ?
              AND activity_id IS NOT NULL
        ";
        $stmtFilter = $db->prepare($sqlFilter);
        $stmtFilter->execute(array_merge($enrollmentIds, [$termIdForSheet]));
        $allowedActivityIds = $stmtFilter->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!empty($allowedActivityIds)) {
            $activities = array_values(array_filter(
                $activities,
                static fn($a) => in_array($a['id'], $allowedActivityIds)
            ));
        } else {
            $activities = [];
        }
    }

    // Prepare header
    $headers = ['Student Name', /* will insert activities headers dynamically next */];

    foreach ($activities as $a) {
        $rt = strtoupper(trim((string) $a['record_type']));
        if ($rt === 'EXAM' || $rt === 'LAB_EXAM') {
            continue;
        }
        $label = trim((string)($a['title'] ?? ''));
        if ($label === '') {
            $rt = (string)($a['record_type'] ?? '');
            $key = strtolower($rt);
            $label = $shortTypeCodes[$rt] ?? ($shortTypeCodes[$key] ?? strtoupper(substr($key, 0, 3)));
        }
        // Add full label for CSV clarity
        $headers[] = $label;
    }

    $headers = array_merge($headers, ['Values Total', 'Attendance (%)', 'Exam Score', 'Exam (%)']);

    // Sub-headers: Row with max_scores for activities & exam
    $maxScoresRow = [''];
    foreach ($activities as $a) {
        $rt = strtoupper(trim((string) $a['record_type']));
        if ($rt === 'EXAM' || $rt === 'LAB_EXAM') {
            continue;
        }
        $maxScoresRow[] = $a['max_score'];
    }
    $maxScoresRow = array_merge($maxScoresRow, ['', '', '', ($examMaxFromAssessment > 0 ? $examMaxFromAssessment : '')]);

    // Student Rows
    $studentRows = [];
    foreach ($students as $s) {
        $enrollmentId = (int)$s['enrollment_id'];

        $stmtR = $db->prepare("
            SELECT activity_id, score
            FROM assessment_records
            WHERE enrollment_id = ?
              AND term_id = ?
        ");
        $stmtR->execute([$enrollmentId, $termIdForSheet]);
        $records = [];
        while ($r = $stmtR->fetch(PDO::FETCH_ASSOC)) {
            $records[$r['activity_id']] = (float)$r['score'];
        }

        $row = [$s['full_name']];
        $valuesTotal = 0;
        foreach ($activities as $a) {
            $rt = strtoupper(trim((string) $a['record_type']));
            if ($rt === 'EXAM' || $rt === 'LAB_EXAM') {
                continue;
            }
            $score = array_key_exists($a['id'], $records) ? $records[$a['id']] : '';
            $row[] = $score;
            if ($score !== '' && strtolower((string)$a['record_type']) === 'values') {
                $valuesTotal += (float)$score;
            }
        }
        $row[] = $valuesTotal;

        // Attendance (same weighting as term grade)
        $attendancePercent = attendance_weighted_percent($db, $enrollmentId, $termIdForSheet);

        // Exam + lab exam (same weighting as term grade); fallback legacy term_exam_scores.
        $examScore = 0.0;
        $examMax = 0.0;
        foreach ($activities as $a) {
            $rt = strtoupper(trim((string) $a['record_type']));
            if ($rt !== 'EXAM' && $rt !== 'LAB_EXAM') {
                continue;
            }
            $examMax += (float) ($a['max_score'] ?? 0);
            if (array_key_exists($a['id'], $records)) {
                $examScore += (float) $records[$a['id']];
            }
        }
        if ($examMax <= 0) {
            $stmtExam = $db->prepare("
                SELECT score, max_score
                FROM term_exam_scores
                WHERE enrollment_id = ?
                  AND term_id = ?
                LIMIT 1
            ");
            $stmtExam->execute([$enrollmentId, $termIdForSheet]);
            $exam = $stmtExam->fetch(PDO::FETCH_ASSOC);
            $examScore = (float) ($exam['score'] ?? 0);
            $examMax = (float) ($exam['max_score'] ?? 0);
        }
        $examPercent = ($examMax > 0) ? round(($examScore / $examMax) * 100, 2) : 0;

        $row[] = $attendancePercent;
        $row[] = $examScore;
        $row[] = $examPercent;

        $studentRows[] = $row;
    }

    return [
        'meta' => $meta,
        'headers' => $headers,
        'max_scores_header' => $maxScoresRow,
        'rows' => $studentRows,
        'sheet_name' => $termSheetName,
    ];
};

// Prepare output file name
if ($allTerms) {
    $fileName = 'Dean_RecordBook_AllTerms_' . (string)($info['subject_code'] ?? 'Class') . '.csv';
} else {
    $termName = strtoupper($terms[$termId] ?? 'Term');
    $fileName = 'Dean_RecordBook_' . $termName . '_' . (string)($info['subject_code'] ?? 'Class') . '.csv';
}

// Clean output and set headers for CSV
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

// Multiple terms will be stacked in one file, separated by a line of dashes.
// Optionally, could be separated by an extra blank line or commented row.
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) . '"');
header('Cache-Control: max-age=0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel compatibility:
fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$termDataSets = [];
if ($allTerms) {
    foreach ($terms as $tid => $tname) {
        $safeName = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', (string)$tname);
        $safeName = trim($safeName) !== '' ? trim($safeName) : ('Term ' . (int)$tid);
        $safeName = mb_substr($safeName, 0, 31, 'UTF-8');
        $termDataSets[] = $buildTermSheetData((int)$tid, $safeName);
    }
} else {
    $termName = strtoupper($terms[$termId] ?? 'Term');
    $termDataSets[] = $buildTermSheetData($termId, mb_substr($termName, 0, 31, 'UTF-8'));
}

// Write data for each term (if allTerms selected, they are stacked in same CSV)
foreach ($termDataSets as $idx => $data) {
    // -- metadata section (label/value pairs)
    foreach ($data['meta'] as $metaRow) {
        fputcsv($output, $metaRow);
    }
    // Add sheet (term) label for allTerms export clarity
    if ($allTerms) {
        fputcsv($output, ["Term", $data['sheet_name']]);
    }
    fputcsv($output, []); // Blank row before table
    // -- headers
    fputcsv($output, $data['headers']);
    // -- max scores
    fputcsv($output, $data['max_scores_header']);
    // -- student rows
    foreach ($data['rows'] as $studentRow) {
        fputcsv($output, $studentRow);
    }
    // Add separation between terms (if allTerms)
    if ($idx < count($termDataSets) - 1) {
        fputcsv($output, []); // blank row
        fputcsv($output, array_fill(0, count($data['headers']), '------------------'));
        fputcsv($output, []); // blank row
    }
}
fclose($output);
exit;
