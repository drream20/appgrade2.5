<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

$academicYears = [];
$activeAyId = null;
$activeAyLabel = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester, is_active FROM academic_years ORDER BY school_year DESC, semester ASC");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($academicYears as $ay) {
        if (!empty($ay['is_active'])) {
            $activeAyId = (int)$ay['id'];
            $activeAyLabel = trim(($ay['school_year'] ?? '') . ' ' . ($ay['semester'] ?? ''));
        }
    }
} catch (Throwable $e) {}

$chartSelectedAyId = isset($_GET['chart_academic_year_id']) && $_GET['chart_academic_year_id'] !== ''
    ? (int)$_GET['chart_academic_year_id']
    : null;
$chartAyId = $chartSelectedAyId ?: $activeAyId;
$chartAyLabel = 'Active Academic Year';
if ($chartAyId) {
    foreach ($academicYears as $ay) {
        if ((int)$ay['id'] === $chartAyId) {
            $chartAyLabel = trim(($ay['school_year'] ?? '') . ' ' . ($ay['semester'] ?? ''));
            break;
        }
    }
}

$totalStudents = 0;
try {
    if ($chartAyId) {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT e.student_id) AS n
            FROM class_sections cs
            JOIN enrollments e ON e.class_section_id = cs.id
            WHERE cs.academic_year_id = ? AND e.status = 'enrolled'
        ");
        $stmt->execute([$chartAyId]);
        $totalStudents = (int)($stmt->fetch()['n'] ?? 0);
    }
} catch (Throwable $e) {}

$perYear = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
if ($chartAyId) {
    try {
        $stmt = $db->prepare("
            SELECT cs.year_level, COUNT(DISTINCT e.student_id) AS n
            FROM class_sections cs
            JOIN enrollments e ON e.class_section_id = cs.id
            WHERE cs.academic_year_id = ? AND e.status = 'enrolled'
            GROUP BY cs.year_level
        ");
        $stmt->execute([$chartAyId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $yl = (int)($row['year_level'] ?? 0);
            if ($yl >= 1 && $yl <= 4) {
                $perYear[$yl] = (int)$row['n'];
            }
        }
    } catch (Throwable $e) {}
}

$activeInstructors = 0;
if ($activeAyId) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT isub.instructor_id) AS n
            FROM instructor_subjects isub
            WHERE isub.academic_year_id = ?
        ");
        $stmt->execute([$activeAyId]);
        $activeInstructors = (int)($stmt->fetch()['n'] ?? 0);
    } catch (Throwable $e) {}
}
if (!$activeInstructors) {
    try {
        $stmt = $db->query("SELECT COUNT(*) AS n FROM instructors");
        $activeInstructors = (int)($stmt->fetch()['n'] ?? 0);
    } catch (Throwable $e) {}
}

