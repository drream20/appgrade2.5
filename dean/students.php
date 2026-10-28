<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

// Get current Dean's department (context)
$programDepartment = deanSelectedProgramDepartment();

// Resolve list of courses for this department (e.g. SCS dean => BSCS students only)
$coursesInDepartment = deanCoursesForDepartment($programDepartment);

// Active academic year
$activeAyId = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester FROM academic_years WHERE is_active = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activeAyId = (int) $row['id'];
        $activeAyLabel = trim(($row['school_year'] ?? '') . ' ' . ($row['semester'] ?? ''));
    }
} catch (Throwable $e) {
    $activeAyId = null;
    $activeAyLabel = null;
}

// Filters
$yearLevel = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int) $_GET['year_level'] : null;
$section = isset($_GET['section']) && $_GET['section'] !== '' ? trim($_GET['section']) : null;
$subjectId = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int) $_GET['subject_id'] : null;
$search = trim($_GET['search'] ?? '');

// Dropdown options
$sections = [];
$subjects = [];

if ($activeAyId && !empty($coursesInDepartment)) {
    // Only show sections that actually contain students from this dean's courses
    $courseSectionPlaceholders = [];
    $courseSectionParams = [':ayId' => $activeAyId];
    foreach ($coursesInDepartment as $idx => $courseCode) {
        $key = ':secCourse' . $idx;
        $courseSectionPlaceholders[] = $key;
        $courseSectionParams[$key] = $courseCode;
    }
    $sectionQuery = "
        SELECT DISTINCT cs.year_level, cs.section
        FROM class_sections cs
        JOIN enrollments e ON e.class_section_id = cs.id
        JOIN students st ON st.id = e.student_id
        WHERE cs.academic_year_id = :ayId
          AND st.course IN (" . implode(',', $courseSectionPlaceholders) . ")
        ORDER BY cs.year_level, cs.section
    ";
    $stmt = $db->prepare($sectionQuery);
    $stmt->execute($courseSectionParams);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Only show subjects that actually contain students from this dean's courses
    $courseSubjectPlaceholders = [];
    $courseSubjectParams = [':ayId' => $activeAyId];
    foreach ($coursesInDepartment as $idx => $courseCode) {
        $key = ':subCourse' . $idx;
        $courseSubjectPlaceholders[] = $key;
        $courseSubjectParams[$key] = $courseCode;
    }
    $subjectQuery = "
        SELECT DISTINCT s.id, s.code, s.title
        FROM class_sections cs
        JOIN enrollments e ON e.class_section_id = cs.id
        JOIN students st ON st.id = e.student_id
        JOIN subjects s ON s.id = cs.subject_id
        WHERE cs.academic_year_id = :ayId
          AND st.course IN (" . implode(',', $courseSubjectPlaceholders) . ")
        ORDER BY s.code
    ";
    $stmt = $db->prepare($subjectQuery);
    $stmt->execute($courseSubjectParams);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Main data query (per-enrollment row)
$params = [];
$where = [];
$where[] = "1=1";

if ($activeAyId) {
    $where[] = "cs.academic_year_id = :ayId";
    $params[':ayId'] = $activeAyId;
}

// Main department filter: Only students whose course is in the mapped list of the dean's department
if (!empty($coursesInDepartment)) {
    // IN clause for department filter
    $inClause = [];
    foreach ($coursesInDepartment as $idx => $courseCode) {
        $key = ':course' . $idx;
        $inClause[] = $key;
        $params[$key] = $courseCode;
    }
    // Only fetch students in this department, by course code (strict match, not by section department)
    $where[] = "st.course IN (" . implode(',', $inClause) . ")";
} else {
    // No department; show nothing
    $where[] = "0=1";
}

if ($yearLevel) {
    $where[] = "cs.year_level = :yearLevel";
    $params[':yearLevel'] = $yearLevel;
}
if ($section !== null && $section !== '') {
    $where[] = "cs.section = :section";
    $params[':section'] = $section;
}
if ($subjectId) {
    $where[] = "s.id = :subjectId";
    $params[':subjectId'] = $subjectId;
}
// Expanded the search to all fields
if ($search !== '') {
    $where[] = "("
        . "st.student_id LIKE :search OR "
        . "st.full_name LIKE :search OR "
        . "st.email LIKE :search OR "
        . "cs.year_level LIKE :search OR "
        . "cs.section LIKE :search OR "
        . "s.code LIKE :search OR "
        . "s.title LIKE :search"
        . ")";
    $params[':search'] = '%' . $search . '%';
}
// Removed term filter functionality entirely

$sql = "
    SELECT
      e.id AS enrollment_id,
      st.student_id,
      st.full_name,
      st.email,
      cs.year_level,
      cs.section,
      s.code AS subject_code,
      s.title AS subject_title,
      tg1.term_grade_computed AS prelim,
      tg2.term_grade_computed AS midterm,
      tg3.term_grade_computed AS final,
      COALESCE(absent_counts.absent_count, 0) AS absences
    FROM enrollments e
    JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON s.id = isub.subject_id
    LEFT JOIN term_grades tg1 ON tg1.enrollment_id = e.id AND tg1.term_id = 1
    LEFT JOIN term_grades tg2 ON tg2.enrollment_id = e.id AND tg2.term_id = 2
    LEFT JOIN term_grades tg3 ON tg3.enrollment_id = e.id AND tg3.term_id = 3
    LEFT JOIN (
        SELECT enrollment_id, COUNT(*) AS absent_count
        FROM attendance_records
        WHERE status = 'absent'
        GROUP BY enrollment_id
    ) absent_counts ON absent_counts.enrollment_id = e.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cs.year_level, cs.section, st.full_name, s.code
    LIMIT 200
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute overall grade (Prelim 30%, Midterm 30%, Final 40%)
foreach ($rows as &$r) {
    $p = is_numeric($r['prelim'] ?? null) ? (float) $r['prelim'] : null;
    $m = is_numeric($r['midterm'] ?? null) ? (float) $r['midterm'] : null;
    $f = is_numeric($r['final'] ?? null) ? (float) $r['final'] : null;
    $r['overall'] = ($p !== null && $m !== null && $f !== null) ? computeFinalGrade($p, $m, $f) : null;
}
unset($r);

$pageTitle = 'Students';
$currentPage = 'students';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Student Management (View Only)</h4>
  <?php if (!empty($activeAyLabel)): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-2">
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-select">
          <option value="">All Year</option>
          <?php for ($yl = 1; $yl <= 4; $yl++): ?>
            <option value="<?= $yl ?>" <?= $yearLevel === $yl ? 'selected' : '' ?>><?= $yl ?><?= $yl === 1 ? 'st' : ($yl === 2 ? 'nd' : ($yl === 3 ? 'rd' : 'th')) ?> Year</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Section</label>
        <select name="section" class="form-select">
          <option value="">All Section</option>
          <?php foreach ($sections as $sec): ?>
            <?php $label = ($sec['year_level'] ?? '') . '-' . ($sec['section'] ?? ''); ?>
            <option value="<?= htmlspecialchars($sec['section']) ?>" <?= $section === ($sec['section'] ?? '') ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <select name="subject_id" class="form-select">
          <option value="">All Subject</option>
          <?php foreach ($subjects as $sub): ?>
            <option value="<?= (int)$sub['id'] ?>" <?= $subjectId === (int)$sub['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sub['code'])?>
              <!-- . ' - ' . $sub['title']) -->
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Search</label>
        <div class="input-group">
          <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Any field: ID, Name, Section, Subject, etc">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
      </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
      Showing up to 200 records. Refine filters for more specific results. All information is read-only for the Dean.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-people me-2"></i>Enrolled Students</strong>
    <span class="text-muted small"><?= count($rows) ?> record(s)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <p class="text-muted p-3 mb-0">No records found for the selected filters.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Student ID</th>
              <th>Full Name</th>
              <th>Year &amp; Section</th>
              <th>Subject</th>
              <th>Email</th>
              <th>Prelim</th>
              <th>Midterm</th>
              <th>Final</th>
              <th>Overall (30/30/40)</th>
              <th>Absences</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['student_id']) ?></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td>
                  <?php if ($r['year_level']): ?>
                    <?= (int)$r['year_level'] ?><?= (int)$r['year_level'] === 1 ? 'st' : ((int)$r['year_level'] === 2 ? 'nd' : ((int)$r['year_level'] === 3 ? 'rd' : 'th')) ?> Yr
                    <?= $r['section'] ? ' - ' . htmlspecialchars($r['section']) : '' ?>
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['subject_code']): ?>
                    <strong><?= htmlspecialchars($r['subject_code']) ?></strong><br>
                    <!-- <span class="small text-muted"><?= htmlspecialchars($r['subject_title'] ?? '') ?></span> -->
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
                <td><?= $r['prelim'] !== null ? number_format((float)$r['prelim'], 2) : '—' ?></td>
                <td><?= $r['midterm'] !== null ? number_format((float)$r['midterm'], 2) : '—' ?></td>
                <td><?= $r['final'] !== null ? number_format((float)$r['final'], 2) : '—' ?></td>
                <td><strong><?= $r['overall'] !== null ? number_format((float)$r['overall'], 2) : '—' ?></strong></td>
                <td class="<?= (int)$r['absences'] >= 3 ? 'text-danger fw-semibold' : '' ?>">
                  <?= (int)$r['absences'] ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
