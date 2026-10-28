<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();
// Program context
$programDepartment = deanSelectedProgramDepartment();
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

// Active academic year
$activeAyId = null;
$activeAyLabel = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester FROM academic_years WHERE is_active = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activeAyId = (int) $row['id'];
        $activeAyLabel = trim(($row['school_year'] ?? '') . ' ' . ($row['semester'] ?? ''));
    }
} catch (Throwable $e) {
    $activeAyId = null;
}

// Terms
$terms = [];
try {
    $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $terms[(int)$r['id']] = $r['name'];
    }
} catch (Throwable $e) {}

// Filters
$yearLevel = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int) $_GET['year_level'] : null;
$termId = isset($_GET['term_id']) && $_GET['term_id'] !== '' ? (int) $_GET['term_id'] : null;

// If both filters are empty, display ALL students with 3+ absences (not limited to active AY/program dept)
$showAll = ($yearLevel === null && $termId === null);

// Build query conditions
$where = ["ar.status = 'absent'"];
$params = [];

// Program scope always applies (access boundary, not a user filter).
// Match by student course codes, class_section department, or subject department
// to cover all naming variations — same approach as sections.php / class_records.php.
if ($programDepartment) {
    $deptExpr = [];

    if (!empty($programDeptFilters)) {
        $csDeptPlaceholders = [];
        foreach ($programDeptFilters as $idx => $deptVal) {
            $key = ':dept_cs_' . $idx;
            $csDeptPlaceholders[] = $key;
            $params[$key] = $deptVal;
        }
        $deptExpr[] = "cs.department IN (" . implode(',', $csDeptPlaceholders) . ")";
    }

    if (!empty($programCourses)) {
        $courseIn = [];
        foreach ($programCourses as $idx => $courseCode) {
            $key = ':course_' . $idx;
            $courseIn[] = $key;
            $params[$key] = $courseCode;
        }
        $deptExpr[] = "st.course IN (" . implode(',', $courseIn) . ")";
    }

    if (!empty($deptExpr)) {
        $where[] = "(" . implode(' OR ', $deptExpr) . ")";
    } else {
        $where[] = "0=1";
    }
}

if (!$showAll) {
    if ($activeAyId) {
        $where[] = "cs.academic_year_id = :ayId";
        $params[':ayId'] = $activeAyId;
    }
    if ($yearLevel) {
        $where[] = "cs.year_level = :yearLevel";
        $params[':yearLevel'] = $yearLevel;
    }
    if ($termId) {
        $where[] = "ar.term_id = :termId";
        $params[':termId'] = $termId;
    }
}
$sql = "
    SELECT
      st.student_id,
      st.full_name,
      st.email,
      cs.year_level,
      cs.section,
      s.code AS subject_code,
      s.title AS subject_title,
      ar.term_id,
      COUNT(*) AS absences
    FROM attendance_records ar
    JOIN enrollments e ON e.id = ar.enrollment_id
    JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON s.id = isub.subject_id
    " . (count($where) ? ('WHERE ' . implode(' AND ', $where)) : '') . "
    GROUP BY
      st.student_id, st.full_name, st.email,
      cs.year_level, cs.section,
      s.code, s.title,
      ar.term_id
    HAVING COUNT(*) >= 3
    ORDER BY cs.year_level, cs.section, st.full_name, s.code
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Attendance Monitoring';
$currentPage = 'attendance';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Sections Monitoring </h4>
  <?php if ($activeAyLabel): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <?php if (!empty($_GET['program'])): ?>
        <input type="hidden" name="program" value="<?= htmlspecialchars((string)$_GET['program']) ?>">
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-select">
          <option value="">All Year Levels</option>
          <?php for ($yl = 1; $yl <= 4; $yl++): ?>
            <option value="<?= $yl ?>" <?= $yearLevel === $yl ? 'selected' : '' ?>>
              <?= $yl ?><?= $yl === 1 ? 'st' : ($yl === 2 ? 'nd' : ($yl === 3 ? 'rd' : 'th')) ?> Year
            </option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select">
          <option value="">All Terms</option>
          <?php foreach ($terms as $id => $name): ?>
            <option value="<?= $id ?>" <?= $termId === (int)$id ? 'selected' : '' ?>>
              <?= htmlspecialchars($name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button type="submit" class="btn btn-primary mt-4 w-100"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
      </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
      List shows students with 3 or more recorded absences per subject.
      <?php if ($showAll): ?>
        All students across all academic years, terms, and departments are shown.
      <?php else: ?>
        Use filters to focus by year level and term (Prelim, Midterm, Final).
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-clipboard-data me-2"></i>Students with 3+ Absences</strong>
    <span class="text-muted small"><?= count($rows) ?> record(s)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <p class="text-muted p-3 mb-0">No students found with 3 or more absences for the selected filters.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Student ID</th>
              <th>Full Name</th>
              <th>Year &amp; Section</th>
              <th>Subject</th>
              <th>Term</th>
              <th>Absences</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['student_id']) ?></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td>
                  <?php if (!empty($r['year_level'])): ?>
                    <?= (int)$r['year_level'] ?><?= (int)$r['year_level'] === 1 ? 'st' : ((int)$r['year_level'] === 2 ? 'nd' : ((int)$r['year_level'] === 3 ? 'rd' : 'th')) ?> Yr
                    <?= $r['section'] ? ' - ' . htmlspecialchars($r['section']) : '' ?>
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (!empty($r['subject_code'])): ?>
                    <strong><?= htmlspecialchars($r['subject_code']) ?></strong><br>
                    <span class="small text-muted"><?= htmlspecialchars($r['subject_title'] ?? '') ?></span>
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php
                  $tid = (int)($r['term_id'] ?? 0);
                  echo htmlspecialchars($terms[$tid] ?? 'N/A');
                  ?>
                </td>
                <td class="text-danger fw-semibold">
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
