<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();

// Academic year list for filter dropdown
$academicYears = [];
$stmtAYs = $db->query("SELECT id, school_year, semester, is_locked FROM academic_years ORDER BY school_year DESC, semester DESC");
while ($row = $stmtAYs->fetch(PDO::FETCH_ASSOC)) {
    $academicYears[(int)$row['id']] = $row;
}

// Academic year filter: allow user to select AY/semester
$activeAY = getActiveAcademicYear($db);
$defaultAcademicYearId = $activeAY ? (int)$activeAY['id'] : null;
$selectedAcademicYearId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : $defaultAcademicYearId;

$selectedAY = null;
if ($selectedAcademicYearId && isset($academicYears[$selectedAcademicYearId])) {
    $selectedAY = $academicYears[$selectedAcademicYearId];
} else {
    $selectedAY = $activeAY;
    $selectedAcademicYearId = $defaultAcademicYearId;
}

$selectedAyId = $selectedAY ? (int)$selectedAY['id'] : null;
$selectedSemester = $selectedAY ? $selectedAY['semester'] : null;

// --- PARAM HANDLING SECTION (All queries depend on selected academic year) ---
$ayFilter = '';
$params_stats = [];
$params_subjects = [];
$params_sections = [];
$params_top = [];
$params_analytics = [];

// Always start with instructorId as first param for all queries
$params_stats[] = $instructorId;
$params_subjects[] = $instructorId;
$params_sections[] = $instructorId;
$params_top[] = $instructorId;
$params_analytics[] = $instructorId;

if ($selectedAY && $selectedSemester !== null) {
    $ayFilter = " AND (isub.academic_year_id = ? OR (isub.academic_year_id IS NULL AND isub.school_year = ? AND isub.semester = ?))";
    // For stats
    $params_stats[] = $selectedAyId;
    $params_stats[] = $selectedAY['school_year'];
    $params_stats[] = $selectedSemester;
    $params_stats[] = $instructorId;
    $params_stats[] = $selectedAyId;
    $params_stats[] = $selectedAY['school_year'];
    $params_stats[] = $selectedSemester;
    // For subjects
    $params_subjects[] = $selectedAyId;
    $params_subjects[] = $selectedAY['school_year'];
    $params_subjects[] = $selectedSemester;
    // For sections
    $params_sections[] = $selectedAyId;
    $params_sections[] = $selectedAY['school_year'];
    $params_sections[] = $selectedSemester;
    // For top students and analytics
    $params_top[] = $selectedAyId;
    $params_top[] = $selectedAY['school_year'];
    $params_top[] = $selectedSemester;
    $params_analytics[] = $selectedAyId;
    $params_analytics[] = $selectedAY['school_year'];
    $params_analytics[] = $selectedSemester;
} else {
    // No active term: show no data to match system-wide term control
    $ayFilter = " AND 1=0";
    // For stats
    $params_stats[] = $instructorId;
}

