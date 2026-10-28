<?php
/**
 * Per-student grade copy: all enrolled subjects with term grades (%) and weighted final.
 * Layout: registrar/templates/student-grade-copy-template.xlsx (up to 6 term columns + final + equiv.).
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

registrarRequireLogin();

/** @return float Weighted percentage (same weighting as TOR: term 1–3 → 30%, 30%, 40%). */
function registrar_weighted_final_pct(array $tgByTermId): float
{
    $weights = [1 => 0.30, 2 => 0.30, 3 => 0.40];
    $sumWeight = 0.0;
    $sumValue = 0.0;
    foreach ($tgByTermId as $tid => $val) {
        $w = $weights[(int) $tid] ?? 0;
        if ($w <= 0) {
            continue;
        }
        $sumWeight += $w;
        $sumValue += (float) $val * $w;
    }

    return $sumWeight > 0 ? round($sumValue / $sumWeight, 2) : 0.0;
}

function registrar_percent_to_college_grade(float $pct): string
{
    if ($pct >= 99) {
        return '1.00';
    }
    if ($pct >= 96) {
        return '1.25';
    }
    if ($pct >= 93) {
        return '1.50';
    }
    if ($pct >= 90) {
        return '1.75';
    }
    if ($pct >= 87) {
        return '2.00';
    }
    if ($pct >= 84) {
        return '2.25';
    }
    if ($pct >= 81) {
        return '2.50';
    }
    if ($pct >= 78) {
        return '2.75';
    }
    if ($pct >= 75) {
        return '3.00';
    }

    return '5.00';
}

function registrar_semester_label($sem): string
{
    $s = strtoupper(trim((string) $sem));
    $s = str_replace(['-', ' '], '', $s);
    if (in_array($s, ['1', '1ST', '1STSEM', 'FIRST', 'FIRSTSEM'], true)) {
        return '1st Sem';
    }
    if (in_array($s, ['2', '2ND', '2NDSEM', 'SECOND', 'SECONDSEM'], true)) {
        return '2nd Sem';
    }

    return $sem !== '' ? (string) $sem : '—';
}

/**
 * Signatory block in student-grade-copy-template.xlsx (Executive Dean / School President / Program Chair / Registrar).
 * Row numbers shift by $footerShift when extra data rows were inserted before row 29.
 */
function registrar_grade_copy_apply_template_signatories(
    $sheet,
    int $footerShift,
    string $executiveDean,
    string $programChairperson,
    string $schoolPresident,
    string $registrarOfficer
): void {
    $fs = $footerShift;
    $r32 = 32 + $fs;
    $r35 = 35 + $fs;

    $sheet->setCellValue('B' . $r32, $executiveDean);
    $sheet->setCellValue('I' . $r32, $schoolPresident);
    $sheet->setCellValue('B' . $r35, $programChairperson);
    $sheet->setCellValue('I' . $r35, $registrarOfficer);
}

/**
 * When the built-in workbook is used (no template file), append signatory lines after the table.
 */
function registrar_grade_copy_append_signatories(
    $sheet,
    int $startRow,
    string $executiveDean,
    string $programChairperson,
    string $schoolPresident,
    string $registrarOfficer
): void {
    $r = $startRow + 2;
    $sheet->setCellValue('A' . $r, 'Executive Dean:');
    $sheet->setCellValue('B' . $r, $executiveDean);
    $r++;
    $sheet->setCellValue('A' . $r, 'Program Chairperson:');
    $sheet->setCellValue('B' . $r, $programChairperson);
    $r++;
    $sheet->setCellValue('A' . $r, 'School President:');
    $sheet->setCellValue('B' . $r, $schoolPresident);
    $r++;
    $sheet->setCellValue('A' . $r, 'Registrar Officer:');
    $sheet->setCellValue('B' . $r, $registrarOfficer);
}

/**
 * Fallback workbook when template file is missing (same column layout as student-grade-copy-template.xlsx).
 */
