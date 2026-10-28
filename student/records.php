<?php
/**
 * Student Records - Assessment & Attendance.
 * Students can view their records based on academic year.
 */
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
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
    // Default to the "active" semester if available, else first
    require_once __DIR__ . '/../includes/active_academic_year.php';
    $active = getActiveAcademicYear($db);
    $selectedAY = null;
    $selectedAyId = null;
    if ($active) {
        foreach ($allAYs as $ay) {
            if ($ay['school_year'] == $active['school_year'] && $ay['semester'] == $active['semester']) {
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

    if (!$selectedAY && count($allAYs) > 0) {
        $ay = $allAYs[0];
        $selectedAyId = $ay['ay_id'];
        $selectedAY = [
            'id' => $ay['ay_id'],
            'school_year' => $ay['school_year'],
            'semester' => $ay['semester'],
        ];
    }
}

if (!$selectedAY) {
    $pageTitle = 'Records';
    $currentPage = 'records';
    ob_start();
    ?>
    <div class="alert alert-warning">No academic years found. Please contact MIS to setup settings.</div>
    <?php
    $content = ob_get_clean();
    require __DIR__ . '/includes/layout.php';
    exit;
}

$ayId = (int)$selectedAY['id'];

// Student's enrollments for the selected academic year
$stmt = $db->prepare("
  SELECT e.id as enrollment_id, s.code, s.description, isub.id as instructor_subject_id
  FROM enrollments e
  LEFT JOIN class_sections cs ON cs.id = e.class_section_id
  LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
  LEFT JOIN subjects s ON s.id = isub.subject_id
  WHERE e.student_id = ?
    AND (isub.id IS NOT NULL AND s.id IS NOT NULL)
    AND (cs.academic_year_id = ? OR isub.academic_year_id = ?)
  ORDER BY s.code
");
$stmt->execute([$studentId, $ayId, $ayId]);
$all_enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter out enrollments whose subject code ends with 'L' if same code exists without 'L'
$enrollments = [];
$mainCodes = [];
foreach ($all_enrollments as $e) {
    $code = trim($e['code']);
    if (substr($code, -1) === 'L') {
        $main = substr($code, 0, -1);
        $mainCodes[$main . ''] = true;
        continue;
    } else {
        $mainCodes[$code . ''] = true;
    }
}
foreach ($all_enrollments as $e) {
    $code = trim($e['code']);
    if (substr($code, -1) === 'L') {
        $main = substr($code, 0, -1);
        if (isset($mainCodes[$main . ''])) {
            continue; // don't include if codeL and main code exist
        } else {
            $enrollments[] = $e;
        }
    } else {
        $enrollments[] = $e;
    }
}

$termsList = [];
$stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];

// --- Auto-select logic for automatic reload ---
$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : null;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : null;
$recordType = $_GET['type'] ?? 'assessment';

// Set defaults if none set yet
$shouldRedirect = false;

if (empty($enrollments)) {
    $subjectInfo = null;
    $enrollmentId = null;
} else {
    // If instructorSubjectId or termId is missing or invalid, set to first valid; trigger reload
    $subjectInfo = null;
    foreach ($enrollments as $e) {
        if ((int)$e['instructor_subject_id'] === $instructorSubjectId) {
            $subjectInfo = $e;
            $enrollmentId = (int)$e['enrollment_id'];
            break;
        }
    }
    if (!$subjectInfo) {
        $subjectInfo = $enrollments[0];
        $instructorSubjectId = (int)$subjectInfo['instructor_subject_id'];
        $enrollmentId = (int)$subjectInfo['enrollment_id'];
        $shouldRedirect = true;
    } else {
        $enrollmentId = (int)$subjectInfo['enrollment_id'];
    }

    // Default first term if missing/invalid
    if (!$termId || !array_key_exists($termId, $termsList)) {
        // Pick first term from $termsList
        reset($termsList);
        $termId = key($termsList);  
        $shouldRedirect = true;
    }

    // Default type
    if (!in_array($recordType, ['assessment', 'attendance'])) {
        $recordType = 'assessment';
        $shouldRedirect = true;
    }
}

// Auto-redirect if defaults applied (to keep URL in sync & for stateless reload)
if ($shouldRedirect) {
    $params = [
        'academic_year_id' => $selectedAyId,
        'instructor_subject_id' => $instructorSubjectId,
        'term_id' => $termId,
        'type' => $recordType
    ];
    $qs = http_build_query($params);
    header("Location: {$_SERVER['PHP_SELF']}?$qs");
    exit;
}

// --- Data fetch ---
$assessmentRecords = [];
$performanceRecords = [];
$assessmentNonExamRecords = [];
$examScores = null;
$labExamScores = null;
$attendanceRecords = [];
if ($enrollmentId && $termId) {
    $stmt = $db->prepare("SELECT * FROM assessment_records WHERE enrollment_id = ? AND term_id = ? ORDER BY recorded_at, id");
    $stmt->execute([$enrollmentId, $termId]);
    $assessmentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT * FROM performance_records WHERE enrollment_id = ? AND term_id = ? ORDER BY recorded_at, id");
    $stmt->execute([$enrollmentId, $termId]);
    $performanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Term examination: exam and optional lab_exam (assessment_records).
    $assessmentNonExamRecords = [];
    foreach ($assessmentRecords as $r) {
        $rt = strtolower(trim((string)($r['record_type'] ?? '')));
        if ($rt === 'exam') {
            $examScores = $r;
            continue;
        }
        if ($rt === 'lab_exam') {
            $labExamScores = $r;
            continue;
        }
        $assessmentNonExamRecords[] = $r;
    }

    try {
        $stmt = $db->prepare("SELECT * FROM attendance_records WHERE enrollment_id = ? AND term_id = ? ORDER BY attendance_date");
        $stmt->execute([$enrollmentId, $termId]);
        $attendanceRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $attendanceRecords = [];
    }
}

$statusLetters = ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'excused' => 'E'];

$pageTitle = 'Records';
$currentPage = 'records';
ob_start();
?>
<!-- Auto-refresh records periodically to show latest changes -->
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
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Records</h4>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end" id="records-autoform" autocomplete="off">
      <div class="col-md-5">
        <label class="form-label">Academic Year / Semester</label>
        <select name="academic_year_id" class="form-select" required onchange="this.form.submit()">
          <?php foreach ($allAYs as $ay): ?>
            <option value="<?= $ay['ay_id'] ?>" <?= $selectedAyId === $ay['ay_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($ay['display']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Subject</label>
        <select name="instructor_subject_id" class="form-select" required onchange="this.form.submit()">
          <option value="">-- Select subject --</option>
          <?php foreach ($enrollments as $e): ?>
            <option value="<?= (int)$e['instructor_subject_id'] ?>" <?= $instructorSubjectId === (int)$e['instructor_subject_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['code'] . ' - ' . $e['description']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select" required onchange="this.form.submit()">
          <option value="">--</option>
          <?php foreach ($termsList as $tid => $tname): ?>
            <option value="<?= $tid ?>" <?= $termId === (int)$tid ? 'selected' : '' ?>><?= htmlspecialchars($tname) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select name="type" class="form-select" onchange="this.form.submit()">
          <option value="assessment" <?= $recordType === 'assessment' ? 'selected' : '' ?>>Assessment</option>
          <option value="attendance" <?= $recordType === 'attendance' ? 'selected' : '' ?>>Attendance</option>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($subjectInfo && $termId): ?>
<div class="card">
  <div class="card-header bg-white">
    <strong><?= htmlspecialchars($subjectInfo['code'] . ' - ' . $subjectInfo['description']) ?></strong> — <?= htmlspecialchars($termsList[$termId] ?? '') ?> — <?= $recordType === 'attendance' ? 'Attendance' : 'Assessment' ?>
  </div>
  <div class="card-body">
    <?php if ($recordType === 'attendance'): ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Status</th>
              <th>Recorded At</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($attendanceRecords)): ?>
              <tr><td colspan="3" class="text-muted text-center py-3">No attendance records yet.</td></tr>
            <?php else: ?>
              <?php foreach ($attendanceRecords as $rec): ?>
                <tr>
                  <td><?= htmlspecialchars($rec['attendance_date']) ?></td>
                  <td>
                    <span class="badge bg-<?= $rec['status'] === 'present' ? 'success' : ($rec['status'] === 'absent' ? 'danger' : ($rec['status'] === 'late' ? 'info' : 'warning')) ?>">
                      <?= $statusLetters[$rec['status']] ?? $rec['status'] ?>
                    </span>
                  </td>
                  <td>
                    <?= htmlspecialchars(isset($rec['created_at']) ? date('Y-m-d H:i', strtotime($rec['created_at'])) : '') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <h6 class="mb-2">Class Standing (quizzes, participation, projects)</h6>
      <div class="table-responsive mb-4">
        <table class="table table-sm table-hover">
          <thead class="table-light">
            <tr>
              <th>Title</th>
              <th>Type</th>
              <th>Score</th>
              <th>Recorded At</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_merge($assessmentNonExamRecords, $performanceRecords) as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['description'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['record_type'] ?? 'performance') ?></td>
                <td><?= number_format((float)($r['score'] ?? 0), 2) ?> / <?= number_format((float)($r['max_score'] ?? 100), 2) ?></td>
                <td>
                  <?= htmlspecialchars(isset($r['created_at']) ? date('Y-m-d H:i', strtotime($r['created_at'])) : '') ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($assessmentNonExamRecords) && empty($performanceRecords)): ?>
              <tr><td colspan="4" class="text-muted">No records yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($examScores || $labExamScores): ?>
        <h6 class="mb-2">Term Examination</h6>
        <?php if ($examScores): ?>
          <p class="mb-1">Exam: <?= number_format((float)$examScores['score'], 2) ?> / <?= number_format((float)$examScores['max_score'], 2) ?></p>
        <?php endif; ?>
        <?php if ($labExamScores): ?>
          <p class="mb-0">Lab exam: <?= number_format((float)$labExamScores['score'], 2) ?> / <?= number_format((float)$labExamScores['max_score'], 2) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php elseif (!empty($enrollments)): ?>
  <p class="text-muted">Select subject and term to view records.</p>
<?php else: ?>
  <p class="text-muted">No enrollments for the selected academic year.</p>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