// Stats
$stmt = $db->prepare("
  SELECT COUNT(DISTINCT isub.id) as total_subjects,
         (SELECT COUNT(*) FROM enrollments e JOIN instructor_subjects isub2 ON e.instructor_subject_id = isub2.id
          WHERE isub2.instructor_id = ? $ayFilter) as total_students
  FROM instructor_subjects isub
  WHERE isub.instructor_id = ? $ayFilter
");
$stmt->execute($params_stats);
$stats = $stmt->fetch();
if (!$stats) {
    $stats = ['total_subjects' => 0, 'total_students' => 0];
}

// My Subjects Table - from schedule description
$stmt = $db->prepare("
  SELECT 
    isub.id as instructor_subject_id, 
    s.code, 
    sc.description, 
    COUNT(e.id) as enrolled
  FROM instructor_subjects isub
  INNER JOIN subjects s ON s.id = isub.subject_id
  LEFT JOIN schedule sc ON sc.instructor_subject_id = isub.id
  LEFT JOIN enrollments e ON e.instructor_subject_id = isub.id
  WHERE isub.instructor_id = ? $ayFilter
  GROUP BY isub.id, s.code, sc.description
  ORDER BY s.code
");
$stmt->execute($params_subjects);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Section filter - only sections from selected academic year/semester
$sectionId = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$sections = [];
if ($selectedAY && $selectedSemester !== null) {
    $stmtSec = $db->prepare("
      SELECT cs.id, cs.section, cs.year_level, cs.department, s.code AS subject_code
      FROM class_sections cs
      INNER JOIN instructor_subjects isub ON cs.instructor_subject_id = isub.id
      INNER JOIN subjects s ON isub.subject_id = s.id
      WHERE isub.instructor_id = ? $ayFilter
      ORDER BY s.code, cs.year_level, cs.section
    ");
    $stmtSec->execute($params_sections);
    $sections = $stmtSec->fetchAll(PDO::FETCH_ASSOC);
}

// Students with ≥3 absences, filterable by section and week/month window
$absenceScope = $_GET['absence_scope'] ?? 'week';
if (!in_array($absenceScope, ['week', 'month'], true)) {
    $absenceScope = 'week';
}
$absenceDate = $_GET['absence_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $absenceDate)) {
    $absenceDate = date('Y-m-d');
}

try {
    $ref = new DateTime($absenceDate);
} catch (Throwable $e) {
    $ref = new DateTime();
}

if ($absenceScope === 'month') {
    $startDate = $ref->format('Y-m-01');
    $endDate = $ref->format('Y-m-t');
    $absenceWindowLabel = $ref->format('F Y');
} else {
    $weekRef = clone $ref;
    $dow = (int)$weekRef->format('N');
    $weekRef->modify('-' . ($dow - 1) . ' days');
    $startDate = $weekRef->format('Y-m-d');
    $weekRef->modify('+6 days');
    $endDate = $weekRef->format('Y-m-d');
    $absenceWindowLabel = 'Week of ' . date('M d, Y', strtotime($startDate));
}

$absenceFilterSection = '';
$absenceParams = $params_top;
$absenceParams[] = $startDate;
$absenceParams[] = $endDate;
if ($sectionId) {
    $absenceFilterSection = 'AND cs.id = ?';
    $absenceParams[] = $sectionId;
}

$stmtAbs = $db->prepare("
  SELECT 
    st.full_name,
    st.student_id,
    s.code AS subject_code,
    cs.section AS section_name,
    COUNT(*) AS absences
  FROM attendance_records ar
    INNER JOIN enrollments e ON e.id = ar.enrollment_id
    INNER JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON e.class_section_id = cs.id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON isub.subject_id = s.id
  WHERE isub.instructor_id = ? $ayFilter
    AND ar.status = 'absent'
    AND ar.attendance_date BETWEEN ? AND ?
    $absenceFilterSection
  GROUP BY st.full_name, st.student_id, s.code, cs.section
  HAVING COUNT(*) >= 3
  ORDER BY cs.section, st.full_name
");
$stmtAbs->execute($absenceParams);
$absenceStudents = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
ob_start();
?>

<!-- Filters Row: Page Filter and Academic Year/Semester Filter -->
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap" style="gap: 0.5rem;">
  <h4 class="mb-0">Dashboard</h4>
  <form method="get" class="d-flex align-items-center" id="aySemesterFilterForm" style="gap:.5rem;">
    <!-- Academic Year + Semester filter -->
    <label for="academic_year_id" class="form-label mb-0 small">Academic Year/Semester:</label>
    <select name="academic_year_id" id="academic_year_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:180px;">
      <?php foreach ($academicYears as $ayId => $ay): ?>
        <option value="<?= $ayId ?>" <?= $selectedAcademicYearId == $ayId ? 'selected' : '' ?>>
          <?= htmlspecialchars($ay['school_year'] . ' ' . $ay['semester']) ?><?= !empty($ay['is_locked']) ? ' (Locked)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php
      // Retain other filter params on submit, but reset page for analytics/AY
      foreach ($_GET as $key => $val) {
        if ($key !== 'academic_year_id') {
          if (is_array($val)) {
            foreach ($val as $item) {
              echo '<input type="hidden" name="'.htmlspecialchars($key).'[]" value="'.htmlspecialchars($item).'">';
            }
          } else {
            echo '<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($val).'">';
          }
        }
      }
    ?>
  </form>
</div>

<div class="mb-4">
  <?php if ($selectedAY): ?>
    <div class="text-muted small">Active term: <strong><?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) ?></strong></div>
  <?php else: ?>
    <div class="alert alert-warning py-2 mb-0 small">No active semester. Please contact MIS to activate an academic year.</div>
  <?php endif; ?>
</div>

<?php if ($selectedAY): ?>
<div class="alert alert-success py-2 mb-3 small">
  Showing subjects and schedule for <strong><?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedSemester) ?></strong>.
  <?= !empty($selectedAY['is_locked']) ? ' <span class="text-danger">This semester is locked; you can view but not edit records.</span>' : ' You can record grades and attendance (unlocked).' ?>
</div>
<?php endif; ?>

<div class="row g-2 mb-2">
  <!-- "My Subjects" (left) and "Top Students" (right) side by side, equal heights -->
  <div class="col-12 col-lg-6 d-flex flex-column">
    <div class="row g-2 mb-2"></div>
    <!-- My Subjects Table with full height  -->
    <div class="card flex-grow-1 d-flex" style="min-height:300px;max-height:600px;">
      <div class="card-header bg-white"><strong>My Subjects</strong></div>
      <div class="card-body p-0 d-flex flex-column" style="overflow-y:auto;">
        <?php if (empty($subjects)): ?>
          <p class="text-muted p-3 mb-0">No subjects assigned. Contact admin.</p>
        <?php else: ?>
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th style="background-color:rgb(28, 77, 146); color:white;">Code</th>
                <th style="background-color:rgb(28, 77, 146); color:white;">Description</th>
                <th style="background-color:rgb(28, 77, 146); color:white;">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subjects as $s): ?>
                <tr>
                  <td><?= htmlspecialchars($s['code']) ?></td>
                  <td><?= htmlspecialchars($s['description']) ?></td>
                  <td><a href="<?= $baseUrl ?>/records.php?academic_year_id=<?= $selectedAcademicYearId ?>" class="btn btn-sm btn-outline-primary">Start Recording</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <!-- Students with ≥3 absences side-by-side, equal height to My Subjects -->
  <div class="col-12 col-lg-6 d-flex flex-column">
    <div class="card flex-grow-1 d-flex mb-0 mb-lg-0" style="min-height:600px;max-height:520px;">
      <div class="card-header bg-white pb-1 pt-2 d-flex align-items-center flex-wrap" style="gap:.5rem;" >
        <div class="me-auto">
          <strong>Students with ≥3 absences</strong>
          <div class="text-muted small">
            <?= htmlspecialchars($absenceWindowLabel) ?>
          </div>
        </div>
        <form method="get"
              class="d-flex align-items-center flex-wrap flex-md-nowrap w-100 justify-content-md-end"
              id="absencesForm"
              style="gap: .55rem;">
          <div class="d-flex w-100 flex-row flex-md-row align-items-center justify-content-between justify-content-md-end" style="gap: .55rem;">
            <input type="hidden" name="academic_year_id" value="<?= htmlspecialchars($selectedAcademicYearId) ?>">
            <label class="form-label mb-0 small d-none d-sm-inline"></label>
            <select name="section_id" class="form-select form-select-sm" style="max-width:150px;" onchange="this.form.submit()">
              <option value="">All Sections</option>
              <?php foreach ($sections as $sec): ?>
                <option value="<?= $sec['id'] ?>" <?= $sectionId && $sectionId == $sec['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars(($sec['subject_code'] ? $sec['subject_code'] . ' - ' : '') . ($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <select name="absence_scope" class="form-select form-select-sm" style="max-width:110px;" onchange="this.form.submit()">
              <option value="week" <?= $absenceScope === 'week' ? 'selected' : '' ?>>This Week</option>
              <option value="month" <?= $absenceScope === 'month' ? 'selected' : '' ?>>This Month</option>
            </select>
            <input type="date" name="absence_date" value="<?= htmlspecialchars($absenceDate) ?>" class="form-control form-control-sm" style="max-width:110px;" onchange="this.form.submit()">
          </div>
        </form>
        <style>
          @media (max-width: 575.98px) {
            #absencesForm .form-label {
              display: none !important;
            }
            #absencesForm .d-flex.w-100 {
              flex-direction: row !important;
              flex-wrap: nowrap !important;
              justify-content: space-between !important;
              align-items: center !important;
              gap: .55rem !important;
              width: 100% !important;
            }
            #absencesForm select,
            #absencesForm input[type="date"] {
              min-width: 0;
              width: 32%;
              max-width: unset !important;
            }
          }
        </style>
      </div>
      <div class="card-body py-2 px-2 d-flex flex-column" style="overflow-x:auto; overflow-y:auto;">
        <?php if (empty($absenceStudents)): ?>
          <span class="text-muted">No students with 3 or more absences in this period.</span>
        <?php else: ?>
          <table class="table table-sm table-bordered mb-0 text-center align-middle">
            <thead class="table-light">
              <tr>
                <th>Name</th>
                <th>ID</th>
                <th>Subject</th>
                <th>Section</th>
                <th>Absences</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($absenceStudents as $s): ?>
              <tr>
                <td class="text-start"><?= htmlspecialchars($s['full_name']) ?></td>
                <td><?= htmlspecialchars($s['student_id']) ?></td>
                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                <td><?= htmlspecialchars($s['section_name']) ?></td>
                <td class="fw-bold text-danger"><?= (int)$s['absences'] ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Analytics: Absences per Week -->
