<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();
// Program context: regular dean is limited to one program; Executive dean can view all or a selected program.
$programDepartment = deanSelectedProgramDepartment();
$courses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];

// Academic year options + chart-only selected filter
$academicYears = [];
$chartSelectedAyId = isset($_GET['chart_academic_year_id']) && $_GET['chart_academic_year_id'] !== '' ? (int)$_GET['chart_academic_year_id'] : null;

// Load academic years and active academic year
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
} catch (Throwable $e) {
    $activeAyId = null;
}
$chartAyId = $chartSelectedAyId ?: $activeAyId;
$chartAyLabel = 'Active Academic Year';
if ($chartAyId) {
    foreach ($academicYears as $ay) {
        if ((int)$ay['id'] === (int)$chartAyId) {
            $chartAyLabel = trim(($ay['school_year'] ?? '') . ' ' . ($ay['semester'] ?? ''));
            break;
        }
    }
}

// Total students (must match Students by Year Level card)
// We count distinct enrolled students from class_sections -> enrollments for the selected chart AY.
$totalStudents = 0;
try {
    if ($chartAyId) {
        $sqlTotal = "
            SELECT COUNT(DISTINCT e.student_id) AS n
            FROM class_sections cs
            JOIN enrollments e ON e.class_section_id = cs.id
            JOIN students st ON st.id = e.student_id
            WHERE cs.academic_year_id = :chartAyId
              AND e.status = 'enrolled'
        ";
        $paramsTotal = [':chartAyId' => $chartAyId];

        if ($programDepartment && !empty($courses)) {
            $coursePlaceholders = [];
            foreach ($courses as $idx => $courseCode) {
                $key = ':totalCourse' . $idx;
                $coursePlaceholders[] = $key;
                $paramsTotal[$key] = $courseCode;
            }
            $sqlTotal .= " AND st.course IN (" . implode(',', $coursePlaceholders) . ")";
        } elseif ($programDepartment) {
            // Regular dean has a department but no mapped courses => show zero.
            $sqlTotal .= " AND 0 = 1";
        }

        $stmtTotal = $db->prepare($sqlTotal);
        $stmtTotal->execute($paramsTotal);
        $totalStudents = (int)($stmtTotal->fetch()['n'] ?? 0);
    }
} catch (Throwable $e) {
    $totalStudents = 0;
}

// Students per year level (1–4) for chart-selected AY only
$perYear = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
if ($chartAyId) {
    $sql = "
        SELECT cs.year_level, COUNT(DISTINCT e.student_id) AS n
        FROM class_sections cs
        JOIN enrollments e ON e.class_section_id = cs.id
        JOIN students st ON st.id = e.student_id
        WHERE cs.academic_year_id = :chartAyId
          AND e.status = 'enrolled'
    ";
    $params = [':chartAyId' => $chartAyId];
    if ($programDepartment && !empty($courses)) {
        $coursePlaceholders = [];
        foreach ($courses as $idx => $courseCode) {
            $key = ':course' . $idx;
            $coursePlaceholders[] = $key;
            $params[$key] = $courseCode;
        }
        $sql .= " AND st.course IN (" . implode(',', $coursePlaceholders) . ")";
    } elseif ($programDepartment) {
        // No mapped course for this department => no rows.
        $sql .= " AND 0 = 1";
    }
    $sql .= " GROUP BY cs.year_level";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $yl = (int) ($row['year_level'] ?? 0);
        if ($yl >= 1 && $yl <= 4) {
            $perYear[$yl] = (int) $row['n'];
        }
    }
}

// Total active instructors (with at least one class in active AY, scoped by program when applicable)
$activeInstructors = 0;
if ($activeAyId) {
    $sql = "
        SELECT COUNT(DISTINCT isub.instructor_id) AS n
        FROM instructor_subjects isub
        LEFT JOIN class_sections cs ON cs.instructor_subject_id = isub.id
        WHERE isub.academic_year_id = :ayId
    ";
    $params = [':ayId' => $activeAyId];
    if ($programDepartment && !empty($courses)) {
        $coursePlaceholders = [];
        foreach ($courses as $idx => $courseCode) {
            $key = ':insCourse' . $idx;
            $coursePlaceholders[] = $key;
            $params[$key] = $courseCode;
        }
        $sql .= " AND EXISTS (
            SELECT 1
            FROM enrollments e2
            JOIN students st2 ON st2.id = e2.student_id
            WHERE (e2.instructor_subject_id = isub.id OR e2.class_section_id = cs.id)
              AND st2.course IN (" . implode(',', $coursePlaceholders) . ")
        )";
    } elseif ($programDepartment) {
        $sql .= " AND 0 = 1";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $activeInstructors = (int) ($stmt->fetch()['n'] ?? 0);
}
// Only fall back to global count when no program filter is applied.
if (!$activeInstructors && !$programDepartment) {
    try {
        $stmt = $db->query("SELECT COUNT(*) AS n FROM instructors");
        $activeInstructors = (int) ($stmt->fetch()['n'] ?? 0);
    } catch (Throwable $e) {
        $activeInstructors = 0;
    }
}