function registrar_build_grade_copy_workbook(): Spreadsheet
{
    $termPlaceholders = [];
    for ($i = 1; $i <= 6; $i++) {
        $termPlaceholders[] = "Term {$i} (%)";
    }
    $headers = array_merge(
        ['No.', 'Subject Code', 'Subject Title', 'Units', 'School Year', 'Semester'],
        $termPlaceholders,
        ['Final grade (%)', 'Equiv. grade']
    );

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Grade copy');

    $lastCol = Coordinate::stringFromColumnIndex(count($headers));
    $sh->mergeCells('A1:' . $lastCol . '1');
    $sh->setCellValue('A1', 'COPY OF GRADES — ALL SUBJECTS (PER STUDENT)');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sh->setCellValue('A3', 'Student No.:');
    $sh->setCellValue('A4', 'Student Name:');
    $sh->setCellValue('A5', 'Program:');
    $sh->setCellValue('A6', 'Date generated:');

    $rowH = 8;
    $c = 1;
    foreach ($headers as $h) {
        $L = Coordinate::stringFromColumnIndex($c++);
        $sh->setCellValue($L . $rowH, $h);
    }
    $sh->getStyle('A' . $rowH . ':' . $lastCol . $rowH)->getFont()->setBold(true);
    $sh->getStyle('A' . $rowH . ':' . $lastCol . $rowH)->getFill()
        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E1F2');
    $sh->getStyle('A' . $rowH . ':' . $lastCol . $rowH)->getBorders()
        ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $lastIdx = Coordinate::columnIndexFromString($lastCol);
    for ($i = 1; $i <= $lastIdx; $i++) {
        $sh->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
    }

    return $ss;
}

$db = getDb();
$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

$executiveDean = isset($_GET['executive_dean']) ? trim((string) $_GET['executive_dean']) : '';
$programChairperson = isset($_GET['program_chairperson']) ? trim((string) $_GET['program_chairperson']) : '';
$schoolPresident = isset($_GET['school_president']) ? trim((string) $_GET['school_president']) : '';
$registrarOfficer = isset($_GET['registrar_officer']) ? trim((string) $_GET['registrar_officer']) : '';

if (!$studentId) {
    header('Location: students.php');
    exit;
}

$stmt = $db->prepare('SELECT id, student_id, full_name, course, department FROM students WHERE id = ?');
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    header('Location: students.php');
    exit;
}

$termsOrdered = [];
$stmt = $db->query('SELECT id, name FROM terms ORDER BY sort_order, id');
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $termsOrdered[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
}
$stmt = $db->prepare("
  SELECT e.id AS enrollment_id,
         COALESCE(isub_direct.school_year, isub_cs.school_year,
                  ay.school_year)                                  AS school_year,
         COALESCE(isub_direct.semester, isub_cs.semester,
                  ay.semester)                                     AS enroll_sem,
         s.code,
         COALESCE(NULLIF(s.description, ''), s.title)              AS description,
         s.units                                                   AS sub_units
  FROM enrollments e
  LEFT JOIN instructor_subjects isub_direct
         ON isub_direct.id = e.instructor_subject_id
  LEFT JOIN class_sections cs
         ON cs.id = e.class_section_id
  LEFT JOIN instructor_subjects isub_cs
         ON isub_cs.id = cs.instructor_subject_id
  LEFT JOIN academic_years ay
         ON ay.id = cs.academic_year_id
  JOIN subjects s
         ON s.id = COALESCE(isub_direct.subject_id, cs.subject_id)
  WHERE e.student_id = ?
  ORDER BY s.code, e.id
");
$stmt->execute([$studentId]);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$templatePath = __DIR__ . '/templates/student-grade-copy-template.xlsx';
$templateLoadedFromFile = is_readable($templatePath);
if ($templateLoadedFromFile) {
    $spreadsheet = IOFactory::load($templatePath);
} else {
    $spreadsheet = registrar_build_grade_copy_workbook();
}
$sheet = $spreadsheet->getActiveSheet();

// Match template width: registrar/templates/student-grade-copy-template.xlsx may be
// 11 cols (A–K: 3 term slots, final J, equiv K, data row 9) or 14 cols (6 terms, M/N, data row 10).
$highestColIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn(8));
$useWideLayout = ($highestColIdx >= 13);
$termSlotCount = $useWideLayout ? 6 : 3;
$displayTerms = array_slice($termsOrdered, 0, $termSlotCount);
$extraTermCount = count($termsOrdered) - $termSlotCount;
$firstTermColIdx = 7;
$finalColIdx = $firstTermColIdx + $termSlotCount;
$equivColIdx = $finalColIdx + 1;
$finalCol = Coordinate::stringFromColumnIndex($finalColIdx);
$equivCol = Coordinate::stringFromColumnIndex($equivColIdx);
$lastCol = Coordinate::stringFromColumnIndex($equivColIdx);
$dataStart = $useWideLayout ? 10 : 9;