<div class="row g-2 mb-2 " style="min-height:400px;max-height:520px;">
  <div class="col-12">
    <div class="card">
      <div class="card-header bg-white d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
        <div class="d-flex align-items-center flex-wrap" style="gap:.5rem;">
          <strong>Absences per Week</strong>
          <div class="text-muted small">
            Based on attendance records (A = Absent)
          </div>
        </div>
        <form method="get" class="d-flex align-items-center ms-2" id="analyticsAbsencesForm" style="gap:.35rem;">
          <input type="hidden" name="academic_year_id" value="<?= htmlspecialchars($selectedAcademicYearId) ?>">
          <label class="form-label mb-0 small d-none d-sm-inline">Section:</label>
          <select name="section_id" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width:170px;">
            <option value="">All Sections</option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?= $sec['id'] ?>" <?= $sectionId && $sectionId == $sec['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(($sec['subject_code'] ? $sec['subject_code'] . ' - ' : '') . ($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="card-body">
        <div id="absencesWeeklyChart" style="width:100%;min-height:320px;"></div>
        <div id="absencesWeeklyChartEmpty" class="text-muted small d-none">No absence records found for the selected filters.</div>
      </div>
    </div>
  </div>
</div>

<script src="https://www.gstatic.com/charts/loader.js"></script>
<script>
(function () {
  // we want to connect the chart analytics to the academic year filter too.
  // read academic year and section from current URL params, not just PHP-side
  function getUrlParam(name) {
    var params = new URLSearchParams(window.location.search);
    return params.get(name);
  }

  function parseIntOrZero(v) {
    var n = parseInt(v, 10);
    return isNaN(n) ? 0 : n;
  }

  function getCurrentAcademicYearId() {
    // Try from query string, fallback to PHP-side JS val
    var val = getUrlParam('academic_year_id');
    if (val !== null) return parseIntOrZero(val);
    return <?= (int)$selectedAyId ?>;
  }

  function getCurrentSectionId() {
    var val = getUrlParam('section_id');
    if (val !== null && val !== '') return parseIntOrZero(val);
    return 0;
  }

  var baseUrl = <?= json_encode($baseUrl) ?>;
  var cachedRows = null;
  var chart = null;

  function showEmpty(msg) {
    var el = document.getElementById('absencesWeeklyChartEmpty');
    if (!el) return;
    if (msg) el.textContent = msg;
    el.classList.remove('d-none');
  }

  function hideEmpty() {
    var el = document.getElementById('absencesWeeklyChartEmpty');
    if (!el) return;
    el.classList.add('d-none');
  }

  function fetchWeeklyAbsences(ayId, sectionId) {
    var url = baseUrl + '/analytics_absences_weekly.php?academic_year_id=' + encodeURIComponent(ayId);
    if (sectionId) url += '&section_id=' + encodeURIComponent(sectionId);
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function draw(rows) {
    var container = document.getElementById('absencesWeeklyChart');
    if (!container) return;

    if (!rows || !rows.length) {
      container.innerHTML = '';
      showEmpty('No absence records found for the selected filters.');
      return;
    }

    hideEmpty();
    var data = new google.visualization.DataTable();
    data.addColumn('string', 'Week');
    data.addColumn('number', 'Absences');

    rows.forEach(function (r) {
      var label = r.week_start ? String(r.week_start) : '';
      data.addRow([label, Number(r.absences || 0)]);
    });

    var options = {
      height: 320,
      legend: { position: 'none' },
      colors: ['#dc3545'],
      chartArea: { left: 55, right: 20, top: 20, bottom: 55, width: '100%', height: '70%' },
      hAxis: { title: 'Week start (Mon)', slantedText: true, slantedTextAngle: 35 },
      vAxis: { title: 'Total absences', minValue: 0, format: '0' },
      bar: { groupWidth: '70%' }
    };

    chart = new google.visualization.ColumnChart(container);
    chart.draw(data, options);
  }

  function init() {
    var ayId = getCurrentAcademicYearId();
    var sectionId = getCurrentSectionId();
    if (!ayId) {
      showEmpty('No active semester. Contact MIS to activate an academic year.');
      return;
    }

    google.charts.load('current', { packages: ['corechart'] });
    google.charts.setOnLoadCallback(function () {
      // Don't cache result per page, for proper AY switching
      fetchWeeklyAbsences(ayId, sectionId)
        .then(function (res) {
          if (!res || !res.ok) {
            showEmpty('Unable to load analytics.');
            return;
          }
          cachedRows = res.rows || [];
          draw(cachedRows);
        })
        .catch(function () {
          showEmpty('Unable to load analytics.');
        });
    });
  }

  // Run on load and on academic_year dropdown change
  document.addEventListener('DOMContentLoaded', function () {
    init();
    // To also redraw on URL changes (e.g., filter), use a Mutation/URL observer
    // but since form submits reload, this is sufficient.
  });
  window.addEventListener('resize', function () {
    if (chart && cachedRows !== null) {
      draw(cachedRows);
      return;
    }
  });
})();
</script>

<?php

// use PDO;
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
