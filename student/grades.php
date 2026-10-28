<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
require __DIR__ . '/../includes/active_academic_year.php';
studentRequireLogin();
studentRequireProfileComplete();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$db = getDb();
$studentId = currentStudentId();

// --- Dynamically Get All Academic Years (and Semesters) available in the system ---
$allAYs = [];
$stmt = $db->query("SELECT ay.id AS ay_id, ay.school_year, ay.semester 
                    FROM academic_years ay 
                    ORDER BY ay.school_year DESC, 
                        CASE ay.semester
                            WHEN '1st' THEN 1
                            WHEN '2nd' THEN 2
                            WHEN 'Summer' THEN 3
                            ELSE 99 END");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $allAYs[] = [
        'ay_id' => (int)$row['ay_id'],
        'school_year' => $row['school_year'],
        'semester' => $row['semester'],
        'display' => $row['school_year'] . ' — ' . $row['semester'],
    ];
}

// --- Determine selected academic year/semester: (preset by GET or default to active AY or first in all) ---
if (isset($_GET['academic_year_id']) && is_numeric($_GET['academic_year_id'])) {
    $selectedAyId = (int)$_GET['academic_year_id'];
    $selectedAY = null;
    foreach ($allAYs as $ay) {
        if ($ay['ay_id'] === $selectedAyId) {
            $selectedAY = [
                'id' => $ay['ay_id'],
                'school_year' => $ay['school_year'],
                'semester' => $ay['semester'],
            ];
            break;
        }
    }
} else {
    $active = getActiveAcademicYear($db);
    // If active matches one of allAYs, use it. Otherwise, pick first ay.
    $selectedAY = null;
    $selectedAyId = null;
    if ($active) {
        foreach ($allAYs as $ay) {
            if (
                $ay['school_year'] == $active['school_year'] &&
                $ay['semester'] == $active['semester']
            ) {
                $selectedAY = [
                    'id' => $ay['ay_id'],
                    'school_year' => $ay['school_year'],
                    'semester' => $ay['semester'],
                ];
                $selectedAyId = $ay['ay_id'];
                break;
            }
        }
    }
    if (!$selectedAY && !empty($allAYs)) {
        $ay = $allAYs[0];
        $selectedAY = [
            'id' => $ay['ay_id'],
            'school_year' => $ay['school_year'],
            'semester' => $ay['semester'],
        ];
        $selectedAyId = $ay['ay_id'];
    }
}

// --- Fetch subjects with grades for the selected AY and this student ---
// We'll filter out lab subjects (ending with "L") IF the non-lab version exists (e.g. show "CSA106" only if both "CSA106" and "CSA106L" are present)
$subjectsWithGrades = [];
$subjectList = [];
$termsList = [];
if ($selectedAY) {
    // Fetch terms for grade headers
    $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];

    // Only show the student's enrollments for this AY
    [$ayWhere, $ayParams] = activeAcademicYearMatch(
        "isub.academic_year_id", 
        "isub.school_year", 
        "isub.semester", 
        $selectedAY
    );
    $stmt = $db->prepare("
      SELECT e.id as enrollment_id, s.code, s.description, isub.school_year, isub.semester
      FROM enrollments e
      LEFT JOIN class_sections cs ON cs.id = e.class_section_id
      LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
      LEFT JOIN subjects s ON s.id = isub.subject_id
      WHERE e.student_id = ?
        AND (isub.id IS NOT NULL AND s.id IS NOT NULL)
        AND $ayWhere
      ORDER BY s.code
    ");
    $stmt->execute(array_merge([$studentId], $ayParams));
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build a map of core codes and full codes for filtering out "L" subjects
    $coreCodeMap = [];
    foreach ($enrollments as $e) {
        if (!empty($e['code'])) {
            $code = $e['code'];
            if (preg_match('/^(.+?)L$/', $code, $match)) {
                $core = $match[1];
                if (!isset($coreCodeMap[$core])) $coreCodeMap[$core] = ['has_main' => false, 'has_lab' => false];
                $coreCodeMap[$core]['has_lab'] = true;
            } else {
                $core = $code;
                if (!isset($coreCodeMap[$core])) $coreCodeMap[$core] = ['has_main' => false, 'has_lab' => false];
                $coreCodeMap[$core]['has_main'] = true;
            }
        }
    }
    // Now build filtered enrollment list and tracking for subjectList/subjectsWithGrades
    foreach ($enrollments as $e) {
        $code = $e['code'];
        $isLab = preg_match('/^(.+?)L$/', $code, $match);
        $core = $isLab ? $match[1] : $code;
        // If this is a lab subject ("L" at end) AND the main/regular subject code exists, skip the lab
        if ($isLab && isset($coreCodeMap[$core]) && $coreCodeMap[$core]['has_main']) {
            continue;
        }
        $eid = (int)$e['enrollment_id'];
        $stmt2 = $db->prepare("
          SELECT tg.term_id, tg.term_grade_computed
          FROM term_grades tg WHERE tg.enrollment_id = ?
        ");
        $stmt2->execute([$eid]);
        $termGrades = [];
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $termGrades[$row['term_id']] = $row['term_grade_computed'];
        }
        $prelim = isset($termGrades[1]) ? (float)$termGrades[1] : null;
        $midterm = isset($termGrades[2]) ? (float)$termGrades[2] : null;
        $final = isset($termGrades[3]) ? (float)$termGrades[3] : null;
        $finalGrade = ($prelim !== null && $midterm !== null && $final !== null)
            ? computeFinalGrade($prelim, $midterm, $final) : null;
        // For subject dropdown. Use code as unique key.
        $subjectList[] = [
            'code' => $e['code'],
            'description' => $e['description'],
        ];

        $subjectsWithGrades[$e['code']] = [
            'code' => $e['code'],
            'description' => $e['description'],
            'school_year' => $e['school_year'],
            'semester' => $e['semester'],
            'prelim' => $prelim,
            'midterm' => $midterm,
            'final' => $final,
            'final_grade' => $finalGrade,
        ];
    }
}

