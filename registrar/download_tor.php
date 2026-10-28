<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

registrarRequireLogin();

/** Signatory lines from template tor.xlsx (Prepared by / Check by / Dean); rows shift if body rows are inserted. */
function tor_apply_signatories($sheet, int $rowsInsertedBelowTemplateBody, string $preparedBy, string $checkedBy, string $deanName): void
{
    $n = $rowsInsertedBelowTemplateBody;
    $sheet->setCellValue('D' . (161 + $n), $preparedBy);
    $sheet->setCellValue('D' . (164 + $n), $checkedBy);
    $sheet->setCellValue('H' . (167 + $n), $deanName);
}

$db = getDb();
$studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;

$preparedBy = isset($_GET['prepared_by']) ? trim((string) $_GET['prepared_by']) : '';
$checkedBy = isset($_GET['checked_by']) ? trim((string) $_GET['checked_by']) : '';
$deanName = isset($_GET['dean_name']) ? trim((string) $_GET['dean_name']) : '';

if (!$studentId) {
    header('Location: students.php');
    exit;
}

// Get student
$stmt = $db->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header('Location: students.php');
    exit;
}

// Resolve formal degree text from a department/course code.
function resolveDegreeName(array $student): string
{
    $rawCode = (string)($student['department'] ?? $student['course'] ?? '');
    $code = strtoupper(trim($rawCode));
    $code = str_replace([' ', '-', '_', '.'], '', $code);

    $degreeMap = [
        'BSCS' => 'BACHELOR OF SCIENCE IN COMPUTER SCIENCE',
        'ACT' => 'ASSOCIATE COMPUTER TECHNOLOGY',
        'BSHM' => 'BACHELOR OF SCIENCE IN HOSPITALITY MANAGEMENT',
        'BEED' => 'BACHELOR OF ELEMENTARY EDUCATION',
        'BSEDENG' => 'BACHELOR OF SECONDARY EDUCATION MAJOR IN ENGLISH',
        'BSEDFIL' => 'BACHELOR OF SECONDARY EDUCATION MAJOR IN FILIPINO',
        'BSEDMATH' => 'BACHELOR OF SECONDARY EDUCATION MAJOR IN MATH',
        'BSEDSOCSCIE' => 'BACHELOR OF SECONDARY EDUCATION MAJOR IN SOCIAL SCIENCE',
        'BSEDSOCSCI' => 'BACHELOR OF SECONDARY EDUCATION MAJOR IN SOCIAL SCIENCE',
        'BSN' => 'BACHELOR OF SCIENCE IN NURSING',
        'BSCRIM' => 'BACHELOR OF SCIENCE IN CRIMINOLOGY',
        'BSISM' => 'BACHELOR OF SCIENCE IN INFORMATION SYSTEM MANAGEMENT',
        'BSBAFM' => 'BACHELOR OF SCIENCE IN BUSINESS' ."\n" .'ADMINISTRATION MAJOR IN'."\n".'FINANCIAL MANAGEMENT',
        'BSA' => 'BACHELOR OF SCIENCE IN ACCOUNTANCY',
    ];

    return $degreeMap[$code] ?? $rawCode;
}

// Convert a 0-100 percentage to the college grade scale (1.00 = highest, 5.00 = fail).
function percentToCollegeGrade(float $pct): string
{
    if ($pct >= 99)  return '1.00';
    if ($pct >= 96)  return '1.25';
    if ($pct >= 93)  return '1.50';
    if ($pct >= 90)  return '1.75';
    if ($pct >= 87)  return '2.00';
    if ($pct >= 84)  return '2.25';
    if ($pct >= 81)  return '2.50';
    if ($pct >= 78)  return '2.75';
    if ($pct >= 75)  return '3.00';
    return '5.00';
}

// Build student name for display (show "Last, First Middle" if possible, fallback to full_name)
$lastName = isset($student['last_name']) ? trim($student['last_name']) : '';
$givenName = isset($student['given_name']) ? trim($student['given_name']) : '';
$middleName = isset($student['middle_name']) ? trim($student['middle_name']) : '';
if ($lastName !== '' && $givenName !== '') {
    $displayName = $lastName . ', ' . $givenName . ($middleName !== '' ? ' ' . $middleName : '');
} else {
    $displayName = $student['full_name'];
}

