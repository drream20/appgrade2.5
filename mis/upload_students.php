

<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';

misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$message = '';
$error = '';

// Get academic years (so upload is tied to a specific AY, like your original page)
$academicYears = [];
$stmt = $db->query("SELECT id, school_year, semester, is_active FROM academic_years ORDER BY school_year DESC, semester DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $academicYears[] = $row;
}

$selectedAyId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : null;
$activeAY = null;
foreach ($academicYears as $ay) {
    if (!empty($ay['is_active'])) {
        $activeAY = $ay;
        break;
    }
}
if (!$selectedAyId && $activeAY) {
    $selectedAyId = (int)$activeAY['id'];
}
if (!$selectedAyId && !empty($academicYears)) {
    $selectedAyId = (int)$academicYears[0]['id'];
}
$selectedAY = null;
foreach ($academicYears as $ay) {
    if ((int)$ay['id'] === $selectedAyId) {
        $selectedAY = $ay;
        break;
    }
}

$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$perPage = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedAY && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $path = $_FILES['file']['tmp_name'];
    $rows = [];

    // Accept course as-is (no constraint for subject code)
    $normalizeCourseKey = function (string $courseRaw): string {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $courseRaw));
    };

    // Enhanced subject code normalization/rewriting logic based on user instructions
    $normalizeSubjectCode = function (string $subjectCodeRaw, string $courseRaw) use ($normalizeCourseKey): string {
        $subjectCodeRaw = trim($subjectCodeRaw);
        if ($subjectCodeRaw === '') {
            return '';
        }

        $effectiveCourseKey = $normalizeCourseKey($courseRaw);
        $codeKey = strtoupper(preg_replace('/\s+/', '', $subjectCodeRaw));

        // EDPCKn rule for BEED/BSED
        if (preg_match('/^EDPCK(\d+)$/i', $codeKey, $m)) {
            if ($effectiveCourseKey === 'BEED' || strpos($effectiveCourseKey, 'BSED') === 0) {
                return 'ED/PCK' . $m[1];
            }
        }

        // New: Remove prefix for "BSHM-HM-PRAC1", "BSBAFM-BA-PRAC", "BSBAFM-NSTP2", etc.
        // The rule is: For subject codes that match BSHM-HM-*, BSBAFM-BA-*, BSBAFM-NSTP*, remove prefix and save only the last portion after the last "-"
        // Detect: if it matches pattern like BSXXX-[XXX-]SOMECODE, strip all up to the last dash
        // Examples:
        //   BSHM-HM-PRAC1 => PRAC1
        //   BSBAFM-BA-PRAC => PRAC
        //   BSBAFM-NSTP2 => NSTP2

        // You can list all prefixes to be stripped for more specificity, but for now, apply general pattern if course is BSHM, BSBAFM, BSHM-HM etc
        $specialPrefixes = [
            'BSHM-HM-',
            'BSBAFM-BA-',
            'BSBAFM-',
            'BEED-',
            'BSA-',
            'BSA-PROFEL3-',
            'BSA-PROFEL4-',
            'BSCRIM-',
            // 'BSCS-',
            'BSEDENG-',
            'BSEDFIL-',
            'BSEDMAT-',
            'BSEDSS-',
            'HM-',
            'ACT-',
       
        ];
        foreach ($specialPrefixes as $prefix) {
            if (stripos($codeKey, strtoupper($prefix)) === 0) {
                // Remove the prefix
                $withoutPrefix = substr($subjectCodeRaw, strlen($prefix));
                // In case there was another dash, only use the portion after last dash
                if (strpos($withoutPrefix, '-') !== false) {
                    $parts = explode('-', $withoutPrefix);
                    $result = end($parts);
                    return $result;
                } else {
                    // No dash, return the remainder
                    return $withoutPrefix;
                }
            }
        }

        // General logic: if code matches "SOMETHING-SOMECODE", where SOMECODE is a known code like PRAC1, NSTP2, etc.
        // Remove everything before the last dash for these special codes (targeted)
        $specials = [
            // Could use a set of endings if needed
            'PRAC1', 'PRAC', 'NSTP2', 'NSTP1',
        ];
        // Separate by last dash and check if the rightmost part matches an entry in specials (case-insensitive)
        if (strpos($subjectCodeRaw, '-') !== false) {
            $parts = explode('-', $subjectCodeRaw);
            $last = strtoupper(end($parts));
            if (in_array($last, array_map('strtoupper', $specials))) {
                return $last;
            }
        }

        return $subjectCodeRaw; // as is, even with special chars
    };

    if (($h = fopen($path, 'r')) !== false) {
        $bom = fread($h, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($h);
        while (($r = fgetcsv($h)) !== false) {
            $rows[] = $r;
        }
        fclose($h);
    }

    if (count($rows) < 2) {
        $error = 'CSV must have header row and at least one data row.';
    } else {
        // Flexible header support, but order must have minimum columns
        $header   = array_map('trim', $rows[0]);
        $expected = ['Student #','Last Name','Given Name','Middle Name','Gender','Birthday','Phone','Course','Subject ID','Section'];

        $mismatch = false;
        for ($i = 0; $i < count($expected); $i++) {
            if (!isset($header[$i]) || strcasecmp($header[$i], $expected[$i]) !== 0) {
                $mismatch = true;
                break;
            }
        }
        if ($mismatch) {
            $error = 'Invalid CSV header. Expected columns: ' . implode(', ', $expected) . '.';
        } else {
            $dataRows = [];
            $validationErrors = [];

            for ($i = 1; $i < count($rows); $i++) {
                $r = $rows[$i];
                // Skip fully empty lines
                if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) {
                    continue;
                }

                $studentNum = isset($r[0]) ? trim((string)$r[0]) : '';
                $lastName   = isset($r[1]) ? trim((string)$r[1]) : '';
                $givenName  = isset($r[2]) ? trim((string)$r[2]) : '';
                $middleName = isset($r[3]) ? trim((string)$r[3]) : '';
                $gender     = isset($r[4]) ? strtoupper(substr(trim((string)$r[4]), 0, 1)) : '';
                $birthday   = isset($r[5]) ? trim((string)$r[5]) : '';
                $phone      = isset($r[6]) ? trim((string)$r[6]) : '';
                $course     = isset($r[7]) ? trim((string)$r[7]) : '';
                $subjectCodesRaw = isset($r[8]) ? trim((string)$r[8]) : '';
                // Remove ALL SPACES from section field before using it
                $section    = isset($r[9]) ? str_replace(' ', '', trim((string)$r[9])) : '';

                $lineNum = $i + 1;
                $err = [];
                if ($studentNum === '') $err[] = 'Missing Student #';
                if ($lastName === '' || $givenName === '') $err[] = 'Missing name (Last/Given)';
                if ($subjectCodesRaw === '') $err[] = 'Missing Subject ID';
                if ($section === '') $err[] = 'Missing Section';

                if (!empty($err)) {
                    $validationErrors[] = "Row $lineNum: " . implode('; ', $err);
                    continue;
                }

                // Accept all subject codes, including special characters, for DB as-is (except special rules above)
                $subjectCodes = array_map('trim', explode(',', $subjectCodesRaw));
                foreach ($subjectCodes as $subjectCode) {
                    $subjectCode = $normalizeSubjectCode($subjectCode, $course);
                    if ($subjectCode === '') {
                        $validationErrors[] = "Row $lineNum: Missing Subject ID";
                        continue;
                    }

                    // 3: Year level from Section (e.g. 1BSCRIM-A -> 1)
                    $yearLevel = 1;
                    if (preg_match('/^(\d+)/', $section, $m)) {
                        $yearLevel = (int)$m[1];
                        if ($yearLevel <= 0) $yearLevel = 1;
                    }

                    // Store subject code ("as is", unless handled by normalization rules above)
                    $dataRows[] = [
                        'studentNum'  => $studentNum,
                        'lastName'    => $lastName,
                        'givenName'   => $givenName,
                        'middleName'  => $middleName,
                        'gender'      => $gender,
                        'birthday'    => $birthday,
                        'phone'       => $phone,
                        'course'      => $course,
                        'subjectCode' => $subjectCode,
                        'section'     => $section,
                        'yearLevel'   => $yearLevel,
                        'dept'        => $course,
                    ];
                }
            }

            // Deduplicate studentNum+subjectCode
            $uniqRows = [];
            foreach ($dataRows as $dr) {
                $k = $dr['studentNum'] . '-' . $dr['subjectCode'];
                if (!isset($uniqRows[$k])) {
                    $uniqRows[$k] = $dr;
                }
            }
            $dataRows = array_values($uniqRows);

            if (!empty($validationErrors)) {
                $error = "File upload aborted due to errors:<br>" . implode("<br>", array_map('htmlspecialchars', $validationErrors));
            } else {
                $db->beginTransaction();
                $studentsCreated = 0;
                $enrollmentsCreated = 0;
                $defaultPasswordHash = hash_password_for_storage(APPGRADE_DEFAULT_PASSWORD);

                try {
                    // Prepared statements
                    $findStudent = $db->prepare("SELECT id, password_hash FROM students WHERE student_id = ?");
                    $insertStudent = $db->prepare("
                        INSERT INTO students
                          (student_id, full_name, email, last_name, given_name, middle_name, gender, course, department, phone, address, password_hash)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $updateStudent = $db->prepare("
                        UPDATE students
                        SET full_name = ?, last_name = ?, given_name = ?, middle_name = ?, gender = ?, course = ?, department = ?, phone = ?
                        WHERE student_id = ?
                    ");

                    // Always INSERT subject as-is (including special characters), no restriction on code value
                    $findSubject = $db->prepare("SELECT id, department FROM subjects WHERE code = ?");
                    $insertSubject = $db->prepare("
                        INSERT INTO subjects (code, title, description, department)
                        VALUES (?, ?, '', ?)
                    ");

                    $findClassSection = $db->prepare("
                        SELECT id
                        FROM class_sections
                        WHERE academic_year_id = ? AND subject_id = ? AND year_level = ? AND section = ?
                    ");
                    $findClassSectionLoose = $db->prepare("
                        SELECT id, section
                        FROM class_sections
                        WHERE academic_year_id = ?
                          AND subject_id = ?
                          AND year_level = ?
                          AND UPPER(REPLACE(section, ' ', '')) = UPPER(REPLACE(?, ' ', ''))
                        LIMIT 1
                    ");
                    $updateClassSectionSection = $db->prepare("UPDATE class_sections SET section = ? WHERE id = ?");
                    $insertClassSection = $db->prepare("
                        INSERT INTO class_sections (academic_year_id, subject_id, year_level, section, department)
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    $findEnrollment = $db->prepare("
                        SELECT id FROM enrollments
                        WHERE class_section_id = ? AND student_id = ?
                    ");
                    $insertEnrollment = $db->prepare("
                        INSERT INTO enrollments (class_section_id, student_id)
                        VALUES (?, ?)
                    ");

                    foreach ($dataRows as $row) {
                        $studentNum  = $row['studentNum'];
                        $lastName    = $row['lastName'];
                        $givenName   = $row['givenName'];
                        $middleName  = $row['middleName'];
                        $gender      = $row['gender'] ?: null;
                        $phone       = $row['phone'] ?: null;
                        $course      = $row['course'] ?: null;
                        $subjectCode = $row['subjectCode']; // as processed above
                        $section     = $row['section'];     // should already have no spaces
                        $yearLevel   = $row['yearLevel'];
                        $dept        = $row['dept'] ?: $row['course'];
                        $fullName = trim($lastName . ', ' . $givenName . ($middleName ? ' ' . $middleName : ''));
                        $email = $studentNum . '@chcc.edu.ph';

                        // 1. Student: no duplicates by student_id
                        $findStudent->execute([$studentNum]);
                        $stuRow = $findStudent->fetch(PDO::FETCH_ASSOC);
                        if ($stuRow) {
                            $studentId = (int)$stuRow['id'];
                            $updateStudent->execute([
                                $fullName,
                                $lastName,
                                $givenName,
                                $middleName,
                                $gender,
                                $course,
                                $dept,
                                $phone,
                                $studentNum,
                            ]);

                            // Auto-upgrade legacy password hashes when re-uploading enrollments.
                            // We only touch password_hash when it's empty or still matches the legacy default
                            // legacy default plaintexts, so custom passwords are not overwritten.
                            $existingHash = (string)($stuRow['password_hash'] ?? '');
                            if (trim($existingHash) === '') {
                                $db->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
                                    ->execute([$defaultPasswordHash, $studentId]);
                            } else {
                                $upgradedHash = maybe_upgrade_legacy_default_password($existingHash);
                                if ($upgradedHash !== null) {
                                    $db->prepare('UPDATE students SET password_hash = ? WHERE id = ?')
                                        ->execute([$upgradedHash, $studentId]);
                                }
                            }
                        } else {
                            $insertStudent->execute([
                                $studentNum,
                                $fullName,
                                $email,
                                $lastName,
                                $givenName,
                                $middleName,
                                $gender,
                                $course,
                                $dept,
                                $phone,
                                null,
                                $defaultPasswordHash,
                            ]);
                            $studentId = (int)$db->lastInsertId();
                            $studentsCreated++;
                        }

                        // 2. Subject (insert as-is, accept all characters)
                        $findSubject->execute([$subjectCode]);
                        $subRow = $findSubject->fetch(PDO::FETCH_ASSOC);
                        if ($subRow) {
                            $subjectId = (int)$subRow['id'];
                            $subjectDept = $subRow['department'] ?? $dept;
                        } else {
                            $insertSubject->execute([$subjectCode, $subjectCode, $dept]);
                            $subjectId = (int)$db->lastInsertId();
                            $subjectDept = $dept;
                        }

                        // 4. Class section
                        $findClassSection->execute([$selectedAY['id'], $subjectId, $yearLevel, $section]);
                        $csRow = $findClassSection->fetch(PDO::FETCH_ASSOC);
                        if ($csRow) {
                            $classSectionId = (int)$csRow['id'];
                        } else {
                            $findClassSectionLoose->execute([$selectedAY['id'], $subjectId, $yearLevel, $section]);
                            $csRowLoose = $findClassSectionLoose->fetch(PDO::FETCH_ASSOC);
                            if ($csRowLoose) {
                                $classSectionId = (int)$csRowLoose['id'];
                                if (($csRowLoose['section'] ?? '') !== $section) {
                                    $updateClassSectionSection->execute([$section, $classSectionId]);
                                }
                            } else {
                                $insertClassSection->execute([$selectedAY['id'], $subjectId, $yearLevel, $section, $subjectDept]);
                                $classSectionId = (int)$db->lastInsertId();
                            }
                        }

                        // 5. Enrollment
                        $findEnrollment->execute([$classSectionId, $studentId]);
                        $enRow = $findEnrollment->fetch(PDO::FETCH_ASSOC);
                        if (!$enRow) {
                            $insertEnrollment->execute([$classSectionId, $studentId]);
                            $enrollmentsCreated++;
                        }
                    }

                    $db->commit();
                    logActivity(
                        'mis',
                        currentMisUserId(),
                        'upload_enrollments',
                        null,
                        null,
                        "Students: $studentsCreated, Enrollments: $enrollmentsCreated for AY " .
                        $selectedAY['school_year'] . " " . $selectedAY['semester']
                    );
                    $message = "Upload complete for " . htmlspecialchars($selectedAY['school_year']) . " " . htmlspecialchars($selectedAY['semester']) .
                               ". $studentsCreated student(s) created/updated, $enrollmentsCreated enrollment(s) added.";
                } catch (Throwable $e) {
                    $db->rollBack();
                    $error = $e->getMessage();
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$selectedAY) {
    $error = 'No active or selected Academic Year. Create and activate one first.';
}

$pageTitle = 'Upload Enrolled Students';
$currentPage = 'upload_students';

$enrolledStudents = [];
$totalStudents = 0;
$totalPages = 1;
if ($selectedAY) {
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM enrollments
        JOIN class_sections ON enrollments.class_section_id = class_sections.id
        WHERE class_sections.academic_year_id = ?
    ");
    $countStmt->execute([$selectedAY['id']]);
    $totalStudents = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalStudents / $perPage));
    $offset = ($page - 1) * $perPage;

    $stmt = $db->prepare("
        SELECT
            s.student_id,
            s.last_name,
            s.given_name,
            s.middle_name,
            s.gender,
            s.course,
            cs.year_level,
            sub.code AS subject_code,
            cs.section,
            cs.department
        FROM enrollments e
        JOIN students s ON e.student_id = s.id
        JOIN class_sections cs ON e.class_section_id = cs.id
        JOIN subjects sub ON cs.subject_id = sub.id
        WHERE cs.academic_year_id = ?
        ORDER BY s.last_name, s.given_name, cs.year_level, cs.section, sub.code
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, (int)$selectedAY['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, (int)$perPage, PDO::PARAM_INT);
    $stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $enrolledStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Upload Enrolled Students</h4>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= $error // might contain <br> ?></div><?php endif; ?>

<form method="get" class="mb-2" autocomplete="off">
  <div class="row g-3 align-items-center">
    <div class="col-auto">
      <label for="ay" class="col-form-label">Academic Year / Semester</label>
    </div>
    <div class="col-auto">
      <select name="academic_year_id" id="ay" class="form-select form-select-sm" onchange="this.form.submit()" required>
        <?php foreach ($academicYears as $ay): ?>
          <option value="<?= htmlspecialchars($ay['id']) ?>"
            <?= (int)$ay['id'] === (int)$selectedAyId ? 'selected' : '' ?>>
            <?= htmlspecialchars($ay['school_year']) ?> <?= htmlspecialchars($ay['semester']) ?>
            <?= $ay['is_active'] ? '(Active)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</form>

<?php if (!$selectedAY): ?>
<div class="alert alert-warning">No academic year/semester selected. <a href="<?= $baseUrl ?>/academic_years.php">Create and activate an academic year</a> before uploading enrollments.</div>
<?php else: ?>
<style>
  .upload-ay-alert {
    font-size: 0.85rem !important;
    margin: 2px 0 10px 0 !important;
    padding: 6px 12px !important;
  }
  @media (max-width: 576px) {
    .upload-ay-alert { font-size: 0.91rem !important; margin-bottom: 12px !important; }
  }
  /* Make all table td inside .make-td-small small text */
  .make-td-small td {
    font-size: .73em;
  }
  .pagination .page-link {
    min-width: 38px;
    text-align: center;
  }
</style>
<div class="alert alert-info mb-2 upload-ay-alert">
  Uploading for <strong>academic year/semester: <?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) ?></strong>.
  New subjects and class sections will be created for this semester as needed.
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="card-header d-flex justify-content-between align-items-center bg-white">
    <span><strong>Student CSV Upload</strong></span>
    <a href="<?= $baseUrl ?>/download_enrollment_template.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download me-1"></i>Download sample template
    </a>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="academic_year_id" value="<?= htmlspecialchars($selectedAyId) ?>">
      <div class="mb-2">
        <!-- Place the file input and submit button on the same row -->
        <div class="row g-2 ">
          <div class="col" style="max-width:70%;">
            <input type="file" name="file" class="form-control" accept=".csv,.txt" required <?= !$selectedAY ? 'disabled' : '' ?> style="width:100%;">
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-primary" style="white-space:nowrap; max-width:100%;"" <?= !$selectedAY ? 'disabled' : '' ?>>
              <i class="bi bi-upload me-1" ></i>Upload Enrollments
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card mb-2">
  <div class="card-header bg-white"><strong>Required CSV Format</strong></div>
  <div class="card-body">
    <p>Upload a CSV with the exact columns and data as shown below; the order may vary:</p>
    <div class="table-responsive">
      <table class="table table-bordered table-sm make-td-small">
        <thead>
          <tr>
            <th>Student #</th>
            <th>Last Name</th>
            <th>Given Name</th>
            <th>Middle Name</th>
            <th>Gender</th>
            <th>Birthday</th>
            <th>Phone</th>
            <th>Course</th>
            <th>Subject ID</th>
            <th>Section</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>37982022</td>
            <td>PAMINTUAN</td>
            <td>M-jay</td>
            <td>Garcia</td>
            <td>M or F</td>
            <td>7/16/2003</td>
            <td>9707838564</td>
            <td>BEED</td>
            <td>BEED-GEC102</td>
            <td>1BSCRIM-A</td>
          </tr>
          <tr>
            <td>37982022</td>
            <td>PAMINTUAN</td>
            <td>M-jay</td>
            <td>Garcia</td>
            <td>M or F</td>
            <td>7/16/2003</td>
            <td>9707838564</td>
            <td>BEED</td>
            <td>BEED-GEC103</td>
            <td>1BSCRIM-A</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p class="mb-2 small text-muted">
      <strong>Notes:</strong>
      <ul class="mb-2">
        <li style="font-size: 12px;">Each row represents one student and their subjects (if multiple subjects, separate them by a comma in the "Subject ID" column, or use multiple rows).</li>
        <!-- Added clarification per your instruction -->
        <li style="font-size: 12px;">Subject IDs like <code>PROFEL4-AC</code> or <code>PROFEL3-AC</code> are accepted as-is (including dashes and other special characters).</li>
        <li style="font-size: 12px;" > If the <strong >Subject ID</strong> contains a dash (like <code style="font-size: 12px;">BSCS-CSP117</code>, <code style="font-size: 12px;">BSEDENG-GEC109</code>, <code style="font-size: 12px;">BSCRIM-CRISOC8</code>), the system will <b>accept the entire code including the prefix and dash</b> as the Subject Code for database storage and matching (such as <b style="font-size: 12px;">PROFEL4-AC</b>).</li>
        <li style="font-size: 12px;">Email is auto-generated as <code>Student#@chcc.edu.ph</code> (e.g. 41992022@chcc.edu.ph).</li>
        <li style="font-size: 12px;">Upload affects only the selected academic year/semester.</li>
        <li style="font-size: 12px; ">All columns shown above are required except Phone and Middle Name, which may be blank.</li>
      </ul>
    </p>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header bg-white"><strong>Enrolled Students for Selected Academic Year / Semester</strong></div>
  <div class="card-body">
    <?php if ($selectedAY): ?>
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle make-td-small">
          <thead>
            <tr>
              <th>Student #</th>
              <th>Name</th>
              <th>Gender</th>
              <th>Course</th>
              <th>Year</th>
              <th>Subject</th>
              <th>Section</th>
              <th>Department</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($enrolledStudents as $row): ?>
            <tr>
              <td><?= htmlspecialchars($row['student_id']) ?></td>
              <td><?= htmlspecialchars("{$row['last_name']}, {$row['given_name']} {$row['middle_name']}") ?></td>
              <td><?= htmlspecialchars($row['gender']) ?></td>
              <td><?= htmlspecialchars($row['course']) ?></td>
              <td><?= htmlspecialchars($row['year_level']) ?></td>
              <td><?= htmlspecialchars($row['subject_code']) ?></td>
              <td><?= htmlspecialchars($row['section']) ?></td>
              <td><?= htmlspecialchars($row['department']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="text-muted">No students to display. Please select an academic year/semester.</p>
    <?php endif; ?>
  </div>
  <!-- User-friendly Paging redesign -->
  <?php if ($selectedAY && $totalPages > 1): ?>
    <nav aria-label="Enrollment table pages">
      <ul class="pagination justify-content-center mt-3 flex-wrap">
        <!-- First and Previous -->
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?academic_year_id=<?= $selectedAyId ?>&page=1" aria-label="First">&laquo;&laquo;</a>
        </li>
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="?academic_year_id=<?= $selectedAyId ?>&page=<?= max(1, $page-1) ?>" aria-label="Previous">&laquo;</a>
        </li>
        <?php
          // Smart paging window: show max 5 page buttons around current page
          $window = 2;    // window size each side from current
          $startPage = max(1, $page - $window);
          $endPage = min($totalPages, $page + $window);

          if ($startPage > 1) {
              // Ellipsis before
              echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
          for ($p = $startPage; $p <= $endPage; $p++):
        ?>
          <li class="page-item <?= ($p == $page) ? 'active' : '' ?>">
            <a class="page-link" href="?academic_year_id=<?= $selectedAyId ?>&page=<?= $p ?>"><?= $p ?></a>
          </li>
        <?php endfor;
          if ($endPage < $totalPages) {
              // Ellipsis after
              echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
          }
        ?>
        <!-- Next and Last -->
        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
          <a class="page-link" href="?academic_year_id=<?= $selectedAyId ?>&page=<?= min($totalPages, $page+1) ?>" aria-label="Next">&raquo;</a>
        </li>
        <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
          <a class="page-link" href="?academic_year_id=<?= $selectedAyId ?>&page=<?= $totalPages ?>" aria-label="Last">&raquo;&raquo;</a>
        </li>
      </ul>
      <div class="text-center text-muted small mb-2">
        Showing page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
        &nbsp;|&nbsp; Total: <strong><?= $totalStudents ?></strong> entries
      </div>
    </nav>
  <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