// Select the subject to view. Default to first if not selected or not available in filtered list.
$selectedSubjectCode = isset($_GET['subject_code']) ? $_GET['subject_code'] : null;
$subjectCodes = array_column($subjectList, 'code');
if (!$selectedSubjectCode || !in_array($selectedSubjectCode, $subjectCodes)) {
    $selectedSubjectCode = !empty($subjectList) ? $subjectList[0]['code'] : null;
}

// For page structure
$pageTitle = 'Grades';
$currentPage = 'grades';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Grades</h4>
</div>
<script>
  (function () {
    var refreshMs = 10000;
    setInterval(function () {
      if (!document.hidden) {
        window.location.reload();
      }
    }, refreshMs);
  })();
</script>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" id="gradeForm" class="row g-3 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Academic Year / Semester</label>
        <select name="academic_year_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($allAYs as $ay): ?>
            <option value="<?= (int)$ay['ay_id'] ?>" <?= ((int)$selectedAyId === (int)$ay['ay_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($ay['display']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Subject</label>
        <select name="subject_code" class="form-select" onchange="this.form.submit()">
          <?php foreach ($subjectList as $subj): ?>
            <option value="<?= htmlspecialchars($subj['code']) ?>" <?= $selectedSubjectCode === $subj['code'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($subj['code']) ?> — <?= htmlspecialchars($subj['description']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <!-- No submit button, auto submit on change -->
    </form>
    <?php if ($selectedAY): ?>
      <p class="text-muted small mt-2 mb-0">
        Showing grades for: 
        <strong><?= htmlspecialchars($selectedAY['school_year'] . ' — ' . $selectedAY['semester']) ?></strong>
      </p>
    <?php else: ?>
      <p class="text-muted small mt-2 mb-0">No available academic year/semester in the system.</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($selectedAY && !empty($subjectList) && isset($subjectsWithGrades[$selectedSubjectCode])):
    $s = $subjectsWithGrades[$selectedSubjectCode];
?>
<div class="card">
  <div class="card-header bg-white">
    <strong>Grades — <?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) ?></strong>
    <span class="text-muted ms-2">Prelim 30% · Midterm 30% · Final 40%</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Subject</th>
          <th>Prelim</th>
          <th>Midterm</th>
          <th>Final term</th>
          <th>Final grade</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <strong><?= htmlspecialchars($s['code']) ?></strong>
            <br><span class="text-muted small"><?= htmlspecialchars($s['description']) ?></span>
          </td>
          <td><?= $s['prelim'] !== null ? number_format($s['prelim'], 2) : '—' ?></td>
          <td><?= $s['midterm'] !== null ? number_format($s['midterm'], 2) : '—' ?></td>
          <td><?= $s['final'] !== null ? number_format($s['final'], 2) : '—' ?></td>
          <td><strong><?= $s['final_grade'] !== null ? number_format($s['final_grade'], 2) : '—' ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($selectedAY && empty($subjectList)): ?>
  <p class="text-muted">No grades recorded for this academic year and semester.</p>
<?php elseif (!$selectedAY): ?>
  <p class="text-muted">No available records for your enrollment or grade records for any academic year.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