$sheet->setCellValue('B3', $student['student_id']);
$sheet->setCellValue('B4', $student['full_name']);
$sheet->setCellValue('B5', $student['course'] ?? $student['department'] ?? '');
$sheet->setCellValue('B6', date('F j, Y'));

if ($extraTermCount > 0) {
    $sheet->setCellValue('A2', 'Note: Only the first ' . $termSlotCount . ' grading periods fit this template; contact MIS if more columns are required.');
    $sheet->mergeCells('A2:' . $lastCol . '2');
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9);
}

$rowH = 8;
for ($ti = 0; $ti < $termSlotCount; $ti++) {
    $col = Coordinate::stringFromColumnIndex($firstTermColIdx + $ti);
    $label = isset($displayTerms[$ti])
        ? ($displayTerms[$ti]['name'] . ' (%)')
        : '';
    $sheet->setCellValue($col . $rowH, $label);
}

$nEnroll = count($enrollments);
$lastDataRow = $nEnroll > 0 ? $dataStart + $nEnroll - 1 : $dataStart - 1;
$footerShift = max(0, $lastDataRow - 28);
if ($footerShift > 0 && $templateLoadedFromFile) {
    $sheet->insertNewRowBefore(29, $footerShift);
}

$row = $dataStart;
$n = 0;
foreach ($enrollments as $e) {
    $stmtTg = $db->prepare('SELECT term_id, term_grade_computed FROM term_grades WHERE enrollment_id = ?');
    $stmtTg->execute([$e['enrollment_id']]);
    $tg = [];
    while ($g = $stmtTg->fetch(PDO::FETCH_ASSOC)) {
        if ($g['term_grade_computed'] !== null && $g['term_grade_computed'] !== '') {
            $tg[(int) $g['term_id']] = (float) $g['term_grade_computed'];
        }
    }

    $finalPct = registrar_weighted_final_pct($tg);
    $equiv = !empty($tg) ? registrar_percent_to_college_grade($finalPct) : '';

    $n++;
    $sheet->setCellValue('A' . $row, $n);
    $sheet->setCellValue('B' . $row, $e['code']);
    $sheet->setCellValue('C' . $row, $e['description']);
    $units = $e['sub_units'];
    $sheet->setCellValue('D' . $row, $units !== null && $units !== '' ? (float) $units : '');
    $sheet->setCellValue('E' . $row, $e['school_year'] ?? '');
    $sheet->setCellValue('F' . $row, registrar_semester_label($e['enroll_sem'] ?? ''));

    for ($ti = 0; $ti < $termSlotCount; $ti++) {
        $col = Coordinate::stringFromColumnIndex($firstTermColIdx + $ti);
        if (!isset($displayTerms[$ti])) {
            $sheet->setCellValue($col . $row, '');
            continue;
        }
        $tid = $displayTerms[$ti]['id'];
        if (array_key_exists($tid, $tg)) {
            $sheet->setCellValue($col . $row, round($tg[$tid], 2));
        } else {
            $sheet->setCellValue($col . $row, '—');
        }
    }

    $sheet->setCellValue($finalCol . $row, !empty($tg) ? $finalPct : '—');
    $sheet->setCellValue($equivCol . $row, $equiv !== '' ? $equiv : '—');

    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $row++;
}

if ($templateLoadedFromFile) {
    registrar_grade_copy_apply_template_signatories(
        $sheet,
        $footerShift,
        $executiveDean,
        $programChairperson,
        $schoolPresident,
        $registrarOfficer
    );
} else {
    registrar_grade_copy_append_signatories(
        $sheet,
        $row,
        $executiveDean,
        $programChairperson,
        $schoolPresident,
        $registrarOfficer
    );
}

$safeId = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $student['student_id']);
$filename = 'GradeCopy_' . $safeId . '.xlsx';

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

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