// ---------------------------------------------------------------------------
// Build a per-subject grade map for this student.
// Handles two enrollment paths:
//   1) instructor_subject_id set  -> join instructor_subjects for subject/semester
//   2) class_section_id set       -> join class_sections for subject/year_level,
//      then class_sections.instructor_subject_id or academic_years for semester
// ---------------------------------------------------------------------------
$stmt = $db->prepare("
  SELECT e.id AS enrollment_id,
         COALESCE(isub_direct.subject_id, cs.subject_id)          AS subject_id,
         COALESCE(isub_direct.school_year, isub_cs.school_year,
                  ay.school_year)                                  AS school_year,
         COALESCE(isub_direct.semester, isub_cs.semester,
                  ay.semester)                                     AS enroll_sem,
         s.code,
         COALESCE(NULLIF(s.description, ''), s.title)              AS description,
         s.units                                                   AS sub_units,
         COALESCE(cs.year_level, 0)                                AS cs_year_level
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
");
$stmt->execute([$studentId]);
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$subjectGrades = [];

foreach ($enrollments as $e) {
    $stmt2 = $db->prepare("SELECT term_id, term_grade_computed FROM term_grades WHERE enrollment_id = ?");
    $stmt2->execute([$e['enrollment_id']]);
    $tg = [];
    while ($g = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if ($g['term_grade_computed'] !== null && $g['term_grade_computed'] !== '') {
            $tg[(int)$g['term_id']] = (float)$g['term_grade_computed'];
        }
    }

    $weights   = [1 => 0.30, 2 => 0.30, 3 => 0.40];
    $sumWeight = 0.0;
    $sumValue  = 0.0;
    foreach ($tg as $tid => $val) {
        $w = $weights[$tid] ?? 0;
        $sumWeight += $w;
        $sumValue  += $val * $w;
    }
    $rawPct = $sumWeight > 0 ? $sumValue / $sumWeight : 0;

    $collegeGrade = !empty($tg) ? percentToCollegeGrade($rawPct) : '';

    $subjectGrades[(int)$e['subject_id']] = [
        'code'          => $e['code'],
        'description'   => $e['description'],
        'sub_units'     => $e['sub_units'],
        'final_grade'   => $collegeGrade,
        'school_year'   => $e['school_year'],
        'enroll_sem'    => $e['enroll_sem'],
        'cs_year_level' => (int)$e['cs_year_level'],
    ];
}

// ---------------------------------------------------------------------------
// Build ordered TOR sections grouped by year_level -> semester.
// Prefer prospectus table; fall back to enrollment + class_sections metadata.
// ---------------------------------------------------------------------------
$yearNames = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
$semNames  = [1 => '1st Semester', 2 => '2nd Semester'];

$normSem = static function ($v): int {
    $s = strtoupper(trim((string)$v));
    $s = str_replace(['-', ' '], '', $s);
    if (in_array($s, ['1', '1ST', '1STSEM', 'FIRST', 'FIRSTSEM'], true)) return 1;
    if (in_array($s, ['2', '2ND', '2NDSEM', 'SECOND', 'SECONDSEM'], true)) return 2;
    return (int)$v ?: 1;
};

$sections = []; // key = "Y-S" e.g. "1-1", value = array of rows

$prospectusUsed = false;
try {
    $pStmt = $db->query("
      SELECT p.subject_id, p.year_level, p.semester, p.units AS p_units, p.sort_order,
             s.code, s.title, s.units AS sub_units
      FROM prospectus p
      JOIN subjects s ON s.id = p.subject_id
      ORDER BY p.year_level, p.semester, p.sort_order, s.code
    ");
    $prospRows = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($prospRows)) {
        $prospectusUsed = true;
        foreach ($prospRows as $pr) {
            $sid = (int)$pr['subject_id'];
            $sg  = $subjectGrades[$sid] ?? null;
            if (!$sg) continue; // student hasn't taken this subject

            $yl  = (int)$pr['year_level'];
            $sem = (int)$pr['semester'];
            $key = $yl . '-' . $sem;

            $sections[$key]['year_level'] = $yl;
            $sections[$key]['semester']   = $sem;
            $sections[$key]['school_year'] = $sg['school_year'] ?? '';
            $sections[$key]['rows'][] = [
                'code'        => $sg['code'],
                'description' => $sg['description'],
                'final_grade' => $sg['final_grade'],
                'units'       => $pr['p_units'] ?: $sg['sub_units'],
            ];
        }
    }
} catch (Throwable $e) {
    // prospectus table might not exist
}

if (!$prospectusUsed && !empty($subjectGrades)) {
    foreach ($subjectGrades as $sid => $sg) {
        $yl  = $sg['cs_year_level'] ?: 1;
        $sem = $normSem($sg['enroll_sem']);
        $key = $yl . '-' . $sem;

        $sections[$key]['year_level']  = $yl;
        $sections[$key]['semester']    = $sem;
        $sections[$key]['school_year'] = $sg['school_year'] ?? '';
        $sections[$key]['rows'][] = [
            'code'        => $sg['code'],
            'description' => $sg['description'],
            'final_grade' => $sg['final_grade'],
            'units'       => $sg['sub_units'],
        ];
    }
}

ksort($sections);

// ---------------------------------------------------------------------------
// Load XLSX template and fill student header.
// ---------------------------------------------------------------------------
$spreadsheet = IOFactory::load(__DIR__ . '/template tor.xlsx');
$sheet = $spreadsheet->getActiveSheet();

$sheet->setCellValue('B1', $displayName);
$sheet->setCellValue('H162', $displayName);
$sheet->setCellValue('H1', $student['date_admission'] ?? '');
$sheet->setCellValue('H2', $student['course'] ?? '');
$sheet->setCellValue('B2', $student['address'] ?? '');
$sheet->setCellValue('C3', $student['preliminary_education'] ?? '');
$sheet->setCellValue('C4', $student['elementary'] ?? '');
$sheet->setCellValue('C5', $student['secondary'] ?? '');
$sheet->setCellValue('I4', $student['elem_year'] ?? '');
$sheet->setCellValue('I5', $student['sec_year'] ?? '');
$sheet->setCellValue('C6', $student['admission_credential'] ?? '');
$sheet->setCellValue('C8', $student['degree_earned'] ?? '');
$sheet->setCellValue('B13', resolveDegreeName($student));
$sheet->setCellValue('I9', date('F d, Y'));
$sheet->setCellValue('D167', date('F d, Y'));
// ---------------------------------------------------------------------------
// Clear old body area (rows 16-43) and compute how many rows we need.
// ---------------------------------------------------------------------------
$bodyStartRow   = 16;
$templateEndRow = 43;
for ($r = $bodyStartRow; $r <= $templateEndRow; $r++) {
    foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $col) {
        $sheet->setCellValue($col . $r, '');
    }
}

