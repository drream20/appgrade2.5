<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
studentRequireLogin();
studentRequireProfileComplete();
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

$db = getDb();
$studentId = currentStudentId();
$student = currentStudent();

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

$termsList = [];
$stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];

// Subject rankings: for each subject the student is in, get all classmates and rank by term grade
$subjectRankings = [];
if ($selectedAY) {
    $ayId = (int)$selectedAY['id'];
    [$isubAyWhere, $isubAyParams] = activeAcademicYearMatch('isub.academic_year_id', 'isub.school_year', 'isub.semester', $selectedAY);

    $stmt = $db->prepare("
      SELECT e.id as enrollment_id, e.instructor_subject_id, e.class_section_id,
             s.id as subject_id, s.code, s.title
      FROM enrollments e
      LEFT JOIN class_sections cs ON cs.id = e.class_section_id
      LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
      LEFT JOIN subjects s ON s.id = isub.subject_id
      WHERE e.student_id = ?
        AND (isub.id IS NOT NULL AND s.id IS NOT NULL)
        AND (cs.academic_year_id = ? OR $isubAyWhere)
      ORDER BY s.code
    ");
    $stmt->execute(array_merge([$studentId, $ayId], $isubAyParams));
    $myEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter out subjects that end with 'L' if the non-L (lecture) version is also present
    $filteredEnrollments = [];
    $codesSeen = [];
    foreach ($myEnrollments as $myEnr) {
        $code = $myEnr['code'];
        if (preg_match('/^(.*)L$/i', $code, $matches)) {
            $baseCode = $matches[1]; // e.g. CSA106 from CSA106L
            $codesSeen[$code] = [
                'is_lab' => true,
                'base' => rtrim($baseCode),
            ];
        } else {
            $codesSeen[$code] = [
                'is_lab' => false,
                'base' => $code,
            ];
        }
    }
    foreach ($myEnrollments as $myEnr) {
        $code = $myEnr['code'];
        if (
            preg_match('/^(.*)L$/i', $code, $matches)
            && array_key_exists(rtrim($matches[1]), $codesSeen)
        ) {
            // Skip this lab section if the lecture is present
            continue;
        }
        $filteredEnrollments[] = $myEnr;
    }

    foreach ($filteredEnrollments as $myEnr) {
        $isubId = (int)($myEnr['instructor_subject_id'] ?? 0);
        $csId = (int)($myEnr['class_section_id'] ?? 0);

        $enrollmentIds = [];
        if ($isubId) {
            $q = $db->prepare("SELECT id FROM enrollments WHERE instructor_subject_id = ?");
            $q->execute([$isubId]);
        } else {
            $q = $db->prepare("SELECT id FROM enrollments WHERE class_section_id = ?");
            $q->execute([$csId]);
        }
        while ($row = $q->fetch(PDO::FETCH_ASSOC)) $enrollmentIds[] = (int)$row['id'];
        if (empty($enrollmentIds)) continue;

        $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
        $byTerm = [];

        foreach ([1, 2, 3] as $termId) {
            $stmt = $db->prepare("
              SELECT e.student_id, tg.term_grade_computed
              FROM term_grades tg
              JOIN enrollments e ON e.id = tg.enrollment_id
              WHERE tg.enrollment_id IN ($placeholders) AND tg.term_id = ? AND tg.term_grade_computed IS NOT NULL
              ORDER BY tg.term_grade_computed DESC, e.student_id ASC
            ");
            $stmt->execute(array_merge($enrollmentIds, [$termId]));
            $rank = 0;
            $myRank = null;
            $myGrade = null;
            $classSize = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rank++;
                $classSize++;
                if ((int) $row['student_id'] === $studentId) {
                    $myRank = $rank;
                    $myGrade = $row['term_grade_computed'] !== null ? (float) $row['term_grade_computed'] : null;
                }
            }
            $byTerm[$termId] = [
                'rank' => $myRank,
                'grade' => $myGrade,
                'class_size' => $classSize,
            ];
        }

        $subjectRankings[] = [
            'code' => $myEnr['code'],
            'title' => $myEnr['title'],
            'by_term' => $byTerm,
            'panel_id' => 'subj-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $myEnr['code']) . '-' . (int) $myEnr['enrollment_id'],
        ];
    }
}

$pageTitle = 'Class Ranking';
$currentPage = 'ranking';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Class Ranking by Grades</h4>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" id="ayForm" class="row g-3 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label">Academic Year / Semester</label>
        <select name="academic_year_id" class="form-select" onchange="this.form.submit()">
          <?php foreach ($allAYs as $ay): ?>
            <option value="<?= (int)$ay['ay_id'] ?>" <?= ((int)$selectedAyId === (int)$ay['ay_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($ay['display']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <p class="text-muted small mt-2 mb-0">You only see your own rank and grade for each subject and term. Classmate names and grades are not shown.</p>
  </div>
</div>

<?php if ($selectedAY && !empty($subjectRankings)): ?>
  <?php foreach ($subjectRankings as $subj): ?>
  <?php $pid = $subj['panel_id'] ?? ('subj-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) $subj['code'])); ?>
  <div class="card mb-4">
    <div class="card-header bg-white">
      <strong><?= htmlspecialchars($subj['code']) ?></strong> — <?= htmlspecialchars($subj['title']) ?>
    </div>
    <div class="card-body p-0">
      <ul class="nav nav-tabs px-3 pt-2" role="tablist">
        <?php foreach ([1 => 'Prelim', 2 => 'Midterm', 3 => 'Final'] as $tid => $tname): ?>
        <li class="nav-item">
          <button class="nav-link <?= $tid === 1 ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#<?= htmlspecialchars($pid) ?>-<?= (int) $tid ?>" type="button"><?= htmlspecialchars($tname) ?></button>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="tab-content p-3">
        <?php foreach ([1, 2, 3] as $tid): ?>
        <?php $t = $subj['by_term'][$tid] ?? ['rank' => null, 'grade' => null, 'class_size' => 0]; ?>
        <div class="tab-pane fade <?= $tid === 1 ? 'show active' : '' ?>" id="<?= htmlspecialchars($pid) ?>-<?= (int) $tid ?>">
          <?php if ((int) ($t['class_size'] ?? 0) === 0): ?>
            <p class="text-muted mb-0">No grades recorded for this term yet.</p>
          <?php elseif ($t['rank'] === null): ?>
            <p class="text-muted mb-0">Your grade for this term is not recorded yet, so your rank is not available.</p>
          <?php else: ?>
            <p class="mb-2 fs-5">
              You are <strong>rank <?= (int) $t['rank'] ?></strong> in your class<?= (int) $t['class_size'] > 0 ? ' <span class="text-muted">(out of ' . (int) $t['class_size'] . ' with grades)</span>' : '' ?>.
            </p>
            <p class="mb-0 text-muted">Your grade: <strong class="text-dark"><?= number_format((float) $t['grade'], 2) ?></strong></p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php elseif ($selectedAY): ?>
  <p class="text-muted">No enrollments or grades for this academic year.</p>
<?php else: ?>
  <p class="text-muted">No available academic year/semester in the system.</p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
