<?php
declare(strict_types=1);

include_once __DIR__ . '/../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
include_once __DIR__ . '/../config/database.php';
$conn = getDb();
$db = $conn;

$instructorId        = isset($_GET['instructor_id']) ? (int)$_GET['instructor_id'] : 0;
$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : 0;
$termId              = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

if (!$instructorId || !$instructorSubjectId || !$termId) {
    header('Location: records.php');
    exit;
}

/* ===============================
   Validate instructor_subject
================================= */
$stmt = $db->prepare("
    SELECT id, subject_id, academic_year_id
    FROM instructor_subjects
    WHERE id = ? AND instructor_id = ?
    LIMIT 1
");
$stmt->execute([$instructorSubjectId, $instructorId]);
$instructorSubjectRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$instructorSubjectRow) {
    header('Location: records.php');
    exit;
}

/* ===============================
   Subject Info
================================= */
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
    LEFT JOIN class_sections cs ON cs.instructor_subject_id = isub.id
    LEFT JOIN academic_years ay ON ay.id = isub.academic_year_id
    WHERE isub.id = ?
    LIMIT 1
");
$stmtInfo->execute([$instructorSubjectId]);
$info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

if (!$info) {
    die('Subject information not found.');
}

$subjectCode    = $info['code'] ?? '';
$instructorName = strtoupper($info['instructor_name'] ?? '');
$schoolYear     = $info['school_year'] ?? '';
$semester       = strtoupper($info['semester'] ?? '');
$section        = $info['section'] ?? '';
$yearLevel      = $info['year_level'] ?? '';
$department     = $info['department'] ?? '';

if ($semester === '1ST') $semester = 'FIRST (1ST)';
if ($semester === '2ND') $semester = 'SECOND (2ND)';

/* ===============================
   Term Name
================================= */
$stmtTerm = $db->prepare("SELECT name FROM terms WHERE id = ? LIMIT 1");
$stmtTerm->execute([$termId]);
$termName = strtoupper($stmtTerm->fetchColumn() ?? 'TERM');

/* ===============================
   Activities
================================= */
$stmtA = $db->prepare("
    SELECT id, record_type, max_score
    FROM assessment_activities
    WHERE instructor_subject_id = ?
      AND term_id = ?
    ORDER BY activity_date, id
");
$stmtA->execute([$instructorSubjectId, $termId]);
$activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   Students
================================= */
$stmtE = $db->prepare("
    SELECT e.id as enrollment_id, st.full_name
    FROM enrollments e
    INNER JOIN students st ON st.id = e.student_id
    WHERE e.instructor_subject_id = ?
    ORDER BY st.full_name
");
$stmtE->execute([$instructorSubjectId]);
$students = $stmtE->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   Load Template
================================= */
$templatePath = __DIR__ . '/../assets/templates/term-template.xlsx';
if (!file_exists($templatePath)) {
    die('Template file not found.');
}

$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getSheet(0);

/* ===============================
   Header Data
================================= */
$sheet->setCellValue('C1', $subjectCode);
$sheet->setCellValue('C2', $instructorName);
$sheet->setCellValue('C3', $schoolYear);
$sheet->setCellValue('C4', $semester);
$sheet->setCellValue('E3', $yearLevel . $department . '-' . $section);

/* ===============================
   Column Helper
================================= */
function columnLetter(int $index): string {
    $letter = '';
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $letter = chr(65 + $mod) . $letter;
        $index = intdiv($index - $mod, 26);
    }
    return $letter;
}

/* ===============================
   Activity Headers
================================= */
$colIndex = 8; // Column H
$examColumn = null;

foreach ($activities as $a) {

    $type = strtolower(trim($a['record_type']));
    $title = '';

    switch ($type) {
        case 'quiz': $title = 'Q'; break;
        case 'project': $title = 'P'; break;
        case 'graded_activity': $title = 'GA'; break;
        case 'assignment': $title = 'AS'; break;
        case 'exercise': $title = 'EX'; break;
        case 'exam':
            $examColumn = $colIndex;
            $sheet->setCellValue(columnLetter($colIndex) . '8', 'EXAM');
            $sheet->setCellValue(columnLetter($colIndex) . '9', $a['max_score']);
            $colIndex++;
            continue 2;
        case 'lab_exam':
            $sheet->setCellValue(columnLetter($colIndex) . '8', 'LAB EX');
            $sheet->setCellValue(columnLetter($colIndex) . '9', $a['max_score']);
            $colIndex++;
            continue 2;
        default:
            $title = 'A';
    }

    $sheet->setCellValue(columnLetter($colIndex) . '8', $title);
    $sheet->setCellValue(columnLetter($colIndex) . '9', $a['max_score']);
    $colIndex++;
}

/* ===============================
   Student Data
================================= */
$rowIndex = 10;

foreach ($students as $s) {

    $sheet->setCellValue("B$rowIndex", $s['full_name']);

    $stmtR = $db->prepare("
        SELECT activity_id, score
        FROM assessment_records
        WHERE enrollment_id = ?
          AND term_id = ?
    ");
    $stmtR->execute([$s['enrollment_id'], $termId]);

    $records = [];
    foreach ($stmtR as $r) {
        $records[$r['activity_id']] = $r['score'];
    }

    $colIndex = 8;

    foreach ($activities as $a) {
        $score = $records[$a['id']] ?? '';
        $sheet->setCellValue(columnLetter($colIndex) . $rowIndex, $score);
        $colIndex++;
    }

    $rowIndex++;
}

/* ===============================
   Clean Output
================================= */
while (ob_get_level()) ob_end_clean();
ini_set('zlib.output_compression', 'Off');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $instructorName . '_'. $subjectCode  . '_' . $yearLevel . $department . '-' . $section . '_' . $termName . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;