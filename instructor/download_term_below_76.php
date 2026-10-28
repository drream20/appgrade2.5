<?php

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Same layout as instructor/templates/students-below-76-term-template.xlsx when the file is missing.
 */
function build_students_below_76_template_spreadsheet(): Spreadsheet
{
    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Below 76');

    $sh->mergeCells('A1:E1');
    $sh->setCellValue('A1', 'STUDENTS WITH TERM GRADE BELOW 76%');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sh->setCellValue('A3', 'Subject Code:');
    $sh->setCellValue('A4', 'Subject Title:');
    $sh->setCellValue('A5', 'Instructor:');
    $sh->setCellValue('A6', 'School Year:');
    $sh->setCellValue('C6', 'Semester:');
    $sh->setCellValue('A7', 'Term:');
    $sh->setCellValue('C7', 'Section:');

    $hdr = 9;
    $sh->setCellValue('A' . $hdr, 'No.');
    $sh->setCellValue('B' . $hdr, 'Student Name');
    $sh->setCellValue('C' . $hdr, 'Student ID');
    $sh->setCellValue('D' . $hdr, 'Term Grade (%)');
    $sh->setCellValue('E' . $hdr, 'Remarks');
    $sh->getStyle('A' . $hdr . ':E' . $hdr)->getFont()->setBold(true);
    $sh->getStyle('A' . $hdr . ':E' . $hdr)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2EFDA');
    $sh->getStyle('A' . $hdr . ':E' . $hdr)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    foreach (range('A', 'E') as $col) {
        $sh->getColumnDimension($col)->setAutoSize(true);
    }

    return $ss;
}

requireLogin();

const BELOW_THRESHOLD = 76.0;

$db = getDb();
$instructorId = currentInstructorId();

$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int) $_GET['instructor_subject_id'] : null;
$termId = isset($_GET['term_id']) ? (int) $_GET['term_id'] : null;
$sectionId = isset($_GET['section_id']) ? (int) $_GET['section_id'] : null;

if (!$instructorSubjectId || !$termId) {
    header('Location: records.php');
    exit;
}

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

$stmtTerm = $db->prepare('SELECT name FROM terms WHERE id = ? LIMIT 1');
$stmtTerm->execute([$termId]);
$termName = (string) $stmtTerm->fetchColumn();

if ($semester === '1st') {
    $semesterLabel = 'FIRST (1ST)';
} elseif ($semester === '2nd') {
    $semesterLabel = 'SECOND (2ND)';
} else {
    $semesterLabel = strtoupper((string) $semester);
}

$sectionLabel = $yearLevel . '' . $department . '-' . $section;

if ($sectionId) {
    $sql = "
        SELECT st.full_name, st.student_id, tg.term_grade_computed
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        INNER JOIN term_grades tg ON tg.enrollment_id = e.id AND tg.term_id = ?
        WHERE e.class_section_id = ?
          AND tg.term_grade_computed < ?
        ORDER BY st.full_name, st.student_id
    ";
    $stmtList = $db->prepare($sql);
    $stmtList->execute([$termId, $sectionId, BELOW_THRESHOLD]);
} else {
    $sql = "
        SELECT st.full_name, st.student_id, tg.term_grade_computed
        FROM enrollments e
        INNER JOIN students st ON st.id = e.student_id
        INNER JOIN term_grades tg ON tg.enrollment_id = e.id AND tg.term_id = ?
        WHERE (e.instructor_subject_id = ?
           OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?))
          AND tg.term_grade_computed < ?
        ORDER BY st.full_name, st.student_id
    ";
    $stmtList = $db->prepare($sql);
    $stmtList->execute([$termId, $instructorSubjectId, $instructorSubjectId, BELOW_THRESHOLD]);
}
$rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$templatePath = __DIR__ . '/templates/students-below-76-term-template.xlsx';
if (file_exists($templatePath)) {
    $spreadsheet = IOFactory::load($templatePath);
} else {
    $spreadsheet = build_students_below_76_template_spreadsheet();
}
$sheet = $spreadsheet->getSheet(0);
if (!$sheet) {
    die('Sheet not found in template.');
}

$instructorNameU = strtoupper($instructorName);
$sheet->setCellValue('B3', $subjectCode);
$sheet->setCellValue('B4', $subjectTitle);
$sheet->setCellValue('B5', $instructorNameU);
$sheet->setCellValue('B48', $instructorNameU);
$sheet->setCellValue('B6', $schoolYear);
$sheet->setCellValue('D6', $semesterLabel);
$sheet->setCellValue('B7', strtoupper($termName));
$sheet->setCellValue('D7', $sectionLabel);

$startRow = 10;
$no = 1;
foreach ($rows as $r) {
    $g = round((float) $r['term_grade_computed'], 2);
    $sheet->setCellValue('A' . $startRow, $no);
    $sheet->setCellValue('B' . $startRow, $r['full_name']);
    $sheet->setCellValue('C' . $startRow, $r['student_id']);
    $sheet->setCellValue('D' . $startRow, $g);
    $sheet->setCellValue('E' . $startRow, 'Below 76%');
    $sheet->getStyle('A' . $startRow . ':E' . $startRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $no++;
    $startRow++;
}

$safeSubject = preg_replace('/[^A-Za-z0-9_-]+/', '_', $subjectCode);
$safeTerm = preg_replace('/[^A-Za-z0-9_-]+/', '_', $termName);
$filename = preg_replace(
    '/[^A-Za-z0-9._-]+/',
    '_',
    $instructorNameU . '_' . $safeSubject . '_Below76_' . $safeTerm . '_AY' . $schoolYear . '.xlsx'
);

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', 'Off');
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit;
