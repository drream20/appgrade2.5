<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require_once __DIR__ . '/includes/subject_code_lab.php';

// Attempt to load PhpSpreadsheet library before using its classes
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$libraryLoaded = false;
foreach ($autoloadPaths as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $libraryLoaded = true;
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

$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : null;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : null;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;

if (!$instructorSubjectId || !$termId) {
    header('Location: records.php');
    exit;
}

// Validate that this instructor teaches this subject (not a lab-only row for records)
$stmt = $db->prepare("
    SELECT isub.id, isub.subject_id, isub.academic_year_id, s.code
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

// Accurate subject/section info from the database (optionally per section)
if ($sectionId) {
    $stmtInfo = $db->prepare("
        SELECT 
            s.code,
            s.title,
            i.full_name AS instructor_name,
            ay.school_year,
            ay.semester,
            cs.section,
            cs.year_level,
            cs.department
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
        SELECT 
            s.code,
            s.title,
            i.full_name AS instructor_name,
            ay.school_year,
            ay.semester,
            cs.section,
            cs.year_level,
            cs.department
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
$info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

$subjectCode = $info['code'] ?? '';
$subjectTitle = $info['title'] ?? '';
$instructorName = $info['instructor_name'] ?? '';
$schoolYear = $info['school_year'] ?? '';
$semester = $info['semester'] ?? '';
$section = $info['section'] ?? '';
$yearLevel = $info['year_level'] ?? '';
$department = $info['department'] ?? '';
$stmtTerm = $db->prepare("SELECT name FROM terms WHERE id = ? LIMIT 1");
$stmtTerm->execute([$termId]);
$termName = strtoupper($stmtTerm->fetchColumn());

// Get assessment activities for this instructor_subject and term
$stmtA = $db->prepare("
    SELECT 
        id,
        record_type,
        title,
        max_score
    FROM assessment_activities
    WHERE instructor_subject_id = ?
      AND term_id = ?
    ORDER BY activity_date, id
");
$stmtA->execute([$instructorSubjectId, $termId]);
$activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);

// Get enrolled students (accurate to database, optionally per section)
if ($sectionId) {
    $stmtE = $db->prepare("
        SELECT e.id as enrollment_id,
               st.full_name
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        WHERE e.class_section_id = ?
        ORDER BY st.full_name, st.student_id
    ");
    $stmtE->execute([$sectionId]);
} else {
    $stmtE = $db->prepare("
        SELECT e.id as enrollment_id,
               st.full_name
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        WHERE e.instructor_subject_id = ?
           OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?)
        ORDER BY st.full_name, st.student_id
    ");
    $stmtE->execute([$instructorSubjectId, $instructorSubjectId]);
}
$students = $stmtE->fetchAll(PDO::FETCH_ASSOC);

// When a specific section is selected, only include assessment
// activities that actually have recorded scores for at least one
// student in that section for this term. This mirrors the on-screen
// behavior in records_assessment.php and prevents activities from
// other sections from appearing in the downloaded sheet.
if ($sectionId && !empty($students) && !empty($activities)) {
    $enrollmentIds = array_column($students, 'enrollment_id');
    if (!empty($enrollmentIds)) {
        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $sql = "
            SELECT DISTINCT activity_id
            FROM assessment_records
            WHERE enrollment_id IN ($placeholders)
              AND term_id = ?   
              AND activity_id IS NOT NULL
        ";
        $stmtFilter = $db->prepare($sql);
        $params = array_merge($enrollmentIds, [$termId]);
        $stmtFilter->execute($params);
        $allowedActivityIds = $stmtFilter->fetchAll(PDO::FETCH_COLUMN, 0);

        if (!empty($allowedActivityIds)) {
            $activities = array_values(array_filter(
                $activities,
                function ($a) use ($allowedActivityIds) {
                    return in_array($a['id'], $allowedActivityIds);
                }
            ));
        } else {
            // No recorded assessments for this section & term
            $activities = [];
        }
    }
}

// Use the official term template file from instructor/templates directory
$templatePath = __DIR__ . '/templates/term-template.xlsx';
if (!file_exists($templatePath)) {
    die('Term record template file not found at path: ' . $templatePath);
}
$spreadsheet = IOFactory::load($templatePath);

// Use the first sheet (index 0)
$sheet = $spreadsheet->getSheet(0);
if (!$sheet) {
    die("Sheet at index 0 not found in template.");
}
if($semester == '1st'){
    $semester = 'FIRST (1ST)';
}else if($semester == '2nd'){
    $semester = 'SECOND (2ND)';
}

$instructorName = strtoupper($instructorName);
// Accurate header info
$sheet->setCellValue('C1', $subjectCode);
$sheet->setCellValue('C2', $instructorName);
$sheet->setCellValue('C3', $schoolYear);
$sheet->setCellValue('C4', $semester);
$sheet->setCellValue('E3', $yearLevel . '' . $department . '-' . $section);
$sheet->setCellValue('H5', $subjectCode . ' - ' . $yearLevel . '' . $department . '-' . $section . ' ' . $instructorName . ' AY' . $schoolYear . ' ' . $semester);

// =====================
// Write Activity Headers
// =====================

// We'll filter out 'values' activities when writing headers and columns H9:AA9 etc
$colIndex = 8; // Start in column H
$totalMax = 0;

// Helper: 1-based to Excel col letter
$columnIndexToLetter = function (int $index): string {
    $index = (int)$index;
    $letter = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = (int)(($index - $mod) / 26);
    }
    return $letter;
};

// Short codes for activity types when title is blank
$shortTypeCodes = [
    'class_participation' => 'C',
    'quiz'               => 'Q',
    'project'            => 'P',
    'graded_activity'    => 'GA',
    'assignment'         => 'AS',
    'exercise'           => 'EX',
];

// AF8: combined max for written exam + lab exam (same weighting as term grade).
$examScoreMax = 0;
foreach ($activities as $a) {
    $rt = strtoupper(trim($a['record_type']));
    if ($rt === 'EXAM' || $rt === 'LAB_EXAM') {
        $examScoreMax += (float) $a['max_score'];
    }
}

// Calculate and set max score for 'values' activities (for AD9)
$valuesMaxScore = 0;

// Only write activity headers for non-'values' type
foreach ($activities as $a) {
    $rtHdr = strtoupper(trim($a['record_type']));
    if ($rtHdr === 'EXAM' || $rtHdr === 'LAB_EXAM') {
        // Summarized in AF/AG (not a separate activity column).
        continue;
    }
    if (strtoupper(trim($a['record_type'])) === 'VALUES') {
        // Instead, accumulate its max_score for AD9, but do not generate per-activity column.
        if (strtolower($a['record_type']) === 'values') {
            $valuesMaxScore += (float)$a['max_score'];
        }
        continue;
    }

    // Determine category for key column
    $label = $a['record_type'];
    if ($label === 'class_participation' || $label === 'graded_activity') {
        $keyChar = 'GA';
    } else if ($label === 'project') {
        $keyChar = 'P';
    } else if ($label === 'assignment') {
        $keyChar = 'AS';
    } else if ($label === 'exercise') {
        $keyChar = 'EX';
    } else {
        $keyChar = 'Q';
    }

    // Row 8: Activity title/label; Row 9: Max score per activity
    $colLetter = $columnIndexToLetter($colIndex);
    $sheet->setCellValue($colLetter . '8', $keyChar);
    $sheet->setCellValue($colLetter . '9', $a['max_score']);
    $totalMax += (float)$a['max_score'];
    $colIndex++;
}

// Write total max score for 'values' activities into AD9
$sheet->setCellValue('AD9', $valuesMaxScore);

$totalColLetter = $columnIndexToLetter($colIndex);

// Write max exam score (from assessment_activities, not term_exam_scores) to AF8
$sheet->setCellValue("AF8", $examScoreMax);

// =====================
// Student Loop (accurate data for each enrolled student)
// =====================
$rowIndex = 10;
$no = 1;

foreach ($students as $s) {
    $sheet->setCellValue("B$rowIndex", $s['full_name']);

    // Fetch all assessment scores for the student's enrollment & this term
    $stmtR = $db->prepare("
        SELECT activity_id, score
        FROM assessment_records
        WHERE enrollment_id = ?
          AND term_id = ?
    ");
    $stmtR->execute([$s['enrollment_id'], $termId]);
    $records = [];
    while ($r = $stmtR->fetch(PDO::FETCH_ASSOC)) {
        $records[$r['activity_id']] = (float)$r['score'];
    }

    $assessmentTotal = 0;
    $assessmentMaxTotal = 0;
    $valuesTotal = 0; // total of all 'values' type scores for this student
    $examScoreDB = 0;
    $examMaxDB = 0;
    $colIndex = 8;

    // Write only non-values activities into columns H-AA
    foreach ($activities as $a) {
        $rtAct = strtoupper(trim($a['record_type']));
        if ($rtAct === 'EXAM' || $rtAct === 'LAB_EXAM') {
            $examScoreDB += array_key_exists($a['id'], $records) ? $records[$a['id']] : 0;
            $examMaxDB += isset($a['max_score']) ? (float) $a['max_score'] : 0.0;
            continue;
        }
        if (strtoupper(trim($a['record_type'])) === 'VALUES') {
            // Accumulate scores for values, will be written into AD column only
            $score = array_key_exists($a['id'], $records) ? $records[$a['id']] : 0;
            if ($score !== '') {
                $valuesTotal += (float)$score;
            }
            continue;
        }
        $score = array_key_exists($a['id'], $records) ? $records[$a['id']] : '';
        $colLetter = $columnIndexToLetter($colIndex);
        $sheet->setCellValue($colLetter . $rowIndex, $score);

        if ($score !== '') {
            $assessmentTotal += $score;
            $assessmentMaxTotal += (float)$a['max_score'];
        }
        $colIndex++;
    }

    // Write total 'values' score into column AD for this student (per template layout)
    $sheet->setCellValue("AD$rowIndex", $valuesTotal);

    // Attendance %: same weighting as term grade (excused = present; 3 lates ≡ 1 absent)
    $attendancePercent = attendance_weighted_percent($db, (int) $s['enrollment_id'], (int) $termId);

    // Exam AF: sum of exam + lab_exam raw scores; AG: combined percentage.

    $examPercent = ($examMaxDB > 0)
        ? round(($examScoreDB / $examMaxDB) * 100, 2)
        : 0;

    // Fetch the computed term grade (Prelim/Mid/Midterm/etc for this term) from term_grades
    $stmtTG = $db->prepare("
        SELECT term_grade_computed 
        FROM term_grades 
        WHERE enrollment_id = ? 
          AND term_id = ?
        LIMIT 1
    ");
    $stmtTG->execute([$s['enrollment_id'], $termId]);
    $tgRow = $stmtTG->fetch(PDO::FETCH_ASSOC);
    $termGrade = $tgRow ? (float)$tgRow['term_grade_computed'] : 0.0;

    // Sample computations for summary columns:
    // AG: Exam percentage; AH: Transmuted (sample: same as term grade); AI: Term grade; AJ: Remarks (Pass/Fail)
    $sheet->setCellValue("AE$rowIndex", $attendancePercent);
    $sheet->setCellValue("AF$rowIndex", $examScoreDB);
    $sheet->setCellValue("AG$rowIndex", $examPercent);
    // $sheet->setCellValue("AI$rowIndex", $termGrade);

    // $remarks = $termGrade >= 60 ? 'PASSED' : 'FAILED';
    // $sheet->setCellValue("AJ$rowIndex", $remarks);

    $rowIndex++;
}

// Prepare for download (buffer clean, accurate headers)
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="'.$instructorName.'_'.$subjectCode . '_'. $yearLevel . '' . $department . '-' . $section .'_AY' . $schoolYear . '_' . $semester. '.xlsx"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;