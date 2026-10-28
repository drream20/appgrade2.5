<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require __DIR__ . '/includes/academic_year.php';
require __DIR__ . '/includes/record_date_guard.php';
require __DIR__ . '/../includes/active_academic_year.php';
require_once __DIR__ . '/includes/subject_code_lab.php';

// Attempt to load PhpSpreadsheet library before using its classes
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

use PhpOffice\PhpSpreadsheet\IOFactory;

requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();
$activeAY = getActiveAcademicYear($db);
$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : null;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : null;
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : null;

$message = '';
$err = '';

// Query lists for dropdowns
$instructorSubjects = [];
$termsList = [];
$classInfo = null;
if ($instructorSubjectId) {
    $stmt = $db->prepare("SELECT isub.id, s.code, s.title FROM instructor_subjects isub JOIN subjects s ON s.id = isub.subject_id WHERE isub.id = ? AND isub.instructor_id = ?");
    $stmt->execute([$instructorSubjectId, $instructorId]);
    $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($classInfo && instructor_subject_code_is_lab_suffix($classInfo['code'] ?? '')) {
        header('Location: ' . $baseUrl . '/records.php');
        exit;
    }
}
if ($activeAY) {
    [$aySql, $ayParams] = activeAcademicYearMatch('isub.academic_year_id', 'isub.school_year', 'isub.semester', $activeAY);
    $stmt = $db->prepare("
      SELECT isub.id as instructor_subject_id, s.code, s.title
      FROM instructor_subjects isub
      JOIN subjects s ON s.id = isub.subject_id
      WHERE isub.instructor_id = ?
        AND $aySql
      ORDER BY s.code
    ");
    $stmt->execute(array_merge([$instructorId], $ayParams));
    $instructorSubjects = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static function ($row) {
            return !instructor_subject_code_is_lab_suffix($row['code'] ?? '');
        }
    ));
}
$stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];
if (!$classInfo) $classInfo = null;

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instructorSubjectId = isset($_POST['instructor_subject_id']) ? (int)$_POST['instructor_subject_id'] : $instructorSubjectId;
    $termId = isset($_POST['term_id']) ? (int)$_POST['term_id'] : $termId;
    $sectionId = isset($_POST['section_id']) ? (int)$_POST['section_id'] : $sectionId;
    $stmt = $db->prepare("SELECT isub.id, s.code, s.title FROM instructor_subjects isub JOIN subjects s ON s.id = isub.subject_id WHERE isub.id = ? AND isub.instructor_id = ?");
    $stmt->execute([$instructorSubjectId, $instructorId]);
    $classInfo = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $classInfo && $termId) {
    if (instructor_subject_code_is_lab_suffix($classInfo['code'] ?? '')) {
        $err = 'Excel upload is not available for laboratory subjects (codes ending in L). Use the lecture class in Class Records.';
    } elseif (!$activeAY) {
        $err = 'No active semester. Contact MIS to activate an academic year.';
    } else {
        $stmt = $db->prepare("SELECT instructor_id, academic_year_id, school_year, semester FROM instructor_subjects WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$instructorSubjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $isActiveTerm =
            $row &&
            ((int)($row['academic_year_id'] ?? 0) === (int)$activeAY['id'] ||
             (empty($row['academic_year_id']) && ($row['school_year'] ?? null) === ($activeAY['school_year'] ?? null) && ($row['semester'] ?? null) === ($activeAY['semester'] ?? null)));
        if (!$isActiveTerm || (int)($row['instructor_id'] ?? 0) !== (int)$instructorId) {
            $err = 'This class is not part of the active semester.';
        } elseif (!canRecordForInstructorSubject($db, (int)$instructorSubjectId)) {
            $err = 'Recording is disabled. This semester is locked.';
        }
    }
    // File validation and import
    if (!$err) {
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $err = 'Please select an Excel (.xlsx) file.';
        } else {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $err = 'Please upload the Excel (.xlsx) file downloaded from "Download this term".';
            } elseif (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
                $err = 'Spreadsheet import library is not available.';
            } else {
                // Utilities
                $columnIndexToLetter = function (int $index): string {
                    $letter = '';
                    while ($index > 0) {
                        $mod = ($index - 1) % 26;
                        $letter = chr(65 + $mod) . $letter;
                        $index = (int)(($index - $mod) / 26);
                    }
                    return $letter;
                };
                $normalizeText = function ($value): string {
                    if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $value = $value->getPlainText();
                    $value = str_replace("\xC2\xA0", ' ', (string)$value); // non-breaking space -> space
                    $value = trim($value);
                    $value = preg_replace('/\s+/u', ' ', $value);
                    return $value ?? '';
                };
                $normalizeNumeric = function ($value): ?float {
                    if ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) $value = $value->getPlainText();
                    if ($value === null) return null;
                    if (is_string($value)) {
                        $value = trim($value);
                        if ($value === '') {
                            return null;
                        }
                        $value = str_replace(',', '.', $value);
                    }
                    if (!is_numeric($value)) return null;
                    return (float)$value;
                };

                try {
                    $spreadsheet = IOFactory::load($_FILES['file']['tmp_name']);
                    $sheet = $spreadsheet->getSheet(0);
                } catch (Throwable $e) {
                    $sheet = null;
                    $err = 'Unable to read Excel file. Please make sure you uploaded the file from "Download this term".';
                }
                if ($sheet) {
                    // --- Roster
                    if ($sectionId) {
                        $stmtE = $db->prepare("
                            SELECT e.id as enrollment_id, st.full_name
                            FROM enrollments e INNER JOIN students st ON st.id = e.student_id
                            WHERE e.class_section_id = ?
                        ");
                        $stmtE->execute([$sectionId]);
                    } else {
                        $stmtE = $db->prepare("
                            SELECT e.id as enrollment_id, st.full_name
                            FROM enrollments e INNER JOIN students st ON st.id = e.student_id
                            WHERE e.instructor_subject_id = ?
                               OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?)
                        ");
                        $stmtE->execute([$instructorSubjectId, $instructorSubjectId]);
                    }
                    $students = $stmtE->fetchAll(PDO::FETCH_ASSOC);
                    $nameToEnrollment = [];
                    foreach ($students as $s) {
                        $key = mb_strtoupper($normalizeText($s['full_name'] ?? ''), 'UTF-8');
                        if ($key !== '') {
                            $nameToEnrollment[$key] = (int)$s['enrollment_id'];
                        }
                    }
                    // --- Load activities database
                    $stmtA = $db->prepare("
                        SELECT id, record_type, title, max_score, activity_date
                        FROM assessment_activities
                        WHERE instructor_subject_id = ?
                          AND term_id = ?
                        ORDER BY activity_date, id
                    ");
                    $stmtA->execute([$instructorSubjectId, $termId]);
                    $activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);

                    // Collect exam activity
                    $examActivity = null;
                    foreach ($activities as $a) {
                        if (strtoupper(trim((string)$a['record_type'])) === 'EXAM') {
                            $examActivity = $a;
                        }
                    }
                    // Section filter (as in download_record.php)
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
                            $stmtFilter->execute(array_merge($enrollmentIds, [$termId]));
                            $allowedActivityIds = $stmtFilter->fetchAll(PDO::FETCH_COLUMN, 0);
                            if (!empty($allowedActivityIds)) {
                                $activities = array_values(array_filter(
                                    $activities,
                                    static function ($a) use ($allowedActivityIds) {
                                        return in_array((int)$a['id'], array_map('intval', $allowedActivityIds), true);
                                    }
                                ));
                            } else {
                                $activities = [];
                            }
                        }
                    }
                    // Only non-EXAM, non-VALUES
                    $columnActivities = [];
                    foreach ($activities as $a) {
                        $rt = strtoupper(trim((string)$a['record_type']));
                        if ($rt === 'EXAM' || $rt === 'VALUES') continue;
                        $columnActivities[] = $a;
                    }

                    // Sheet-determined exam max
                    $examMax = null;
                    if ($examActivity && (float)($examActivity['max_score'] ?? 0) > 0) {
                        $examMax = (float)$examActivity['max_score'];
                    } else {
                        $examMaxCell = $sheet->getCell('AF8')->getCalculatedValue();
                        if (is_numeric($examMaxCell) && (float)$examMaxCell > 0) {
                            $examMax = (float)$examMaxCell;
                        }

                    }
                    if ($examMax === null || $examMax <= 0) $examMax = 100.0;

                    if (empty($nameToEnrollment)) {
                        $err = 'No enrolled students found for this class.';
                    } else {
                        $updated = 0;
                        $unmatched = 0;
                        try {
                            $db->beginTransaction();

                            // Activity short codes mapping
                            $activityShortTypeMap = [
                                'GA' => 'class_participation',
                                'P'  => 'project',
                                'AS' => 'assignment',
                                'EX' => 'exercise',
                                'Q'  => 'quiz',
                            ];

                            $insActivity = $db->prepare("
                                INSERT INTO assessment_activities (instructor_subject_id, term_id, record_type, title, max_score, activity_date)
                                VALUES (?,?,?,?,?,?)
                            ");
                            $updActivityTitle = $db->prepare("UPDATE assessment_activities SET title = ? WHERE id = ? AND instructor_subject_id = ?");
                            $updActivityMax = $db->prepare("UPDATE assessment_activities SET max_score = ? WHERE id = ? AND instructor_subject_id = ?");

                            // Activity columns from template header
                            $activityColumns = [];
                            for ($colIndex = 8; $colIndex <= 27; $colIndex++) { // H..AA
                                $colLetter = $columnIndexToLetter($colIndex);
                                $slot = $colIndex - 8;
                                $dbAct = $columnActivities[$slot] ?? null;
                                // Header type code
                                $headerShort = strtoupper($normalizeText($sheet->getCell($colLetter . '8')->getCalculatedValue()));
                                // If header is blank (i.e., no code), skip this column for assessment import
                                if ($headerShort === '' || !isset($activityShortTypeMap[$headerShort])) {
                                    continue;
                                }
                                // max score must be > 0
                                $maxRaw = $sheet->getCell($colLetter . '9')->getCalculatedValue();
                                $maxScore = $normalizeNumeric($maxRaw) ?? 0.0;
                                if ($maxScore <= 0) continue;

                                // Record type to insert
                                $recordType = $dbAct ? (string)($dbAct['record_type'] ?? $activityShortTypeMap[$headerShort]) : $activityShortTypeMap[$headerShort];
                                // Title fallback: blank is normal
                                $title = (string)($dbAct['title'] ?? '');
                                if ($title === '') {
                                    $title = 'Assessment ' . ($slot + 1);
                                }
                                $activityDate = !empty($dbAct['activity_date']) ? (string)$dbAct['activity_date'] : date('Y-m-d');
                                $activityDate = substr(trim($activityDate), 0, 10);
                                if (!record_date_is_not_future($activityDate)) {
                                    $activityDate = date('Y-m-d');
                                }
                                $activityId = (int)($dbAct['id'] ?? 0);
                                // If not found in DB, create it
                                if ($activityId <= 0) {
                                    $insActivity->execute([
                                        $instructorSubjectId,
                                        $termId,
                                        $recordType,
                                        $title,
                                        $maxScore,
                                        date('Y-m-d'),
                                    ]);
                                    $activityId = (int)$db->lastInsertId();
                                } else {
                                    if ($title !== '' && $title !== (string)($dbAct['title'] ?? '')) {
                                        $updActivityTitle->execute([$title, $activityId, $instructorSubjectId]);
                                    }
                                    if (abs($maxScore - (float)($dbAct['max_score'] ?? 0)) > 0.000001) {
                                        $updActivityMax->execute([$maxScore, $activityId, $instructorSubjectId]);
                                    }
                                }
                                $activityColumns[] = [
                                    'activity_id'   => $activityId,
                                    'record_type'   => $recordType,
                                    'title'         => $title,
                                    'max_score'     => $maxScore,
                                    'activity_date' => $activityDate,
                                    'col'           => $colLetter,
                                ];
                            }

                            // Prepare assessment record statements
                            $selectAssessment = $db->prepare("
                                SELECT id FROM assessment_records
                                WHERE enrollment_id = ? AND term_id = ? AND activity_id = ?
                            ");
                            $updateAssessment = $db->prepare("
                                UPDATE assessment_records
                                SET score = ?, max_score = ?, record_type = ?, title = ?, recorded_at = ?
                                WHERE id = ?
                            ");
                            $insertAssessment = $db->prepare("
                                INSERT INTO assessment_records
                                    (enrollment_id, term_id, activity_id, record_type, score, max_score, title, recorded_at)
                                VALUES (?,?,?,?,?,?,?,?)
                            ");

                            // Attendance
                            $insertAttendance = $db->prepare("
                                INSERT INTO attendance_records (enrollment_id, term_id, attendance_date, status)
                                VALUES (?,?,?,?)
                                ON DUPLICATE KEY UPDATE status = VALUES(status)
                            ");

                            // Exam activity (always present for import)
                            $stmtExamRec = $db->prepare("
                                SELECT id, record_type, title, max_score
                                FROM assessment_activities
                                WHERE instructor_subject_id = ? AND term_id = ? AND UPPER(record_type) = 'EXAM'
                                LIMIT 1
                            ");
                            $stmtExamRec->execute([$instructorSubjectId, $termId]);
                            $examDbActivity = $stmtExamRec->fetch(PDO::FETCH_ASSOC);
                            if (!$examDbActivity) {
                                $insActivity->execute([
                                    $instructorSubjectId,
                                    $termId,
                                    'exam',
                                    'Exam',
                                    $examMax,
                                    date('Y-m-d'),
                                ]);
                                $examDbActivity = [
                                    'id' => (int)$db->lastInsertId(),
                                    'record_type' => 'exam',
                                    'title' => 'Exam',
                                    'max_score' => $examMax,
                                ];
                            } else {
                                if (abs(($examDbActivity['max_score'] ?? 0) - $examMax) > 0.000001) {
                                    $updActivityMax->execute([$examMax, (int)$examDbActivity['id'], $instructorSubjectId]);
                                    $examDbActivity['max_score'] = $examMax;
                                }
                            }
                            $selectExamAssessment = $db->prepare("
                                SELECT id FROM assessment_records
                                WHERE enrollment_id = ? AND term_id = ? AND activity_id = ?
                            ");
                            $updateExamAssessment = $db->prepare("
                                UPDATE assessment_records
                                SET score = ?, max_score = ?, record_type = ?, title = ?, recorded_at = ?
                                WHERE id = ?
                            ");
                            $insertExamAssessment = $db->prepare("
                                INSERT INTO assessment_records
                                    (enrollment_id, term_id, activity_id, record_type, score, max_score, title, recorded_at)
                                VALUES (?,?,?,?,?,?,?,?)
                            ");

                            // Values
                            $valuesActivities = array_values(array_filter(
                                $activities,
                                static function ($a) {
                                    return strtoupper(trim((string)$a['record_type'])) === 'VALUES';
                                }
                            ));
                            $valuesMaxFromSheet = $normalizeNumeric($sheet->getCell('AD9')->getCalculatedValue()) ?? 0.0;
                            if (empty($valuesActivities) && $valuesMaxFromSheet > 0) {
                                $insActivity->execute([
                                    $instructorSubjectId,
                                    $termId,
                                    'values',
                                    'Values',
                                    $valuesMaxFromSheet,
                                    date('Y-m-d'),
                                ]);
                                $valuesActivities = [[
                                    'id' => (int)$db->lastInsertId(),
                                    'record_type' => 'values',
                                    'title' => 'Values',
                                    'max_score' => $valuesMaxFromSheet,
                                ]];
                            } elseif (!empty($valuesActivities) && $valuesMaxFromSheet > 0) {
                                if (count($valuesActivities) === 1) {
                                    if (abs($valuesMaxFromSheet - (float)($valuesActivities[0]['max_score'] ?? 0)) > 0.000001) {
                                        $updActivityMax->execute([(float)$valuesMaxFromSheet, (int)$valuesActivities[0]['id'], $instructorSubjectId]);
                                    }
                                    $valuesActivities[0]['max_score'] = $valuesMaxFromSheet;
                                } else {
                                    $currentTotal = 0.0;
                                    foreach ($valuesActivities as $vAct) {
                                        $currentTotal += (float)($vAct['max_score'] ?? 0);
                                    }
                                    if ($currentTotal > 0) {
                                        foreach ($valuesActivities as $i => $vAct) {
                                            $scaled = ((float)($vAct['max_score'] ?? 0) / $currentTotal) * $valuesMaxFromSheet;
                                            if (abs($scaled - (float)($vAct['max_score'] ?? 0)) > 0.000001) {
                                                $updActivityMax->execute([$scaled, (int)$vAct['id'], $instructorSubjectId]);
                                            }
                                            $valuesActivities[$i]['max_score'] = $scaled;
                                        }
                                    }
                                }
                            }

                            // Student loop
                            for ($rowIndex = 10; $rowIndex <= 59; $rowIndex++) {
                                $fullName = $normalizeText($sheet->getCell('B' . $rowIndex)->getCalculatedValue());
                                if ($fullName === '') continue;
                                $lookup = mb_strtoupper($fullName, 'UTF-8');
                                $enrollmentId = $nameToEnrollment[$lookup] ?? null;
                                if (!$enrollmentId) {
                                    $unmatched++;
                                    continue;
                                }
                                // Per-activity columns
                                foreach ($activityColumns as $ac) {
                                    $cellValue = $sheet->getCell($ac['col'] . $rowIndex)->getCalculatedValue();
                                    $score = $normalizeNumeric($cellValue);
                                    // If assessment score is missing or blank, skip (don't create record at all)
                                    if ($score === null || $score === '') continue;

                                    // assessment_records insert/update
                                    $selectAssessment->execute([$enrollmentId, $termId, $ac['activity_id']]);
                                    $existing = $selectAssessment->fetch(PDO::FETCH_ASSOC);

                                    if ($existing) {
                                        $updateAssessment->execute([
                                            $score,
                                            $ac['max_score'],
                                            $ac['record_type'],
                                            $ac['title'],
                                            date('Y-m-d H:i:s'),
                                            $existing['id'],
                                        ]);
                                    } else {
                                        $insertAssessment->execute([
                                            $enrollmentId,
                                            $termId,
                                            $ac['activity_id'],
                                            $ac['record_type'],
                                            $score,
                                            $ac['max_score'],
                                            $ac['title'],
                                            date('Y-m-d H:i:s'),
                                        ]);
                                    }
                                }
                                // Values total from column AD
                                if (!empty($valuesActivities)) {
                                    $valuesCell = $sheet->getCell('AD' . $rowIndex)->getCalculatedValue();
                                    $valuesScore = $normalizeNumeric($valuesCell);
                                    if ($valuesScore !== null) {
                                        if (count($valuesActivities) === 1) {
                                            $vAct = $valuesActivities[0];
                                            $selectAssessment->execute([$enrollmentId, $termId, (int)$vAct['id']]);
                                            $existing = $selectAssessment->fetch(PDO::FETCH_ASSOC);
                                            $vMax = (float)($vAct['max_score'] ?? 0);
                                            if ($existing) {
                                                $updateAssessment->execute([
                                                    $valuesScore,
                                                    $vMax,
                                                    'values',
                                                    (string)($vAct['title'] ?? 'Values'),
                                                    date('Y-m-d H:i:s'),
                                                    $existing['id'],
                                                ]);
                                            } else {
                                                $insertAssessment->execute([
                                                    $enrollmentId,
                                                    $termId,
                                                    (int)$vAct['id'],
                                                    'values',
                                                    $valuesScore,
                                                    $vMax,
                                                    (string)($vAct['title'] ?? 'Values'),
                                                    date('Y-m-d H:i:s'),
                                                ]);
                                            }
                                        } else {
                                            $totalMax = 0.0;
                                            foreach ($valuesActivities as $vAct) {
                                                $totalMax += (float)($vAct['max_score'] ?? 0);
                                            }
                                            if ($totalMax > 0) {
                                                foreach ($valuesActivities as $vAct) {
                                                    $portion = ((float)($vAct['max_score'] ?? 0) / $totalMax) * $valuesScore;
                                                    $selectAssessment->execute([$enrollmentId, $termId, (int)$vAct['id']]);
                                                    $existing = $selectAssessment->fetch(PDO::FETCH_ASSOC);
                                                    $vMax = (float)($vAct['max_score'] ?? 0);
                                                    if ($existing) {
                                                        $updateAssessment->execute([
                                                            $portion,
                                                            $vMax,
                                                            'values',
                                                            (string)($vAct['title'] ?? 'Values'),
                                                            date('Y-m-d H:i:s'),
                                                            $existing['id'],
                                                        ]);
                                                    } else {
                                                        $insertAssessment->execute([
                                                            $enrollmentId,
                                                            $termId,
                                                            (int)$vAct['id'],
                                                            'values',
                                                            $portion,
                                                            $vMax,
                                                            (string)($vAct['title'] ?? 'Values'),
                                                            date('Y-m-d H:i:s'),
                                                        ]);
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                // Attendance percentage from column AE
                                $attCell = $sheet->getCell('AE' . $rowIndex)->getCalculatedValue();
                                $pct = $normalizeNumeric($attCell);
                                if ($pct !== null) {
                                    $pct = max(0, min(100, $pct));
                                    $nPresent = (int)round(20 * $pct / 100);
                                    for ($d = 0; $d < 20; $d++) {
                                        $date = date('Y-m-d', strtotime("-$d days"));
                                        $status = $d < $nPresent ? 'present' : 'absent';
                                        $insertAttendance->execute([$enrollmentId, $termId, $date, $status]);
                                    }
                                }
                                // Exam from column AF
                                $examCell = $sheet->getCell('AF' . $rowIndex)->getCalculatedValue();
                                $examScore = $normalizeNumeric($examCell);
                                if ($examScore !== null && $examDbActivity && $examDbActivity['id']) {
                                    $activityId = (int)$examDbActivity['id'];
                                    $selectExamAssessment->execute([$enrollmentId, $termId, $activityId]);
                                    $existingExam = $selectExamAssessment->fetch(PDO::FETCH_ASSOC);
                                    if ($existingExam) {
                                        $updateExamAssessment->execute([
                                            $examScore,
                                            $examDbActivity['max_score'],
                                            $examDbActivity['record_type'],
                                            $examDbActivity['title'],
                                            date('Y-m-d H:i:s'),
                                            $existingExam['id'],
                                        ]);
                                    } else {
                                        $insertExamAssessment->execute([
                                            $enrollmentId,
                                            $termId,
                                            $activityId,
                                            $examDbActivity['record_type'],
                                            $examScore,
                                            $examDbActivity['max_score'],
                                            $examDbActivity['title'],
                                            date('Y-m-d H:i:s'),
                                        ]);
                                    }
                                }
                                // Recompute overall grade immediately after importing all assessments
                                recomputeTermGrade($db, $enrollmentId, $termId);
                                $updated++;
                            }
                            $db->commit();

                            if ($updated > 0) {
                                if ($unmatched > 0) {
                                    $message = "Imported/updated $updated students from template. $unmatched row(s) were skipped because the student name was not found.";
                                } else {
                                    $message = "Imported/updated $updated students from the Excel template.";
                                }
                            } else {
                                $err = 'No matching students were found in the uploaded file.';
                            }
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            error_log('excel_upload: ' . $e->getMessage());
                            $err = 'Upload failed. Please verify you used the downloaded template and try again.';
                        }
                    }
                }
            }
        }
    }
}

// --- UI
$pageTitle = 'Upload Excel';
$currentPage = 'records';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Upload class record (Excel)</h4>
  <!-- <a href="<?= $baseUrl ?>/records.php" class="btn btn-outline-secondary btn-sm">Back to records</a> -->
</div>

<?php if ($message): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <p class="text-muted">
      Upload the <strong>same Excel (.xlsx) file</strong> you downloaded from
      <strong>"Download this term"</strong>. Do not change the header rows or layout;
      just edit the scores, attendance %, and exam scores in the cells.
    </p>
    <form method="post" enctype="multipart/form-data" class="row g-3">
      <?php if (!$instructorSubjectId || !$termId): ?>
      <div class="col-md-6">
        <label class="form-label">Subject</label>
        <select name="instructor_subject_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($instructorSubjects as $is): ?>
            <option value="<?= $is['instructor_subject_id'] ?>" <?= $instructorSubjectId === (int)$is['instructor_subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars($is['code'] . ' - ' . $is['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select" required>
          <option value="">-- Select --</option>
          <?php foreach ($termsList as $tid => $tname): ?>
            <option value="<?= $tid ?>" <?= $termId === (int)$tid ? 'selected' : '' ?>><?= htmlspecialchars($tname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="instructor_subject_id" value="<?= (int)$instructorSubjectId ?>">
      <input type="hidden" name="term_id" value="<?= (int)$termId ?>">
      <?php if ($sectionId): ?>
      <input type="hidden" name="section_id" value="<?= (int)$sectionId ?>">
      <?php endif; ?>
      <?php endif; ?>
      <div class="col-12">
        <label class="form-label">Excel file (.xlsx)</label>
        <input type="file" name="file" class="form-control" accept=".xlsx" required>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>
    <hr>
    <p class="small mb-0">
      First, go to <strong>Class Records</strong> and click <strong>"Download this term"</strong>.
      After updating scores in that Excel file, upload it here without changing the structure.
    </p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