// Count total rows needed: 1 header per section + subject rows + 1 blank between sections.
$totalBodyRows = 0;
$sectionCount  = count($sections);
$idx = 0;
foreach ($sections as $sec) {
    $totalBodyRows += 1; // section header row
    $totalBodyRows += count($sec['rows']);
    $idx++;
    if ($idx < $sectionCount) {
        $totalBodyRows += 1; // blank separator between sections
    }
}

$availableRows = $templateEndRow - $bodyStartRow + 1;
$rowsInsertedBelowTemplateBody = 0;
if ($totalBodyRows > $availableRows) {
    $rowsInsertedBelowTemplateBody = $totalBodyRows - $availableRows;
    $sheet->insertNewRowBefore($templateEndRow + 1, $rowsInsertedBelowTemplateBody);
}

// ---------------------------------------------------------------------------
// Render sections into spreadsheet body.
// ---------------------------------------------------------------------------
$targetRow = $bodyStartRow;
$sectionIdx = 0;

foreach ($sections as $sec) {
    $yl  = $sec['year_level'];
    $sem = $sec['semester'];
    $sy  = $sec['school_year'] ?? '';

    $label = ($yearNames[$yl] ?? "Year $yl") . ' - ' . ($semNames[$sem] ?? "Sem $sem");
    if ($sy !== '') {
        $label .= ', ' . $sy;
    }

    // Section header row: merge A-F for the label
    $sheet->setCellValue('A' . $targetRow, $label);
    $sheet->mergeCells('A' . $targetRow . ':I' . $targetRow);
    $sheet->getStyle('A' . $targetRow)->getFont()->setBold(true)->setSize(10);
    $sheet->getStyle('A' . $targetRow)->getAlignment()
        ->setHorizontal(Alignment::HORIZONTAL_LEFT);
    $targetRow++;

    // Subject rows
    foreach ($sec['rows'] as $subj) {
        $sheet->setCellValue('A' . $targetRow, $subj['code']);
        $sheet->setCellValue('B' . $targetRow, $subj['description']);
        $sheet->setCellValue('G' . $targetRow, $subj['final_grade']);
        $sheet->setCellValue('H' . $targetRow, '');
        $sheet->setCellValue('I' . $targetRow, $subj['units']);
        $targetRow++;
    }

    $sectionIdx++;
    if ($sectionIdx < $sectionCount) {
        $targetRow++; // blank separator row
    }
}

// Keep degree text fixed (may have been overwritten by inserted rows).
$sheet->setCellValue('B13', resolveDegreeName($student));

tor_apply_signatories($sheet, $rowsInsertedBelowTemplateBody, $preparedBy, $checkedBy, $deanName);

// ---------------------------------------------------------------------------
// Output file.
// ---------------------------------------------------------------------------
$filename = 'TOR_' . $student['student_id'] . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$filename\"");

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;