// Students with attendance issues (3+ absences in any term, per program when applicable)
$studentsWithAttendanceIssues = 0;
try {
    $sql = "
        SELECT COUNT(*) AS n FROM (
            SELECT e.student_id
            FROM attendance_records ar
            JOIN enrollments e ON e.id = ar.enrollment_id
            " . (($activeAyId || $programDepartment) ? "LEFT JOIN class_sections cs ON cs.id = e.class_section_id" : "") . "
            LEFT JOIN students st ON st.id = e.student_id
            WHERE ar.status = 'absent'
            " . ($activeAyId ? "AND cs.academic_year_id = :ayId" : "") . "
            " . ($programDepartment && !empty($courses) ? "AND st.course IN (" . implode(',', array_map(static fn($i) => ':attCourse' . $i, array_keys($courses))) . ")" : "") . "
            " . ($programDepartment && empty($courses) ? "AND 0 = 1" : "") . "
            GROUP BY e.student_id
            HAVING COUNT(*) >= 3
        ) t
    ";
    $stmt = $db->prepare($sql);
    if ($activeAyId) {
        $stmt->bindValue(':ayId', $activeAyId, PDO::PARAM_INT);
    }
    if ($programDepartment && !empty($courses)) {
        foreach (array_values($courses) as $idx => $courseCode) {
            $stmt->bindValue(':attCourse' . $idx, $courseCode, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $studentsWithAttendanceIssues = (int) ($stmt->fetch()['n'] ?? 0);
} catch (Throwable $e) {
    $studentsWithAttendanceIssues = 0;
}

// Other analytics: students with 3+ absences in a specific subject (scoped by dean program)
$analyticsYearLevel = isset($_GET['analytics_year_level']) && $_GET['analytics_year_level'] !== '' ? (int)$_GET['analytics_year_level'] : null;
$subjectAbsenceRows = [];
try {
    $sql = "
        SELECT
            st.student_id,
            st.full_name,
            st.course,
            cs.year_level,
            cs.section,
            s.code AS subject_code,
            s.title AS subject_title,
            COUNT(*) AS absence_count
        FROM attendance_records ar
        JOIN enrollments e ON e.id = ar.enrollment_id
        LEFT JOIN class_sections cs ON cs.id = e.class_section_id
        JOIN students st ON st.id = e.student_id
        LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
        LEFT JOIN subjects s ON s.id = COALESCE(cs.subject_id, isub.subject_id)
        WHERE ar.status = 'absent'
          " . ($activeAyId ? "AND cs.academic_year_id = :ayId" : "") . "
          " . ($analyticsYearLevel ? "AND cs.year_level = :yearLevel" : "") . "
    ";
    $params = [];
    if ($activeAyId) {
        $params[':ayId'] = $activeAyId;
    }
    if ($analyticsYearLevel) {
        $params[':yearLevel'] = $analyticsYearLevel;
    }
    if ($programDepartment && !empty($courses)) {
        $coursePlaceholders = [];
        foreach ($courses as $idx => $courseCode) {
            $key = ':anaCourse' . $idx;
            $coursePlaceholders[] = $key;
            $params[$key] = $courseCode;
        }
        $sql .= " AND st.course IN (" . implode(',', $coursePlaceholders) . ")";
    } elseif ($programDepartment) {
        $sql .= " AND 0 = 1";
    }
    $sql .= "
        GROUP BY
            st.student_id, st.full_name, st.course,
            cs.year_level, cs.section,
            s.code, s.title
        HAVING COUNT(*) >= 3
        ORDER BY absence_count DESC, st.full_name ASC, s.code ASC
        LIMIT 100
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $subjectAbsenceRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $subjectAbsenceRows = [];
}

$pageTitle = 'Dean Dashboard';
$currentPage = 'dashboard';
ob_start();
?>

<style>
  .dashboard-icon-circle {
    width: 52px;
    height: 52px;
    min-width: 52px;
    min-height: 52px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50% !important;
  }
  @media (max-width: 575.98px) {
    .dashboard-icon-circle {
      width: 40px;
      height: 40px;
      min-width: 40px;
      min-height: 40px;
    }
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Welcome, Dean!</h4>
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
          <div class="text-muted small">Absent Student</div>
          <div class="fs-4 fw-bold"><?= $studentsWithAttendanceIssues ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ANALYTICS ROW: left half for chart, right half for analytics container -->
<div class="row g-3 mb-4">
  <!-- Left: Charttt -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-graph-up me-2"></i>Student Analytics (1st to 4th Year)</strong>
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
  <!-- Right: Absenteeism Medium Height, Scrollable Students Only, Fixed Header -->
  <div class="col-12 col-lg-6">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-bar-chart-line me-2"></i>Absenteeism</strong>
        <form method="get" class="d-flex align-items-center">
          <?php if ($chartAyId): ?>
            <input type="hidden" name="chart_academic_year_id" value="<?= (int)$chartAyId ?>">
          <?php endif; ?>
          <label for="analyticsYearLevel" class="me-2 text-muted small mb-0">Year</label>
          <select id="analyticsYearLevel" name="analytics_year_level" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All</option>
            <?php for ($yl = 1; $yl <= 4; $yl++): ?>
              <option value="<?= $yl ?>" <?= $analyticsYearLevel === $yl ? 'selected' : '' ?>>
                <?= $yl ?><?= $yl === 1 ? 'st' : ($yl === 2 ? 'nd' : ($yl === 3 ? 'rd' : 'th')) ?>
              </option>
            <?php endfor; ?>
          </select>
        </form>
      </div>
      <div class="card-body" style="min-height: 350px; max-height: 450px;">
        <div class="small text-muted mb-2">
          Students with 3+ absences in the same subject<?= $analyticsYearLevel ? ' (Year ' . (int)$analyticsYearLevel . ')' : '' ?>.
        </div>
        <?php if (empty($subjectAbsenceRows)): ?>
          <p class="text-muted mb-0">No students found for this filter.</p>
        <?php else: ?>
          <div style="overflow-y: auto; max-height:320px;">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead class="table-light" style="position: sticky; top: 0; z-index: 2;">
                <tr>
                  <th style="background: #f8f9fa;">Student</th>
                  <th style="background: #f8f9fa;">Course</th>
                  <th style="background: #f8f9fa;">Yr &amp; Sec</th>
                  <th style="background: #f8f9fa;">Subject</th>
                  <th style="background: #f8f9fa;">Absences</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($subjectAbsenceRows as $row): ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($row['full_name'] ?? '') ?></strong><br>
                      <span class="small text-muted"><?= htmlspecialchars($row['student_id'] ?? '') ?></span>
                    </td>
                    <td><?= htmlspecialchars($row['course'] ?? '-') ?></td>
                    <td style="font-size: smaller;" >
                      <?= !empty($row['year_level']) ? (int)$row['year_level'] . ' Yr' : 'N/A' ?>
                      <?= !empty($row['section']) ? ' - ' . htmlspecialchars($row['section']) : '' ?>
                    </td>
                    <td style="font-size: small;">
                      <strong><?= htmlspecialchars($row['subject_code'] ?? '-') ?></strong><br>
                      <span class="small text-muted"><?= htmlspecialchars($row['subject_title'] ?? '') ?></span>
                    </td>
                    <td class="text-center"><span class="badge bg-danger"><?= (int)($row['absence_count'] ?? 0) ?></span></td>
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

<div class="row g-3">
  <div class="col-md-12">
    <div class="card h-100 shadow-sm border-0">
      <div class="card-header bg-white">
        <strong><i class="bi bi-clipboard-check me-2"></i>Quick Access</strong>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/students.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-people me-1"></i> Student Management
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/instructors.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-person-badge me-1"></i> Instructor Management
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/attendance.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-clipboard-data me-1"></i> Attendance Monitoring
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/sections.php" class="btn btn-outline-primary w-100 mb-2 shadow-sm">
              <i class="bi bi-journal-text me-1"></i> Sections &amp; Subjects
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/reports.php" class="btn btn-outline-success w-100 mb-2 shadow-sm">
              <i class="bi bi-file-earmark-bar-graph me-1"></i> Downloadable Reports
            </a>
          </div>
          <div class="col-sm-4">
            <a href="<?= $baseUrl ?>/profile.php" class="btn btn-outline-secondary w-100 mb-2 shadow-sm">
              <i class="bi bi-person-gear me-1"></i> Dean Profile
            </a>
          </div>
        </div>
        <p class="text-muted small mt-3 mb-0">
          All Dean tools are view-only for academic records; no editing of grades or instructor records.
        </p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  (function () {
    const ctx = document.getElementById('studentsYearLineChart');
    if (!ctx) return;
    new Chart(ctx, {
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
        aspectRatio: 2, // Makes chart wider; tweak as needed (width : height)
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              precision: 0
            }
          }
        },
        plugins: {
          legend: {
            display: true
          }
        }
      }
    });
  })();
</script>
<?php

use PhpOffice\PhpSpreadsheet\Chart\Chart;
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';

