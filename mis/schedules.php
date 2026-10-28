<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$message = '';
$error = '';

// Instructors cache for searching and ajaxless search

$instructors = [];
$stmt = $db->query("SELECT id, full_name, email FROM instructors ORDER BY full_name ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $instructors[] = $row;

// Subjects cache for searching
$subjects = [];
$stmt = $db->query("SELECT id, code, description FROM subjects ORDER BY code ASC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $subjects[] = $row;

// --- Handle edit assigned instructor in schedule slot ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'edit_schedule_instructor') {
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        $instructorId = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? (int)$_POST['instructor_id'] : null;
        if ($scheduleId && $instructorId !== null) {
            $stmt = $db->prepare("SELECT sch.id, sch.instructor_subject_id, isub.subject_id, isub.school_year, isub.semester, isub.academic_year_id, sch.section_name
                FROM schedule sch
                JOIN instructor_subjects isub ON sch.instructor_subject_id = isub.id
                WHERE sch.id = ?");
            $stmt->execute([$scheduleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stmt2 = $db->prepare("SELECT id FROM instructor_subjects WHERE instructor_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
                $stmt2->execute([$instructorId, $row['subject_id'], $row['school_year'], $row['semester']]);
                $isub = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$isub) {
                    $stmtI = $db->prepare("INSERT INTO instructor_subjects (instructor_id, subject_id, school_year, semester, academic_year_id) VALUES (?,?,?,?,?)");
                    $stmtI->execute([$instructorId, $row['subject_id'], $row['school_year'], $row['semester'], $row['academic_year_id']]);
                    $newIsubId = (int)$db->lastInsertId();
                } else {
                    $newIsubId = (int)$isub['id'];
                }
                $stmtU = $db->prepare("UPDATE schedule SET instructor_subject_id = ? WHERE id = ?");
                $stmtU->execute([$newIsubId, $scheduleId]);
                logActivity('mis', currentMisUserId(), 'edit_schedule_instructor', $scheduleId, null, "Schedule $scheduleId assigned instructor_subject_id $newIsubId");
                $message = 'Schedule instructor updated.';
            } else {
                $error = 'Schedule slot not found.';
            }
        } elseif ($scheduleId && $instructorId === null) {
            $stmt = $db->prepare("SELECT sch.id, isub.subject_id, isub.school_year, isub.semester, isub.academic_year_id FROM schedule sch JOIN instructor_subjects isub ON sch.instructor_subject_id = isub.id WHERE sch.id = ?");
            $stmt->execute([$scheduleId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stmt2 = $db->prepare("SELECT id FROM instructor_subjects WHERE instructor_id IS NULL AND subject_id = ? AND school_year = ? AND semester = ?");
                $stmt2->execute([ $row['subject_id'], $row['school_year'], $row['semester'] ]);
                $isub = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$isub) {
                    $stmtI = $db->prepare("INSERT INTO instructor_subjects (instructor_id, subject_id, school_year, semester, academic_year_id) VALUES (NULL,?,?,?,?)");
                    $stmtI->execute([$row['subject_id'], $row['school_year'], $row['semester'], $row['academic_year_id']]);
                    $newIsubId = (int)$db->lastInsertId();
                } else {
                    $newIsubId = (int)$isub['id'];
                }
                $stmtU = $db->prepare("UPDATE schedule SET instructor_subject_id = ? WHERE id = ?");
                $stmtU->execute([$newIsubId, $scheduleId]);
                logActivity('mis', currentMisUserId(), 'edit_schedule_instructor', $scheduleId, null, "Schedule $scheduleId unassigned instructor");
                $message = 'Schedule instructor unassigned.';
            } else {
                $error = 'Schedule slot not found.';
            }
        } else {
            $error = 'Invalid request.';
        }
    } elseif ($_POST['action'] === 'edit_class_section_instructor') {
        $sectionId = (int)($_POST['section_id'] ?? 0);
        $instructorId = isset($_POST['instructor_id']) && $_POST['instructor_id'] !== '' ? (int)$_POST['instructor_id'] : null;
        if ($sectionId && $instructorId !== null) {
            $stmt = $db->prepare("SELECT cs.id, cs.subject_id, ay.school_year, ay.semester, cs.section, cs.academic_year_id 
                FROM class_sections cs 
                JOIN academic_years ay ON cs.academic_year_id = ay.id
                WHERE cs.id = ?");
            $stmt->execute([$sectionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stmt2 = $db->prepare("SELECT id FROM instructor_subjects WHERE instructor_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
                $stmt2->execute([$instructorId, $row['subject_id'], $row['school_year'], $row['semester']]);
                $isub = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$isub) {
                    $stmtI = $db->prepare("INSERT INTO instructor_subjects (instructor_id, subject_id, school_year, semester, academic_year_id) VALUES (?,?,?,?,?)");
                    $stmtI->execute([$instructorId, $row['subject_id'], $row['school_year'], $row['semester'], $row['academic_year_id']]);
                    $newIsubId = (int)$db->lastInsertId();
                } else {
                    $newIsubId = (int)$isub['id'];
                }
                $stmtU = $db->prepare("UPDATE class_sections SET instructor_subject_id = ? WHERE id = ?");
                $stmtU->execute([$newIsubId, $sectionId]);
                logActivity('mis', currentMisUserId(), 'edit_class_section_instructor', $sectionId, null, "Section $sectionId assigned instructor_subject_id $newIsubId");
                $message = 'Class section instructor assigned.';
            } else {
                $error = 'Class section not found.';
            }
        } elseif ($sectionId && $instructorId === null) {
            $stmt = $db->prepare("UPDATE class_sections SET instructor_subject_id = NULL WHERE id = ?");
            $stmt->execute([$sectionId]);
            logActivity('mis', currentMisUserId(), 'edit_class_section_instructor', $sectionId, null, "Section $sectionId instructor assignment removed");
            $message = 'Class section instructor unassigned.';
        } else {
            $error = 'Invalid section request.';
        }
    }
}

// ----------- REWRITE OF UPLOAD SCHEDULE PART BEGINS HERE -----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_schedule') {
    $activeAY = null;
    $stmt = $db->query("SELECT id, school_year, semester FROM academic_years WHERE is_active = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $activeAY = $row;

    if (!$activeAY) {
        $error = 'No active Academic Year.';
    } elseif (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        $error = 'Please select a CSV file.';
    } else {
        $path = $_FILES['file']['tmp_name'];
        $rows = [];
        if (($h = fopen($path, 'r')) !== false) {
            $bom = fread($h, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($h);
            while (($r = fgetcsv($h)) !== false) $rows[] = $r;
            fclose($h);
        }
        if (count($rows) < 2) {
            $error = 'CSV must have header row and at least one data row.';
        } else {
            $header = array_map('trim', array_map('strtolower', $rows[0]));
            $col = function($key, $header) {
                $index = array_search(strtolower($key), $header);
                return $index !== false ? $index : null;
            };

            $dayCol        = $col('day', $header);
            $startCol      = $col('start', $header);
            $endCol        = $col('finish', $header);
            $roomCol       = $col('rm', $header);
            $codeCol       = $col('code', $header);
            $descCol       = $col('description', $header);
            $unitsCol      = $col('units', $header);
            if ($unitsCol === null) {
                $unitsCol = $col('unit', $header);
            }
            $facultyCol    = $col('faculty', $header);
            $cysCol        = $col('cys', $header);

            $errors = [];
            $hasSubjectsUnitsColumn = false;
            try {
                $unitsColStmt = $db->query("SHOW COLUMNS FROM subjects LIKE 'units'");
                $hasSubjectsUnitsColumn = (bool)$unitsColStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $hasSubjectsUnitsColumn = false;
            }

            // Day mapping, Mon-Sat only
            $dayCharMap = [
                'M'  => 1,
                'T'  => 2,
                'W'  => 3,
                'TH' => 4,
                'F'  => 5,
                'S'  => 6
            ];
            $dayMap = [
                'm'       => 1, 'mon'      => 1, 'monday'    => 1,
                't'       => 2, 'tue'      => 2, 'tuesday'   => 2,
                'w'       => 3, 'wed'      => 3, 'wednesday' => 3,
                'th'      => 4, 'thu'      => 4, 'thursday'  => 4,
                'f'       => 5, 'fri'      => 5, 'friday'    => 5,
                's'       => 6, 'sat'      => 6, 'saturday'  => 6
            ];

            // Utility functions for subject code normalization per instruction
            function normalize_schedule_course_key($raw) {
                return strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$raw));
            }
            function normalize_schedule_course_from_section($sectionName) {
                $s = strtoupper(trim((string)$sectionName));
                if ($s === '' || $s === 'N/A') return '';
                $s = preg_replace('/^\s*\d+\s*/', '', $s);
                $parts = preg_split('/[-\s]/', $s, 2);
                $course = $parts[0] ?? '';
                $courseKey = normalize_schedule_course_key($course);
                if (strlen($courseKey) < 3) return '';
                return $courseKey;
            }
            function normalize_schedule_code($codeRaw, $sectionName = '') {
                $codeRaw = trim((string)$codeRaw);
                if ($codeRaw === '') return '';

                $dashPos = strpos($codeRaw, '-');
                $prefixCourse = null;
                $code = $codeRaw;
                if ($dashPos !== false) {
                    $prefixCourse = substr($codeRaw, 0, $dashPos);
                    $code = substr($codeRaw, $dashPos + 1);
                }

                $code = strtoupper(trim($code));
                $courseKey = normalize_schedule_course_key($prefixCourse ?: normalize_schedule_course_from_section($sectionName));
                $codeKeyNoSpace = strtoupper(preg_replace('/\s+/', '', $code));

                if (preg_match('/^EDPCK(\d+)$/i', $codeKeyNoSpace, $m)) {
                    if ($courseKey === 'BEED' || strpos($courseKey, 'BSED') === 0) {
                        $code = 'ED/PCK' . $m[1];
                    }
                }

                return $code;
            }
            function normalize_schedule_code_no_space($codeRaw, $sectionName = '') {
                $normalized = normalize_schedule_code($codeRaw, $sectionName);
                return strtoupper(preg_replace('/\s+/', '', $normalized));
            }

            // Time conversion helper per prompt
            function normalize_time_24h($timeStr) {
                $timeStr = trim($timeStr);
                if ($timeStr === "") return "";
                // Remove whitespace and fix edge cases
                $timeStr = preg_replace('/\s+/', ' ', $timeStr);

                // If already in HH:MM:SS or HH:MM or H:MM format and NOT with AM/PM, return as is in HH:MM:SS
                if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $timeStr)) {
                    // Append ":00" if just HH:MM
                    if (!preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $timeStr)) {
                        $timeStr = $timeStr . ":00";
                    }
                    return $timeStr;
                }

                // If time is like 1:00:00 PM or 01:00:00 PM or 1:00 PM etc.
                if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([APap][Mm])$/', $timeStr, $m)) {
                    $hour = (int)$m[1];
                    $min = (int)$m[2];
                    $sec = isset($m[3]) && $m[3] !== "" ? (int)$m[3] : 0;
                    $ampm = strtoupper($m[4]);
                    if ($ampm == "PM" && $hour !== 12) $hour += 12;
                    if ($ampm == "AM" && $hour == 12) $hour = 0;
                    return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
                }
                // If time is like 1:00 PM w/o seconds
                if (preg_match('/^(\d{1,2}):(\d{2})\s*([APap][Mm])$/', $timeStr, $m)) {
                    $hour = (int)$m[1];
                    $min = (int)$m[2];
                    $sec = 0;
                    $ampm = strtoupper($m[3]);
                    if ($ampm == "PM" && $hour !== 12) $hour += 12;
                    if ($ampm == "AM" && $hour == 12) $hour = 0;
                    return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
                }
                // If time is like 1 PM (no minutes)
                if (preg_match('/^(\d{1,2})\s*([APap][Mm])$/', $timeStr, $m)) {
                    $hour = (int)$m[1];
                    $min = 0;
                    $sec = 0;
                    $ampm = strtoupper($m[2]);
                    if ($ampm == "PM" && $hour !== 12) $hour += 12;
                    if ($ampm == "AM" && $hour == 12) $hour = 0;
                    return sprintf('%02d:%02d:%02d', $hour, $min, $sec);
                }
                // If time is like 13:00:00 pm (bad input), just use as is, force lowercase/uppercase and parse using date
                if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?\s*[APap][Mm]$/', $timeStr)) {
                    return date('H:i:s', strtotime($timeStr));
                }
                // Fallback: try parsing with strtotime and return H:i:s if possible
                $parsed = strtotime($timeStr);
                if ($parsed !== false) return date("H:i:s", $parsed);
                return $timeStr; // fallback, original
            }

            // --- BEGIN extended logic: keep mapping lecture subject to lab subject ---
            // Map subject codes (lecture->lab) found in schedule
            $lectureToLabCodes = []; // 'CSA104' => 'CSA104L'
            $labToLectureCodes = []; // 'CSA104L' => 'CSA104'
            $subjectCodesInCSV = [];

            // Collect all codes in CSV, find lecture/lab pairs
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $codeRaw = $codeCol !== null ? (string)($r[$codeCol] ?? '') : '';
                $sectionName = $cysCol !== null ? trim((string)($r[$cysCol] ?? '')) : '';
                if ($sectionName === '') $sectionName = 'N/A';

                $codeNorm = normalize_schedule_code($codeRaw, $sectionName);
                $codeNoSpace = normalize_schedule_code_no_space($codeRaw, $sectionName);

                // Try to find lecture-lab pairs (CSA104 and CSA104L) -- strictly: code that ends with 'L' is lab
                if ($codeNoSpace !== '') {
                    $subjectCodesInCSV[$codeNoSpace] = 1;
                }
            }
            // Find lecture <-> laboratory pairs
            foreach (array_keys($subjectCodesInCSV) as $code) {
                if (preg_match('/^(.*\d{2,})L$/i', $code, $m)) {
                    // Laboratory CSAXXXL, check if CSAXXX (without L) exists in CSV
                    $lecCode = $m[1];
                    if (isset($subjectCodesInCSV[$lecCode])) {
                        $lectureToLabCodes[$lecCode] = $code;
                        $labToLectureCodes[$code] = $lecCode;
                    }
                }
            }

            // --- END lecture-lab mapping logic ---

            // Validate each schedule slot (now with 24-hour)
            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                $csvLine = $i + 1;

                $day             = $dayCol        !== null ? trim((string)($r[$dayCol] ?? ''))  : '';
                $startTimeRaw    = $startCol      !== null ? trim((string)($r[$startCol] ?? '')): '';
                $endTimeRaw      = $endCol        !== null ? trim((string)($r[$endCol] ?? ''))  : '';
                $startTime       = normalize_time_24h($startTimeRaw);
                $endTime         = normalize_time_24h($endTimeRaw);
                $room            = $roomCol       !== null ? trim((string)($r[$roomCol] ?? '')) : '';
                $codeRaw         = $codeCol       !== null ? (string)($r[$codeCol] ?? '') : '';
                $desc            = $descCol       !== null ? trim((string)($r[$descCol] ?? ''))     : '';
                $unitsRaw        = $unitsCol      !== null ? trim((string)($r[$unitsCol] ?? ''))    : '';
                $facultyEmail    = $facultyCol    !== null ? trim((string)($r[$facultyCol] ?? ''))  : '';
                $sectionName     = $cysCol        !== null ? trim((string)($r[$cysCol] ?? ''))      : '';
                $units           = null;
                if ($unitsRaw !== '') {
                    if (!is_numeric($unitsRaw)) {
                        $errors[] = "Line $csvLine: Invalid units value '$unitsRaw'";
                        continue;
                    }
                    $units = (float)$unitsRaw;
                    // Business rule: treat 1.5-unit uploads as 3 units.
                    if (abs($units - 1.5) < 0.0001) {
                        $units = 3.0;
                    }
                }

                if ($sectionName === '') {
                    $sectionName = 'N/A';
                }

                $codeNorm       = normalize_schedule_code($codeRaw, $sectionName);
                $codeNoSpace    = normalize_schedule_code_no_space($codeRaw, $sectionName);

                $missingFields = [];
                if ($day === '') $missingFields[] = 'Day';
                if ($startTime === '') $missingFields[] = 'Start Time';
                if ($endTime === '') $missingFields[] = 'End Time';
                if ($codeRaw === '' && $codeNoSpace === '') $missingFields[] = 'Subject Code';
                if ($desc === '') $missingFields[] = 'Description';
                if ($facultyEmail === '') $missingFields[] = 'Faculty Email';
                if ($sectionName === '') $missingFields[] = 'Section Name';
                if (!empty($missingFields)) {
                    $errors[] = "Line $csvLine: Missing required field(s): " . implode(', ', $missingFields);
                    continue;
                }

                // Day mapping
                $inputDay = strtoupper(trim($day));
                $dayOfWeek = null;
                if (is_numeric($day)) {
                    $numDay = (int)$day;
                    if ($numDay >= 1 && $numDay <= 6) $dayOfWeek = $numDay;
                } elseif (isset($dayCharMap[$inputDay])) {
                    $dayOfWeek = $dayCharMap[$inputDay];
                } else {
                    $dayKey = strtolower($day);
                    if (isset($dayMap[$dayKey])) {
                        $dw = $dayMap[$dayKey];
                        if ($dw >= 1 && $dw <= 6) $dayOfWeek = $dw;
                    }
                }
                if (!$dayOfWeek) {
                    $errors[] = "Line $csvLine: Invalid day value (allowed: Mon-Sat only)";
                    continue;
                }

                // Validate subject/code - try both format: no-space and with-space in DB
                $stmt = $db->prepare("SELECT id FROM subjects WHERE (UPPER(REPLACE(code, ' ', '')) = ? OR UPPER(code) = ?)");
                $stmt->execute([$codeNoSpace, $codeNorm]);
                $subjectRow = $stmt->fetch();

                if (!$subjectRow) {
                    try {
                        if ($hasSubjectsUnitsColumn) {
                            $insertSubjectStmt = $db->prepare("
                                INSERT INTO subjects (code, title, description, units)
                                VALUES (?, ?, ?, ?)
                            ");
                            $insertSubjectStmt->execute([$codeNoSpace, $desc, $desc, $units]);
                        } else {
                            $insertSubjectStmt = $db->prepare("
                                INSERT INTO subjects (code, title, description)
                                VALUES (?, ?, ?)
                            ");
                            $insertSubjectStmt->execute([$codeNoSpace, $desc, $desc]);
                        }
                        $subjectId = (int)$db->lastInsertId();
                        $subjectRow = ['id' => $subjectId];
                    } catch (Throwable $e) {
                        $errors[] = "Line $csvLine: Subject code '" . htmlspecialchars($codeRaw) . "' not found, and failed to create new subject: " . $e->getMessage();
                        continue;
                    }
                } else {
                    $subjectIdForDesc = (int)$subjectRow['id'];
                    if ($hasSubjectsUnitsColumn) {
                        $updateDescStmt = $db->prepare("UPDATE subjects SET description = ?, units = ? WHERE id = ?");
                        $updateDescStmt->execute([$desc, $units, $subjectIdForDesc]);
                    } else {
                        $updateDescStmt = $db->prepare("UPDATE subjects SET description = ? WHERE id = ?");
                        $updateDescStmt->execute([$desc, $subjectIdForDesc]);
                    }
                }

                // Validate instructor
                $stmt = $db->prepare("SELECT id FROM instructors WHERE email = ?");
                $stmt->execute([$facultyEmail]);
                $instructorRow = $stmt->fetch();
                if (!$instructorRow) {
                    $errors[] = "Line $csvLine: Faculty/instructor '$facultyEmail' not found";
                    continue;
                }
            }

            if (count($errors) > 0) {
                $error = "Upload aborted due to error(s):<ul><li>" . implode("</li><li>", $errors) . "</li></ul>";
            } else {
                $added = 0;
                $db->beginTransaction();
                try {
                    // --- We need to process the normal insert, plus:
                    // For every CSA104 (lecture) slot, ensure the student's class_section for CSA104L (lab) is created as needed, matching the same section.
                    // We'll build a quick index of CSV by code/section for lookup.
                    $csvEntriesByCodeSection = [];
                    for ($i = 1; $i < count($rows); $i++) {
                        $r = $rows[$i];
                        $sectionName = $cysCol !== null ? trim((string)($r[$cysCol] ?? '')) : '';
                        if ($sectionName === '') $sectionName = 'N/A';
                        $codeRaw = $codeCol !== null ? (string)($r[$codeCol] ?? '') : '';
                        $codeNorm = normalize_schedule_code($codeRaw, $sectionName);
                        $codeNoSpace = normalize_schedule_code_no_space($codeRaw, $sectionName);

                        $csvEntriesByCodeSection[$codeNoSpace . '||' . $sectionName] = $i;
                    }

                    for ($i = 1; $i < count($rows); $i++) {
                        $r = $rows[$i];
                        $day             = $dayCol        !== null ? trim((string)($r[$dayCol] ?? ''))     : '';
                        $startTimeRaw    = $startCol      !== null ? trim((string)($r[$startCol] ?? ''))   : '';
                        $endTimeRaw      = $endCol        !== null ? trim((string)($r[$endCol] ?? ''))     : '';
                        $startTime       = normalize_time_24h($startTimeRaw);
                        $endTime         = normalize_time_24h($endTimeRaw);
                        $room            = $roomCol       !== null ? trim((string)($r[$roomCol] ?? ''))    : '';
                        $codeRaw         = $codeCol       !== null ? (string)($r[$codeCol] ?? '') : '';
                        $desc            = $descCol       !== null ? trim((string)($r[$descCol] ?? ''))    : '';
                        $unitsRaw        = $unitsCol      !== null ? trim((string)($r[$unitsCol] ?? ''))   : '';
                        $facultyEmail    = $facultyCol    !== null ? trim((string)($r[$facultyCol] ?? '')) : '';
                        $sectionName     = $cysCol        !== null ? trim((string)($r[$cysCol] ?? ''))     : '';
                        if ($sectionName === '') $sectionName = 'N/A';
                        $units           = $unitsRaw !== '' && is_numeric($unitsRaw) ? (float)$unitsRaw : null;
                        if ($units !== null && abs($units - 1.5) < 0.0001) {
                            $units = 3.0;
                        }

                        $codeNorm       = normalize_schedule_code($codeRaw, $sectionName);
                        $codeNoSpace    = normalize_schedule_code_no_space($codeRaw, $sectionName);

                        $inputDay = strtoupper(trim($day));
                        $dayOfWeek = null;
                        if (is_numeric($day)) {
                            $numDay = (int)$day;
                            if ($numDay >= 1 && $numDay <= 6) $dayOfWeek = $numDay;
                        } elseif (isset($dayCharMap[$inputDay])) {
                            $dayOfWeek = $dayCharMap[$inputDay];
                        } else {
                            $dayKey = strtolower($day);
                            if (isset($dayMap[$dayKey])) {
                                $dw = $dayMap[$dayKey];
                                if ($dw >= 1 && $dw <= 6) $dayOfWeek = $dw;
                            }
                        }

                        $stmt = $db->prepare("SELECT id, code, department FROM subjects WHERE (UPPER(REPLACE(code, ' ', '')) = ? OR UPPER(code) = ?) LIMIT 1");
                        $stmt->execute([$codeNoSpace, $codeNorm]);
                        $subjectRow = $stmt->fetch();
                        if (!$subjectRow) {
                            if ($hasSubjectsUnitsColumn) {
                                $insertSubjectStmt = $db->prepare("
                                    INSERT INTO subjects (code, title, description, units)
                                    VALUES (?, ?, ?, ?)
                                ");
                                $insertSubjectStmt->execute([$codeNoSpace, $desc, $desc, $units]);
                            } else {
                                $insertSubjectStmt = $db->prepare("
                                    INSERT INTO subjects (code, title, description)
                                    VALUES (?, ?, ?)
                                ");
                                $insertSubjectStmt->execute([$codeNoSpace, $desc, $desc]);
                            }
                            $subjectId = (int)$db->lastInsertId();
                            $subjectDept = null;
                        } else {
                            $subjectId = (int)($subjectRow['id'] ?? 0);
                            $subjectDept = $subjectRow['department'] ?? null;
                            if ($hasSubjectsUnitsColumn) {
                                $updateDescStmt = $db->prepare("UPDATE subjects SET description = ?, units = ? WHERE id = ?");
                                $updateDescStmt->execute([$desc, $units, $subjectId]);
                            } else {
                                $updateDescStmt = $db->prepare("UPDATE subjects SET description = ? WHERE id = ?");
                                $updateDescStmt->execute([$desc, $subjectId]);
                            }
                        }

                        $stmt = $db->prepare("SELECT id FROM instructors WHERE email = ?");
                        $stmt->execute([$facultyEmail]);
                        $instructorRow = $stmt->fetch();
                        $instructorId = (int)($instructorRow['id'] ?? 0);

                        $sy = $activeAY['school_year'];
                        $sem = $activeAY['semester'];
                        $academicYearId = $activeAY['id'];

                        $stmt = $db->prepare("SELECT id FROM instructor_subjects WHERE instructor_id=? AND subject_id=? AND school_year=? AND semester=?");
                        $stmt->execute([$instructorId, $subjectId, $sy, $sem]);
                        $isubRow = $stmt->fetch();
                        if (!$isubRow) {
                            $db->prepare("INSERT INTO instructor_subjects (instructor_id, subject_id, school_year, semester, academic_year_id) VALUES (?,?,?,?,?)")
                                ->execute([$instructorId, $subjectId, $sy, $sem, $academicYearId]);
                            $isubId = (int)$db->lastInsertId();
                        } else {
                            $isubId = (int)$isubRow['id'];
                        }

                        $yearLevel = 1;
                        if (preg_match('/^(\d+)/', $sectionName, $m)) {
                            $yearLevel = (int)$m[1];
                            if ($yearLevel <= 0) $yearLevel = 1;
                        }
                        $deptFallback = normalize_schedule_course_from_section($sectionName) ?: null;
                        $dept = $subjectDept ?: $deptFallback;

                        $stmt = $db->prepare("
                            SELECT id
                            FROM class_sections
                            WHERE academic_year_id = ?
                              AND subject_id = ?
                              AND year_level = ?
                              AND (
                                    section = ?
                                 OR UPPER(REPLACE(section, ' ', ' ')) = UPPER(REPLACE(?, ' ', ' '))
                              )
                            LIMIT 1
                        ");
                        $stmt->execute([$academicYearId, $subjectId, $yearLevel, $sectionName, $sectionName]);
                        $csRow = $stmt->fetch(PDO::FETCH_ASSOC);
                        $lectureCsId = null;
                        if ($csRow) {
                            $lectureCsId = (int)($csRow['id'] ?? 0);
                            $db->prepare("UPDATE class_sections SET instructor_subject_id = ? WHERE id = ?")
                                ->execute([$isubId, $csRow['id']]);
                        } else {
                            $db->prepare("
                                INSERT INTO class_sections (academic_year_id, subject_id, year_level, section, department, instructor_subject_id)
                                VALUES (?,?,?,?,?,?)
                            ")->execute([$academicYearId, $subjectId, $yearLevel, $sectionName, $dept, $isubId]);
                            $lectureCsId = (int)$db->lastInsertId();
                        }

                        $finalCode = $codeNoSpace;

                        $stmt = $db->prepare("
                            INSERT INTO schedule (
                                instructor_subject_id,
                                day_of_week,
                                start_time,
                                end_time,
                                room,
                                section_name,
                                code,
                                description
                            ) VALUES (?,?,?,?,?,?,?,?)
                        ");
                        $stmt->execute([
                            $isubId,
                            $dayOfWeek,
                            $startTime,
                            $endTime,
                            $room,
                            $sectionName,
                            $finalCode,
                            $desc
                        ]);
                        $added++;

                        // --------------- CUSTOM LECTURE-LAB ADAPTER LOGIC HERE ---------------
                        // If this schedule code is a LECTURE (e.g. CSA104) and there is a corresponding LAB (CSA104L), copy section to CSA104L if not already there

                        if (isset($lectureToLabCodes[$finalCode])) {
                            $labCode = $lectureToLabCodes[$finalCode]; // e.g. 'CSA104L'
                            // Find subjectId for CSA104L
                            $labSubjectStmt = $db->prepare("SELECT id FROM subjects WHERE UPPER(REPLACE(code, ' ', '')) = ?");
                            $labSubjectStmt->execute([$labCode]);
                            $labSubjectRow = $labSubjectStmt->fetch(PDO::FETCH_ASSOC);
                            if (!$labSubjectRow) {
                                // Insert subject if not exists, description = same as lecture
                                if ($hasSubjectsUnitsColumn) {
                                    $db->prepare("INSERT INTO subjects (code, title, description, units) VALUES (?, ?, ?, ?)")
                                        ->execute([$labCode, $desc, $desc, $units]);
                                } else {
                                    $db->prepare("INSERT INTO subjects (code, title, description) VALUES (?, ?, ?)")
                                        ->execute([$labCode, $desc, $desc]);
                                }
                                $labSubjectId = (int)$db->lastInsertId();
                            } else {
                                $labSubjectId = (int)$labSubjectRow['id'];
                            }

                            // Create instructor_subjects for lab (instructor same as lecture)
                            $labInstrSubStmt = $db->prepare("SELECT id FROM instructor_subjects WHERE instructor_id=? AND subject_id=? AND school_year=? AND semester=?");
                            $labInstrSubStmt->execute([$instructorId, $labSubjectId, $sy, $sem]);
                            $labIsubRow = $labInstrSubStmt->fetch(PDO::FETCH_ASSOC);

                            if (!$labIsubRow) {
                                $db->prepare("INSERT INTO instructor_subjects (instructor_id, subject_id, school_year, semester, academic_year_id) VALUES (?,?,?,?,?)")
                                    ->execute([$instructorId, $labSubjectId, $sy, $sem, $academicYearId]);
                                $labIsubId = (int)$db->lastInsertId();
                            } else {
                                $labIsubId = (int)$labIsubRow['id'];
                            }

                            // Insert class_section if not exists for the same sectionName
                            $labCsStmt = $db->prepare("
                                SELECT id
                                FROM class_sections
                                WHERE academic_year_id = ?
                                  AND subject_id = ?
                                  AND year_level = ?
                                  AND (
                                      section = ?
                                   OR UPPER(REPLACE(section, ' ', ' ')) = UPPER(REPLACE(?, ' ', ' '))
                                  )
                                LIMIT 1
                            ");
                            $labCsStmt->execute([$academicYearId, $labSubjectId, $yearLevel, $sectionName, $sectionName]);
                            $labCsRow = $labCsStmt->fetch(PDO::FETCH_ASSOC);
                            $labCsId = null;

                            if (!$labCsRow) {
                                $db->prepare("INSERT INTO class_sections (academic_year_id, subject_id, year_level, section, department, instructor_subject_id)
                                    VALUES (?,?,?,?,?,?)")
                                ->execute([$academicYearId, $labSubjectId, $yearLevel, $sectionName, $dept, $labIsubId]);
                                $labCsId = (int)$db->lastInsertId();
                            } else {
                                $labCsId = (int)($labCsRow['id'] ?? 0);
                                $db->prepare("UPDATE class_sections SET instructor_subject_id = ? WHERE id = ?")
                                    ->execute([$labIsubId, $labCsRow['id']]);
                            }

                            // Mirror existing lecture enrollments into the newly created lab.
                            // This ensures that when students were uploaded only to CSA104 (lecture),
                            // schedule upload that creates CSA104L (lab) also enrolls them into CSA104L.
                            if ($lectureCsId && $labCsId) {
                                $mirrorEnrollSql = "
                                    INSERT INTO enrollments (class_section_id, student_id, status)
                                    SELECT
                                        ? AS class_section_id,
                                        e.student_id,
                                        e.status
                                    FROM enrollments e
                                    WHERE e.class_section_id = ?
                                      AND e.status = 'enrolled'
                                      AND NOT EXISTS (
                                          SELECT 1
                                          FROM enrollments e2
                                          WHERE e2.class_section_id = ?
                                            AND e2.student_id = e.student_id
                                      )
                                ";
                                $db->prepare($mirrorEnrollSql)->execute([$labCsId, $lectureCsId, $labCsId]);
                            }
                        }
                        // --------------- END CUSTOM LECTURE-LAB LOGIC ---------------
                    }
                    $db->commit();
                    logActivity('mis', currentMisUserId(), 'upload_schedules', null, null, "Added $added schedule(s)");
                    $message = "Upload complete. $added schedule slot(s) added.";
                } catch (Throwable $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                }
            }
        }
    }
}
// ----------- END REWRITE UPLOAD SCHEDULE -----------

$stmt = $db->query("SELECT id, school_year, semester, is_active FROM academic_years ORDER BY school_year DESC, semester DESC");
$academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
$activeAY = null;
foreach ($academicYears as $ay) {
    if (!empty($ay['is_active'])) {
        $activeAY = $ay;
        break;
    }
}

$ayId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : null;
if (!$ayId && !empty($academicYears)) {
    $active = array_filter($academicYears, fn($a) => $a['is_active']);
    $ayId = !empty($active) ? (int)reset($active)['id'] : (int)$academicYears[0]['id'];
}

// -- SEARCH FILTER HANDLING FOR CLASS SECTIONS (by subject/instructor) --
$csec_subject = '';
$csec_instructor = '';
if (isset($_GET['csec_subject'])) {
    $csec_subject = trim($_GET['csec_subject']);
}
if (isset($_GET['csec_instructor'])) {
    $csec_instructor = trim($_GET['csec_instructor']);
}

// --- Add paging for class sections ---
$sectionPage = isset($_GET['section_page']) && is_numeric($_GET['section_page']) ? max((int)$_GET['section_page'],1) : 1;
$sectionPageSize = 25;

// Get count for paging (respect filters)
$classSectionsCount = 0;
if ($ayId) {
    $classSectionCountSql = "SELECT COUNT(*) 
        FROM class_sections cs
        JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
        LEFT JOIN instructors i ON i.id = isub.instructor_id
        WHERE cs.academic_year_id = ?";

    $params = [$ayId];
    if ($csec_subject !== '') {
        $classSectionCountSql .= " AND (UPPER(TRIM(s.code)) LIKE ? OR UPPER(TRIM(s.title)) LIKE ?)";
        $params[] = '%' . strtoupper($csec_subject) . '%';
        $params[] = '%' . strtoupper($csec_subject) . '%';
    }
    if ($csec_instructor !== '') {
        $classSectionCountSql .= " AND (UPPER(TRIM(i.full_name)) LIKE ? OR UPPER(TRIM(i.email)) LIKE ?)";
        $params[] = '%' . strtoupper($csec_instructor) . '%';
        $params[] = '%' . strtoupper($csec_instructor) . '%';
    }

    $stmt = $db->prepare($classSectionCountSql);
    $stmt->execute($params);
    $classSectionsCount = (int)$stmt->fetchColumn();
}

$sectionTotalPages = max(1, ceil($classSectionsCount / $sectionPageSize));

$classSections = [];
$schedules = [];
$ayRow = null;
if ($ayId) {
    $stmt = $db->prepare("SELECT school_year, semester FROM academic_years WHERE id = ?");
    $stmt->execute([$ayId]);
    $ayRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (isset($ayRow['school_year'])) {
        $offset = ($sectionPage-1)*$sectionPageSize;
        $sql = "
          SELECT cs.id, cs.section, s.code, s.description, cs.department, cs.instructor_subject_id,
                 isub.id as isub_id, i.full_name as instructor_name, i.id as instructor_id
          FROM class_sections cs
          JOIN subjects s ON s.id = cs.subject_id
          LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
          LEFT JOIN instructors i ON i.id = isub.instructor_id
          WHERE cs.academic_year_id = ?
        ";

        $params = [$ayId];
        if ($csec_subject !== '') {
            $sql .= " AND (UPPER(TRIM(s.code)) LIKE ? OR UPPER(TRIM(s.title)) LIKE ?)";
            $params[] = '%' . strtoupper($csec_subject) . '%';
            $params[] = '%' . strtoupper($csec_subject) . '%';
        }
        if ($csec_instructor !== '') {
            $sql .= " AND (UPPER(TRIM(i.full_name)) LIKE ? OR UPPER(TRIM(i.email)) LIKE ?)";
            $params[] = '%' . strtoupper($csec_instructor) . '%';
            $params[] = '%' . strtoupper($csec_instructor) . '%';
        }
        $sql .= " ORDER BY s.code, cs.section
                  LIMIT ? OFFSET ?";
        $params[] = $sectionPageSize;
        $params[] = $offset;

        $stmt = $db->prepare($sql);
        foreach ($params as $i => $val) {
            if (is_int($val)) {
                $stmt->bindValue($i+1, $val, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($i+1, $val);
            }
        }
        $stmt->execute();
        $classSections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($ayRow) {
        $sy = $ayRow['school_year'];
        $sem = $ayRow['semester'];
        $stmt = $db->prepare("
          SELECT sch.*, s.code, s.description, sch.section_name as section, i.full_name as instructor_name, isub.instructor_id as instructor_id
          FROM schedule sch
          JOIN instructor_subjects isub ON isub.id = sch.instructor_subject_id
          JOIN subjects s ON s.id = isub.subject_id
          LEFT JOIN instructors i ON i.id = isub.instructor_id
          WHERE (isub.school_year = ? AND isub.semester = ?) OR (isub.academic_year_id = ?)
          ORDER BY sch.day_of_week, sch.start_time
        ");
        $stmt->execute([$sy, $sem, $ayId]);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Schedules';
$currentPage = 'schedules';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Manage Schedules</h4>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

<?php if (!$activeAY): ?>
<div class="alert alert-warning mb-4">No active semester. <a href="<?= $baseUrl ?>/academic_years.php">Activate an academic year</a> first to upload new schedules and enrollments.</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap" style="gap: 1rem;">
    <span><strong>Upload Schedule</strong><?php if ($activeAY): ?> <span class="text-muted fw-normal small">(applies to active semester: <?= htmlspecialchars($activeAY['school_year'] . ' ' . $activeAY['semester']) ?>)</span><?php endif; ?></span>
    <form method="get" class="mb-0">
      <div class="d-flex align-items-end" style="gap:0.5rem;">
        <label for="academicYearDropdown" class="form-label mb-0 me-2" style="font-size:0.92em;">Academic Year:</label>
        <select name="academic_year_id" id="academicYearDropdown" class="form-select form-select-sm" style="width:auto;min-width:130px;" onchange="this.form.submit()">
          <?php foreach ($academicYears as $ay): ?>
            <option value="<?= $ay['id'] ?>" <?= $ayId === (int)$ay['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($ay['school_year'] . ' ' . $ay['semester']) ?><?= $ay['is_active'] ? ' (Active)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
  <div class="card-body">
    <p class="small text-muted mb-3">
      CSV Columns format: DAY, START, FINISH, RM, CODE, DESCRIPTION, FACULTY (email), CYS ...
      <a href="<?= $baseUrl ?>/download_schedule_template.php">Download template</a>
    </p>
    <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="upload_schedule">
      <div class="col-md-8">
        <input type="file" name="file" class="form-control form-control-sm" accept=".csv,.txt" <?= $activeAY ? 'required' : 'disabled' ?>>
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary btn-sm" <?= !$activeAY ? 'disabled' : '' ?>><i class="bi bi-upload me-1"></i>Upload Schedule</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong>Class Sections</strong>
    <button class="btn btn-primary btn-sm" id="showScheduleSlotsBtn">
      Schedule Slots
    </button>
  </div>

  <!-- SEARCH FORM FOR SUBJECT & INSTRUCTOR (Above table) -->
  <div class="card-body pb-0">
    <form method="get" class="row g-2 align-items-end mb-3" id="classSectionsSearchForm" autocomplete="off">
      <input type="hidden" name="academic_year_id" value="<?= (int)$ayId ?>">
      <div class="col-md-4">
        <label for="csec_subject" class="form-label mb-1">Search Subject (Code or Title)</label>
        <input type="text" class="form-control form-control-sm" name="csec_subject" id="csec_subject" placeholder="e.g. PATHFit2, Math" value="<?=htmlspecialchars($csec_subject)?>">
      </div>
      <div class="col-md-4">
        <label for="csec_instructor" class="form-label mb-1">Search Instructor (Name or Email)</label>
        <input type="text" class="form-control form-control-sm" name="csec_instructor" id="csec_instructor" placeholder="e.g. Allen, allenadriano@chcc.edu.ph" value="<?=htmlspecialchars($csec_instructor)?>">
      </div>
    </form>
  </div>

  <div class="card-body pt-0 p-0">
    <?php if (empty($classSections)): ?>
      <p class="text-muted p-3 mb-0">No class sections for this academic year. Upload enrollments to create sections.</p>
    <?php else: ?>
      <div class="table-responsive" style="max-height: 700px; overflow-y: auto;">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Description</th>
              <th>Section</th>
              <th>Department</th>
              <th>Instructor</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classSections as $cs): ?>
              <tr>
                <td><strong><?= htmlspecialchars($cs['code']) ?></strong></td>
                <td><?= htmlspecialchars($cs['description'] ?? '-') ?></td>
                <td><?= htmlspecialchars($cs['section'] === '' ? 'N/A' : $cs['section']) ?></td>
                <td><?= htmlspecialchars($cs['department'] ?? '-') ?></td>
                <td>
                  <?php if (!empty($cs['instructor_name'])): ?>
                    <?= htmlspecialchars($cs['instructor_name']) ?>
                    <span class="text-success" title="Assigned">
                      <svg xmlns="http://www.w3.org/2000/svg" width="1.1em" height="1.1em" fill="currentColor" class="bi bi-check-circle-fill ms-1" viewBox="0 0 16 16" style="vertical-align: -.15em;">
                        <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zM6.97 11.03a.75.75 0 0 0 1.07 0l3.992-3.992a.75.75 0 1 0-1.06-1.06L7.5 9.44 6.03 7.97a.75.75 0 1 0-1.06 1.06l1.94 1.94z"/>
                      </svg>
                    </span>
                  <?php else: ?>
                    <span class="text-muted">Not assigned</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button class="btn btn-outline-secondary btn-sm btn-edit-section-instructor" style="font-size: 12px";
                    data-section-id="<?= (int)$cs['id'] ?>"
                    data-instructor-id="<?= htmlspecialchars($cs['instructor_id'] ?? '') ?>"
                    data-instructor-name="<?= htmlspecialchars($cs['instructor_name'] ?? '') ?>">
                    Assign Instructor
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($sectionTotalPages > 1): ?>
        <nav class="d-flex justify-content-center my-3" aria-label="Class sections pagination">
          <ul class="pagination pagination-sm mb-0">
            <?php
              $getVars = $_GET;
              $displayRange = 2;
              $startP = max(1, $sectionPage - $displayRange);
              $endP = min($sectionTotalPages, $sectionPage + $displayRange);
              if ($endP - $startP < $displayRange*2) {
                if ($startP == 1) $endP = min($sectionTotalPages, $startP + $displayRange*2);
                if ($endP == $sectionTotalPages) $startP = max(1, $endP - $displayRange*2);
              }

              $getVars['section_page'] = 1;
              $firstUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              $getVars['section_page'] = max(1, $sectionPage - 1);
              $prevUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
            ?>
            <li class="page-item <?= $sectionPage == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $firstUrl ?>" aria-label="First"><<</a>
            </li>
            <li class="page-item <?= $sectionPage == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $prevUrl ?>" aria-label="Previous">
                <span aria-hidden="true">&laquo;</span>
              </a>
            </li>

            <?php if ($startP > 2): ?>
              <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>

            <?php for ($p = $startP; $p <= $endP; $p++): ?>
              <?php
                $getVars['section_page'] = $p;
                $url = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              ?>
              <li class="page-item <?= $p == $sectionPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= $url ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>

            <?php if ($endP < $sectionTotalPages): ?>
              <?php if ($endP < $sectionTotalPages - 1): ?>
                <li class="page-item disabled"><span class="page-link">…</span></li>
              <?php endif; ?>
              <?php
                $getVars['section_page'] = $sectionTotalPages;
                $lastUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              ?>
            <?php endif; ?>

            <?php
              $getVars['section_page'] = min($sectionTotalPages, $sectionPage + 1);
              $nextUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
            ?>
            <li class="page-item <?= $sectionPage == $sectionTotalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $nextUrl ?>" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
              </a>
            </li>
            <li class="page-item <?= $sectionPage == $sectionTotalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $lastUrl ?? '#' ?>" aria-label="Last">>></a>
            </li>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  
  </div>
</div>

<div class="card mt-4" id="scheduleSlotsCard" style="display:none">
  <div class="card-header bg-white"><strong>Schedule Slots</strong></div>
  <div class="card-body p-0">
    <?php if (empty($schedules)): ?>
      <p class="text-muted p-3 mb-0">No schedule slots. Assign instructors to class sections, then add schedule entries in the Instructor module.</p>
    <?php else: ?>
      <div class="table-responsive" style="max-height: 900px; overflow-y: auto;">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Description</th>
              <th>Section</th>
              <th>Day</th>
              <th>Time</th>
              <th>Room</th>
              <th>Instructor</th>
              <th>Edit</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            foreach ($schedules as $sch):
            ?>
              <tr>
                <td><?= htmlspecialchars($sch['code']) ?></td>
                <td><?= htmlspecialchars($sch['description'] ?? '-') ?></td>
                <td><?= htmlspecialchars((isset($sch['section']) && $sch['section'] === '') ? 'N/A' : ($sch['section'] ?? '-')) ?></td>
                <td><?= isset($days[$sch['day_of_week']]) ? $days[$sch['day_of_week']] : $sch['day_of_week'] ?></td>
                <td><?= date('g:i A', strtotime($sch['start_time'])) ?> - <?= date('g:i A', strtotime($sch['end_time'])) ?></td>
                <td><?= htmlspecialchars($sch['room'] ?? '-') ?></td>
                <td><?= htmlspecialchars($sch['instructor_name'] ?? 'Not assigned') ?></td>
                <td>
                  <button class="btn btn-outline-secondary btn-sm btn-edit-schedule-instructor"
                    data-schedule-id="<?= (int)$sch['id'] ?>"
                    data-instructor-id="<?= htmlspecialchars($sch['instructor_id'] ?? '') ?>"
                    data-instructor-name="<?= htmlspecialchars($sch['instructor_name'] ?? '') ?>"
                    >Edit</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Class Section Instructor Modal -->
<div class="modal fade" id="editSectionInstructorModal" tabindex="-1" aria-labelledby="editSectionInstructorModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" id="editSectionInstructorForm" class="modal-content">
      <input type="hidden" name="action" value="edit_class_section_instructor">
      <input type="hidden" name="section_id" id="editSectionId">
      <div class="modal-header">
        <h5 class="modal-title" id="editSectionInstructorModalLabel">Assign Instructor to Section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="sectionInstructorSearch" class="form-label">Instructor</label>
          <input type="text" class="form-control" id="sectionInstructorSearch" autocomplete="off" placeholder="Type to search for instructor by name or email">
          <input type="hidden" name="instructor_id" id="sectionInstructorIdField">
          <div id="sectionInstructorResults" class="list-group mt-2" style="max-height:175px;overflow:auto;z-index:10;position: absolute;width: 93%;"></div>
          <div class="form-text">Leave blank to unassign.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Schedule Instructor Modal -->
<div class="modal fade" id="editScheduleInstructorModal" tabindex="-1" aria-labelledby="editScheduleInstructorModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" id="editScheduleInstructorForm" class="modal-content">
      <input type="hidden" name="action" value="edit_schedule_instructor">
      <input type="hidden" name="schedule_id" id="editScheduleId">
      <div class="modal-header">
        <h5 class="modal-title" id="editScheduleInstructorModalLabel">Edit Assigned Instructor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="scheduleInstructorSearch" class="form-label">Instructor</label>
          <input type="text" class="form-control" id="scheduleInstructorSearch" autocomplete="off" placeholder="Type to search for instructor by name or email">
          <input type="hidden" name="instructor_id" id="scheduleInstructorIdField">
          <div id="scheduleInstructorResults" class="list-group mt-2" style="max-height:175px;overflow:auto;z-index:10;position: absolute;width: 93%;"></div>
          <div class="form-text">Leave blank to unassign.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
const instructorsDb = <?= json_encode($instructors) ?>;
const subjectsDb = <?= json_encode($subjects) ?>;

function updateQueryAndReload(params) {
    let url;
    try {
        url = new window.URL(window.location.href);
    } catch (e) {
        const a = document.createElement('a');
        a.href = window.location.href;
        let search = a.search ? a.search.substring(1).split('&') : [];
        let searchParams = {};
        for (let s of search) {
            if (!s) continue;
            const [k, v] = s.split('=');
            searchParams[decodeURIComponent(k)] = decodeURIComponent(v || '');
        }
        for (const key in params) {
            if (params[key] === null || params[key] === undefined || params[key] === '') {
                delete searchParams[key];
            } else {
                searchParams[key] = params[key];
            }
        }
        delete searchParams['section_page'];
        const newSearch = Object.keys(searchParams).map(k => encodeURIComponent(k) + '=' + encodeURIComponent(searchParams[k])).join('&');
        a.search = newSearch ? '?' + newSearch : '';
        window.location.href = a.href;
        return;
    }
    for (const key in params) {
        if (params[key] === null || params[key] === undefined || params[key] === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, params[key]);
        }
    }
    url.searchParams.delete('section_page');
    window.location.href = url.toString();
}

function instructorMatches(inst, q) {
    q = q.toLowerCase();
    return inst.full_name.toLowerCase().includes(q) || inst.email.toLowerCase().includes(q) || (inst.id.toString() === q);
}
function subjectMatches(sub, q) {
    q = q.toLowerCase();
    return sub.code.toLowerCase().includes(q) || (sub.title && sub.title.toLowerCase().includes(q));
}
document.addEventListener('DOMContentLoaded', function () {
  var showScheduleSlotsBtn = document.getElementById('showScheduleSlotsBtn');
  var scheduleSlotsCard = document.getElementById('scheduleSlotsCard');
  if (showScheduleSlotsBtn && scheduleSlotsCard) {
    showScheduleSlotsBtn.addEventListener('click', function() {
      if(scheduleSlotsCard.style.display === 'none' || scheduleSlotsCard.style.display === '') {
        scheduleSlotsCard.style.display = 'block';
        showScheduleSlotsBtn.style.display = 'none';
        setTimeout(() => scheduleSlotsCard.scrollIntoView({behavior:'smooth'}), 50);
      }
    });
  }

  (function() {
    const subjInput = document.getElementById('csec_subject');
    const instrInput = document.getElementById('csec_instructor');
    let subjTypingTimer, instrTypingTimer;
    const typingDelay = 400;
    function instantSubjectSearch() {
      updateQueryAndReload({
        csec_subject: subjInput.value.trim(),
        csec_instructor: instrInput ? instrInput.value.trim() : ''
      });
    }
    function instantInstructorSearch() {
      updateQueryAndReload({
        csec_subject: subjInput ? subjInput.value.trim() : '',
        csec_instructor: instrInput.value.trim()
      });
    }

    if (subjInput) {
      subjInput.addEventListener('keyup', function(e) {
        clearTimeout(subjTypingTimer);
        subjTypingTimer = setTimeout(() => {
          instantSubjectSearch();
        }, typingDelay);
      });
      subjInput.addEventListener('keydown', function(e) { clearTimeout(subjTypingTimer); });
      subjInput.parentNode.style.position = 'relative';
    }
    if (instrInput) {
      instrInput.addEventListener('keyup', function(e) {
        clearTimeout(instrTypingTimer);
        instrTypingTimer = setTimeout(() => {
          instantInstructorSearch();
        }, typingDelay);
      });
      instrInput.addEventListener('keydown', function(e) { clearTimeout(instrTypingTimer); });
      instrInput.parentNode.style.position = 'relative';
    }

    const resetBtn = document.getElementById('searchResetBtn');
    if (resetBtn) {
      resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (subjInput) subjInput.value = '';
        if (instrInput) instrInput.value = '';
        updateQueryAndReload({csec_subject: '', csec_instructor: '', section_page: ''});
      });
    }
  })();

  var editSectionBtns = document.querySelectorAll('.btn-edit-section-instructor');
  var sectionModal = new bootstrap.Modal(document.getElementById('editSectionInstructorModal'));
  var sectionIdInput = document.getElementById('editSectionId');
  var sectionInstructorIdField = document.getElementById('sectionInstructorIdField');
  var sectionInstructorSearch = document.getElementById('sectionInstructorSearch');
  var sectionInstructorResults = document.getElementById('sectionInstructorResults');
  var editSectionInstructorForm = document.getElementById('editSectionInstructorForm');

  editSectionBtns.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      sectionIdInput.value = this.dataset.sectionId;
      const curId = this.dataset.instructorId;
      let cur = '';
      if (curId && curId !== '0') {
        const curRec = instructorsDb.find(ins => ins.id == curId);
        if (curRec) { cur = curRec.full_name + " (" + curRec.email + ")"; }
      }
      sectionInstructorSearch.value = cur;
      sectionInstructorIdField.value = curId && curId !== '0' ? curId : '';
      sectionInstructorResults.innerHTML = '';
      sectionModal.show();
    });
  });

  sectionInstructorSearch && sectionInstructorSearch.addEventListener('input', function() {
    const query = this.value.trim();
    sectionInstructorResults.innerHTML = '';
    sectionInstructorIdField.value = '';

    if (!query) return;

    const matches = instructorsDb.filter(i => instructorMatches(i, query));
    if (matches.length === 0) {
      sectionInstructorResults.innerHTML = '<div class="list-group-item disabled">No results</div>';
    } else {
      matches.slice(0, 15).forEach(i => {
        var div = document.createElement('button');
        div.type = 'button';
        div.className = 'list-group-item list-group-item-action';
        div.textContent = i.full_name + " (" + i.email + ")";
        div.setAttribute('data-id', i.id);
        div.onclick = function() {
          sectionInstructorSearch.value = i.full_name + ' (' + i.email + ')';
          sectionInstructorIdField.value = i.id;
          sectionInstructorResults.innerHTML = '';
        };
        sectionInstructorResults.appendChild(div);
      });
    }
  });

  sectionInstructorSearch && sectionInstructorSearch.addEventListener('blur', function() {
    setTimeout(function() { sectionInstructorResults.innerHTML = ''; }, 120);
  });

  editSectionInstructorForm && editSectionInstructorForm.addEventListener('submit', function(e) {
    if (!sectionInstructorSearch.value.trim() || !sectionInstructorIdField.value) {
      sectionInstructorIdField.value = '';
      sectionInstructorSearch.value = '';
    }
  });


  var editBtns = document.querySelectorAll('.btn-edit-schedule-instructor');
  var modal = new bootstrap.Modal(document.getElementById('editScheduleInstructorModal'));
  var scheduleIdInput = document.getElementById('editScheduleId');
  var instructorIdField = document.getElementById('scheduleInstructorIdField');
  var instructorSearch = document.getElementById('scheduleInstructorSearch');
  var instructorResults = document.getElementById('scheduleInstructorResults');
  var editInstructorForm = document.getElementById('editScheduleInstructorForm');

  editBtns.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      scheduleIdInput.value = this.dataset.scheduleId;
      const curId = this.dataset.instructorId;
      let cur = '';
      if (curId && curId !== '0') {
        const curRec = instructorsDb.find(ins => ins.id == curId);
        if (curRec) { cur = curRec.full_name + " (" + curRec.email + ")"; }
      }
      instructorSearch.value = cur;
      instructorIdField.value = curId && curId !== '0' ? curId : '';
      instructorResults.innerHTML = '';
      modal.show();
    });
  });

  instructorSearch && instructorSearch.addEventListener('input', function() {
    const query = this.value.trim();
    instructorResults.innerHTML = '';
    instructorIdField.value = '';

    if (!query) return;

    const matches = instructorsDb.filter(i => instructorMatches(i, query));
    if (matches.length === 0) {
      instructorResults.innerHTML = '<div class="list-group-item disabled">No results</div>';
    } else {
      matches.slice(0, 15).forEach(i => {
        var div = document.createElement('button');
        div.type = 'button';
        div.className = 'list-group-item list-group-item-action';
        div.textContent = i.full_name + " (" + i.email + ")";
        div.setAttribute('data-id', i.id);
        div.onclick = function() {
          instructorSearch.value = i.full_name + ' (' + i.email + ')';
          instructorIdField.value = i.id;
          instructorResults.innerHTML = '';
        };
        instructorResults.appendChild(div);
      });
    }
  });

  instructorSearch && instructorSearch.addEventListener('blur', function() {
    setTimeout(function() { instructorResults.innerHTML = ''; }, 120);
  });

  editInstructorForm && editInstructorForm.addEventListener('submit', function(e) {
    if (!instructorSearch.value.trim() || !instructorIdField.value) {
      instructorIdField.value = '';
      instructorSearch.value = '';
    }
  });

});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
