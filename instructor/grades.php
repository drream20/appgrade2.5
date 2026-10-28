<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require __DIR__ . '/../includes/active_academic_year.php';
require_once __DIR__ . '/includes/subject_code_lab.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();

$activeAY = getActiveAcademicYear($db);

// Fetch instructor's subjects (active term only)
$instructorSubjects = [];
if ($activeAY) {
    [$aySql, $ayParams] = activeAcademicYearMatch('isub.academic_year_id', 'isub.school_year', 'isub.semester', $activeAY);
    $stmt = $db->prepare("
      SELECT isub.id as instructor_subject_id, s.code, s.title, isub.school_year, isub.semester
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

// Fetch selected subject and section from GET
$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : null;
$sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : null;

if ($instructorSubjectId) {
    $foundInList = false;
    foreach ($instructorSubjects as $is) {
        if ((int) $is['instructor_subject_id'] === $instructorSubjectId) {
            $foundInList = true;
            break;
        }
    }
    if (!$foundInList) {
        $stmt = $db->prepare('SELECT s.code FROM instructor_subjects isub JOIN subjects s ON s.id = isub.subject_id WHERE isub.id = ? AND isub.instructor_id = ? LIMIT 1');
        $stmt->execute([$instructorSubjectId, $instructorId]);
        $cr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cr || instructor_subject_code_is_lab_suffix($cr['code'] ?? '')) {
            header('Location: ' . $baseUrl . '/grades.php');
            exit;
        }
    }
}

// Build section dropdown options
$sections = [];
$subjectInfo = null;
$students = [];
$termsList = [];

if ($instructorSubjectId) {
    foreach ($instructorSubjects as $is) {
        if ((int)$is['instructor_subject_id'] === $instructorSubjectId) {
            $subjectInfo = $is;
            break;
        }
    }
    if ($subjectInfo) {
        // Get terms
        $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $termsList[$r['id']] = $r['name'];

        // Get sections handled under this instructor_subject
        $stmt = $db->prepare("
          SELECT id, section, year_level
          FROM class_sections
          WHERE instructor_subject_id = ?
          ORDER BY year_level, section
        ");
        $stmt->execute([$instructorSubjectId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Student fetch SQL
        $params = [$instructorSubjectId, $instructorSubjectId];
        $sectionFilterSql = '';
        if ($sectionId) {
            $sectionFilterSql = " AND e.class_section_id = ? ";
            $params[] = $sectionId;
        }
        $sql = "
          SELECT e.id as enrollment_id, st.student_id as student_number, st.full_name,
                 tg1.term_grade_computed as prelim, tg2.term_grade_computed as midterm, tg3.term_grade_computed as final
          FROM enrollments e
          JOIN students st ON st.id = e.student_id
          LEFT JOIN term_grades tg1 ON tg1.enrollment_id = e.id AND tg1.term_id = 1
          LEFT JOIN term_grades tg2 ON tg2.enrollment_id = e.id AND tg2.term_id = 2
          LEFT JOIN term_grades tg3 ON tg3.enrollment_id = e.id AND tg3.term_id = 3
          WHERE (e.instructor_subject_id = ?
             OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ? AND e.status = 'enrolled'))
          $sectionFilterSql
          ORDER BY st.full_name
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate simple final grade (sum only)
        foreach ($students as &$s) {
            $p = is_numeric($s['prelim']) ? (float) $s['prelim'] : null;
            $m = is_numeric($s['midterm']) ? (float) $s['midterm'] : null;
            $f = is_numeric($s['final']) ? (float) $s['final'] : null;
            $s['final_grade'] = ($p !== null && $m !== null && $f !== null) ? $p + $m + $f : null;
        }
        unset($s);
    }
}

$pageTitle = 'Grades';
$currentPage = 'grades';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0">Grades</h4>
  
  <?php if ($subjectInfo): ?>
    <a href="<?= $baseUrl ?>/download_record.php?instructor_subject_id=<?= $instructorSubjectId ?>" class="btn btn-outline-success btn-sm">
      <i class="bi bi-download me-1"></i>Download class record
    </a>
  <?php endif; ?>
</div>
<?php if (!$activeAY): ?>
  <div class="alert alert-warning py-2 mb-2 small">No active semester. Please contact MIS to activate an academic year.</div>
<?php endif; ?>
<div class="alert alert-info py-2 mb-2 small" style="font-size: 11px;">
  <i class="bi bi-info-circle"></i>
 Please select a subject first to display and choose from its available sections. Only sections belonging to the selected subject will appear in the section dropdown.
</div>


<div class="card mb-4">
  <div class="card-body">
    <form method="get" id="gradeFilterForm" class="row g-3 align-items-end">
      <div class="col-md-<?= !empty($sections) ? '6' : '12' ?>">
        <label class="form-label">Subject</label>
        <select name="instructor_subject_id" class="form-select" required id="subjectDropdown">
          <option value="">-- Select subject --</option>
          <?php foreach ($instructorSubjects as $is): ?>
            <option value="<?= $is['instructor_subject_id'] ?>" <?= $instructorSubjectId === (int)$is['instructor_subject_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($is['code'] . ' - ' . $is['title']) ?>
              <?= $is['school_year'] ? ' (' . htmlspecialchars($is['school_year'] . ' ' . $is['semester']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!empty($sections)): ?>
      <div class="col-md-6">
        <label class="form-label">Section</label>
        <select name="section_id" class="form-select" id="sectionDropdown">
          <option value=""><?= htmlspecialchars('All handled sections') ?></option>
          <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['id'] ?>" <?= $sectionId === (int)$sec['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <!-- Remove view grades button for auto-submit -->
    </form>
  </div>
</div>

<script>
  // Auto-submit on subject or section change
  document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('gradeFilterForm');
    const subjectSel = document.getElementById('subjectDropdown');
    const sectionSel = document.getElementById('sectionDropdown');
    if (subjectSel) {
      subjectSel.addEventListener('change', function() {
        // Always reset section on subject change (to all)
        if (sectionSel) sectionSel.selectedIndex = 0;
        form.submit();
      });
    }
    if (sectionSel) {
      sectionSel.addEventListener('change', function() {
        form.submit();
      });
    }
  });
</script>

<?php if ($subjectInfo && !empty($students)): ?>
<div class="card">
  <div class="card-header bg-white">
    <strong><?= htmlspecialchars($subjectInfo['code'] . ' - ' . $subjectInfo['title']) ?></strong>
    <span class="text-muted ms-2">Prelim 30% · Midterm 30% · Final term 40%</span>
    <span class="text-muted ms-2">(Final Grade = Prelim + Midterm + Final)</span>
    <?php if ($sectionId && !empty($sections)): ?>
      <?php foreach ($sections as $sec) {
        if ((int)$sec['id'] === (int)$sectionId) {
          echo '<span class="text-primary ms-2">Section: ' . htmlspecialchars(($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) . '</span>';
          break;
        }
      } ?>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Student ID</th>
          <th>Name</th>
          <th>Prelim</th>
          <th>Midterm</th>
          <th>Final term</th>
          <th>Final grade</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['student_number']) ?></td>
            <td><a href="<?= $baseUrl ?>/student_grade.php?enrollment_id=<?= (int)$s['enrollment_id'] ?>"><?= htmlspecialchars($s['full_name']) ?></a></td>
            <td><?= $s['prelim'] !== null ? number_format((float)$s['prelim'], 2) : '—' ?></td>
            <td><?= $s['midterm'] !== null ? number_format((float)$s['midterm'], 2) : '—' ?></td>
            <td><?= $s['final'] !== null ? number_format((float)$s['final'], 2) : '—' ?></td>
            <td><strong><?= $s['final_grade'] !== null ? number_format($s['final_grade'], 2) : '—' ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php elseif ($subjectInfo): ?>
  <p class="text-muted">No students enrolled or no grades recorded yet.</p>
<?php endif; ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