$studentsWithAttendanceIssues = 0;
try {
    $sql = "
        SELECT COUNT(*) AS n FROM (
            SELECT e.student_id
            FROM attendance_records ar
            JOIN enrollments e ON e.id = ar.enrollment_id
            " . ($activeAyId ? "LEFT JOIN class_sections cs ON cs.id = e.class_section_id" : "") . "
            WHERE ar.status = 'absent'
            " . ($activeAyId ? "AND cs.academic_year_id = :ayId" : "") . "
            GROUP BY e.student_id
            HAVING COUNT(*) >= 3
        ) t
    ";
    $stmt = $db->prepare($sql);
    if ($activeAyId) {
        $stmt->bindValue(':ayId', $activeAyId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $studentsWithAttendanceIssues = (int)($stmt->fetch()['n'] ?? 0);
} catch (Throwable $e) {}

$departments = [
    'BSCS' => 'School of Computer Studies',
    'BSHM' => 'School of Hospitality Management',
    'BEED' => 'School of Teacher Education',
    'BSA'  => 'School of Business Administration & Accountancy',
    'BSCRIM' => 'School of Criminal Justice Education',
    'BSN'  => 'School of Nursing',
];
$courseToDept = [
    'BSCS' => 'School of Computer Studies',
    'BSHM' => 'School of Hospitality Management',
    'BEED' => 'School of Teacher Education',
    'BSEDFIL' => 'School of Teacher Education',
    'BSEDMAT' => 'School of Teacher Education',
    'BSEDENG' => 'School of Teacher Education',
    'BSEDSS' => 'School of Teacher Education',
    'BSA' => 'School of Business Administration & Accountancy',
    'BSBAFM' => 'School of Business Administration & Accountancy',
    'BSCRIM' => 'School of Criminal Justice Education',
    'BSISM' => 'School of Criminal Justice Education',
    'BSN' => 'School of Nursing',
];

$absencesByDept = [];
try {
    $sql = "
        SELECT st.course, COUNT(*) AS absence_count
        FROM attendance_records ar
        JOIN enrollments e ON e.id = ar.enrollment_id
        JOIN students st ON st.id = e.student_id
        " . ($activeAyId ? "LEFT JOIN class_sections cs ON cs.id = e.class_section_id" : "") . "
        WHERE ar.status = 'absent'
        " . ($activeAyId ? "AND cs.academic_year_id = :ayId" : "") . "
        GROUP BY st.course
        ORDER BY absence_count DESC
    ";
    $stmt = $db->prepare($sql);
    if ($activeAyId) {
        $stmt->bindValue(':ayId', $activeAyId, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rawAbsences = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawAbsences as $row) {
        $course = trim($row['course'] ?? '');
        $count = (int)$row['absence_count'];
        $deptName = $courseToDept[$course] ?? $course;
        if (!isset($absencesByDept[$deptName])) {
            $absencesByDept[$deptName] = 0;
        }
        $absencesByDept[$deptName] += $count;
    }
    arsort($absencesByDept);
} catch (Throwable $e) {}

$studentsByDept = [];
if ($chartAyId) {
    try {
        $stmt = $db->prepare("
            SELECT st.course, COUNT(DISTINCT e.student_id) AS n
            FROM class_sections cs
            JOIN enrollments e ON e.class_section_id = cs.id
            JOIN students st ON st.id = e.student_id
            WHERE cs.academic_year_id = ? AND e.status = 'enrolled'
            GROUP BY st.course
        ");
        $stmt->execute([$chartAyId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $course = trim($row['course'] ?? '');
            $count = (int)$row['n'];
            $deptName = $courseToDept[$course] ?? $course;
            if (!isset($studentsByDept[$deptName])) {
                $studentsByDept[$deptName] = 0;
            }
            $studentsByDept[$deptName] += $count;
        }
        arsort($studentsByDept);
    } catch (Throwable $e) {}
}

$maleCount = 0;
$femaleCount = 0;
try {
    $stmt = $db->query("SELECT gender, COUNT(*) as count FROM students WHERE gender IN ('M', 'F') GROUP BY gender");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (strtolower($row['gender']) === 'm') $maleCount = (int)$row['count'];
        if (strtolower($row['gender']) === 'f') $femaleCount = (int)$row['count'];
    }
} catch (Throwable $e) {}

$pageTitle = 'Registrar Dashboard';
$currentPage = 'dashboard';
ob_start();
?>

<style>
  .dashboard-icon-circle {
    width: 52px; height: 52px; min-width: 52px; min-height: 52px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50% !important;
  }
  @media (max-width: 575.98px) {
    .dashboard-icon-circle { width: 40px; height: 40px; min-width: 40px; min-height: 40px; }
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Registrar Dashboard</h4>
  <?php if ($activeAyLabel): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body d-flex align-items-center">
        <div class="dashboard-icon-circle bg-primary bg-opacity-10 me-3">
          <i class="bi bi-people text-primary fs-4"></i>
        </div>
        <div>
          <div class="text-muted small">Total Students</div>
          <div class="fs-4 fw-bold"><?= $totalStudents ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body">
        <div class="text-muted small mb-1">Students by Year Level</div>
        <div class="d-flex flex-wrap small">
          <div class="me-3"><span class="text-muted">1st:</span> <strong><?= $perYear[1] ?></strong></div>
          <div class="me-3"><span class="text-muted">2nd:</span> <strong><?= $perYear[2] ?></strong></div>
          <div class="me-3"><span class="text-muted">3rd:</span> <strong><?= $perYear[3] ?></strong></div>
          <div><span class="text-muted">4th:</span> <strong><?= $perYear[4] ?></strong></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body d-flex align-items-center">
        <div class="dashboard-icon-circle bg-success bg-opacity-10 me-3">
          <i class="bi bi-person-badge text-success fs-4"></i>
        </div>
        <div>
          <div class="text-muted small">Active Instructors</div>
          <div class="fs-4 fw-bold"><?= $activeInstructors ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-body d-flex align-items-center">
        <div class="dashboard-icon-circle bg-danger bg-opacity-10 me-3">
          <i class="bi bi-exclamation-triangle text-danger fs-4"></i>
        </div>
        <div>
          <div class="text-muted small">Students w/ 3+ Absences</div>
          <div class="fs-4 fw-bold"><?= $studentsWithAttendanceIssues ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-graph-up me-2"></i>Students by Year Level</strong>
        <form method="get" class="d-flex align-items-center">
          <label for="chartAcademicYear" class="me-2 text-muted small mb-0">AY</label>
          <select id="chartAcademicYear" name="chart_academic_year_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($academicYears as $ay): ?>
              <?php $ayValue = (int)($ay['id'] ?? 0); ?>
              <?php $ayText = trim(($ay['school_year'] ?? '') . ' ' . ($ay['semester'] ?? '')); ?>
              <option value="<?= $ayValue ?>" <?= $chartAyId === $ayValue ? 'selected' : '' ?>>
                <?= htmlspecialchars($ayText) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <div class="card-body">
        <div class="small text-muted mb-2">Showing: <?= htmlspecialchars($chartAyLabel) ?></div>
        <div style="position: relative; width: 100%; min-height: 350px;">
          <canvas id="studentsYearLineChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-bar-chart-line me-2"></i>Total Absences by Department</strong>
      </div>
      <div class="card-body" style="min-height: 350px; max-height: 450px;">
        <div class="small text-muted mb-2">
          Total absence records per department<?= $activeAyLabel ? ' (' . htmlspecialchars($activeAyLabel) . ')' : '' ?>.
        </div>
        <?php if (empty($absencesByDept)): ?>
          <p class="text-muted mb-0">No absence data found.</p>
        <?php else: ?>
          <div style="overflow-y: auto; max-height: 320px;">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light" style="position: sticky; top: 0; z-index: 2;">
                <tr>
                  <th style="background: #f8f9fa;">Department</th>
                  <th style="background: #f8f9fa;" class="text-end">Total Absences</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($absencesByDept as $deptName => $count): ?>
                  <tr>
                    <td><?= htmlspecialchars($deptName) ?></td>
                    <td class="text-end"><span class="badge bg-danger"><?= $count ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-building me-2"></i>Students by Department</strong>
      </div>
      <div class="card-body" style="min-height: 300px; max-height: 400px;">
        <div class="small text-muted mb-2">Enrolled students per department (<?= htmlspecialchars($chartAyLabel) ?>).</div>
        <?php if (empty($studentsByDept)): ?>
          <p class="text-muted mb-0">No enrollment data found.</p>
        <?php else: ?>
          <div style="overflow-y: auto; max-height: 280px;">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light" style="position: sticky; top: 0; z-index: 2;">
                <tr>
                  <th style="background: #f8f9fa;">Department</th>
                  <th style="background: #f8f9fa;" class="text-end">Students</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($studentsByDept as $deptName => $count): ?>
                  <tr>
                    <td><?= htmlspecialchars($deptName) ?></td>
                    <td class="text-end"><span class="badge bg-primary"><?= $count ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-pie-chart me-2"></i>Students by Gender</strong>
      </div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <div style="position: relative; width: 100%; max-width: 320px;">
          <canvas id="genderPieChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-12">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-header bg-white">
        <strong><i class="bi bi-clipboard-check me-2"></i>Quick Access</strong>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/class_list.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-people me-1"></i> Class List
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/student_management.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-table me-1"></i> Student Management
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/students.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-person-badge me-1"></i> Students
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
  var ctxLine = document.getElementById('studentsYearLineChart');
  if (ctxLine) {
    new Chart(ctxLine, {
      type: 'line',
      data: {
        labels: ['1st Year', '2nd Year', '3rd Year', '4th Year'],
        datasets: [{
          label: 'Total Students',
          data: [<?= (int)$perYear[1] ?>, <?= (int)$perYear[2] ?>, <?= (int)$perYear[3] ?>, <?= (int)$perYear[4] ?>],
          borderColor: '#0d6efd',
          backgroundColor: 'rgba(13, 110, 253, 0.15)',
          fill: true,
          tension: 0.35,
          pointRadius: 4,
          pointHoverRadius: 5
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        aspectRatio: 2,
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        plugins: { legend: { display: true } }
      }
    });
  }

  var ctxPie = document.getElementById('genderPieChart');
  if (ctxPie) {
    new Chart(ctxPie, {
      type: 'doughnut',
      data: {
        labels: ['Male', 'Female'],
        datasets: [{
          data: [<?= $maleCount ?>, <?= $femaleCount ?>],
          backgroundColor: ['#1976d2', '#e91e63'],
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { position: 'bottom' }
        }
      }
    });
  }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
