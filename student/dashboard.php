<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
require __DIR__ . '/../includes/active_academic_year.php';
studentRequireLogin();
studentRequireProfileComplete();
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

$db = getDb();
$studentId = currentStudentId();

$activeAY = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester FROM academic_years WHERE is_active = 1 LIMIT 1");
    $activeAY = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

/**
 * Project course final (30/30/40) from partial term grades. Returns [value|null, confidence: high|medium|low|none].
 */
function student_dashboard_project_final(?float $p, ?float $m, ?float $f): array {
    if ($p !== null && $m !== null && $f !== null) {
        return [computeFinalGrade($p, $m, $f), 'high'];
    }
    if ($p !== null && $m !== null && $f === null) {
        return [computeFinalGrade($p, $m, $m), 'medium'];
    }
    if ($p !== null && $m === null && $f !== null) {
        $midEst = ($p + $f) / 2.0;
        return [computeFinalGrade($p, $midEst, $f), 'medium'];
    }
    if ($p === null && $m !== null && $f !== null) {
        return [computeFinalGrade($m, $m, $f), 'medium'];
    }
    if ($p !== null && $m === null && $f === null) {
        return [$p, 'low'];
    }
    if ($p === null && $m !== null && $f === null) {
        return [$m, 'low'];
    }
    if ($p === null && $m === null && $f !== null) {
        return [$f, 'low'];
    }
    return [null, 'none'];
}

$analyticsCourses = [];
$termAvg = [1 => null, 2 => null, 3 => null];
$termSum = [1 => 0.0, 2 => 0.0, 3 => 0.0];
$termCnt = [1 => 0, 2 => 0, 3 => 0];
$atRisk = [];
$watchList = [];
$avgPredicted = null;

/**
 * Helper to remove trailing "L" from course codes like CSC106L if CSC106 also exists in enrollments.
 */
function filter_lab_courses($enrollments) {
    $seen = [];
    foreach ($enrollments as $row) {
        if (!empty($row['code'])) {
            $coreCode = (preg_match('/^(.+?)L$/', $row['code'], $m) ? $m[1] : $row['code']);
            $seen[$coreCode][] = $row['code'];
        }
    }
    $filtered = [];
    foreach ($enrollments as $row) {
        $code = $row['code'] ?? '';
        // If ends with L and base code exists, skip lab variant
        if (preg_match('/^(.+?)L$/', $code, $m)) {
            $base = $m[1];
            if (isset($seen[$base])) {
                // Base course present, skip this 'L' variant
                continue;
            }
        }
        $filtered[] = $row;
    }
    return $filtered;
}

if ($activeAY) {
    [$ayWhere, $ayParams] = activeAcademicYearMatch(
        'isub.academic_year_id',
        'isub.school_year',
        'isub.semester',
        $activeAY
    );
    $stmt = $db->prepare("
      SELECT e.id AS enrollment_id, s.code, s.title
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

    // Filter out lab subjects like CSC106L if CSC106 is also in enrollments
    $enrollments = filter_lab_courses($enrollments);

    $stmtTg = $db->prepare('SELECT term_id, term_grade_computed FROM term_grades WHERE enrollment_id = ?');
    $stmtAbs = $db->prepare("
        SELECT COUNT(*) FROM attendance_records ar
        WHERE ar.enrollment_id = ? AND ar.status = 'absent'
    ");

    $predictSum = 0.0;
    $predictN = 0;

    foreach ($enrollments as $row) {
        $eid = (int) $row['enrollment_id'];
        $stmtTg->execute([$eid]);
        $tg = [];
        while ($g = $stmtTg->fetch(PDO::FETCH_ASSOC)) {
            $tid = (int) $g['term_id'];
            $tg[$tid] = is_numeric($g['term_grade_computed'] ?? null) ? (float) $g['term_grade_computed'] : null;
        }
        $p = $tg[1] ?? null;
        $m = $tg[2] ?? null;
        $f = $tg[3] ?? null;

        foreach ([1 => $p, 2 => $m, 3 => $f] as $tid => $val) {
            if ($val !== null) {
                $termSum[$tid] += $val;
                $termCnt[$tid]++;
            }
        }

        $stmtAbs->execute([$eid]);
        $absences = (int) $stmtAbs->fetchColumn();

        [$proj, $conf] = student_dashboard_project_final($p, $m, $f);
        if ($proj !== null && $conf !== 'none') {
            $predictSum += $proj;
            $predictN++;
        }

        $code = $row['code'] ?? '';
        $title = $row['title'] ?? '';
        $analyticsCourses[] = [
            'code' => $code,
            'title' => $title,
            'prelim' => $p,
            'midterm' => $m,
            'final' => $f,
            'projected' => $proj,
            'confidence' => $conf,
            'absences' => $absences,
        ];

        if ($proj !== null) {
            if ($proj < 75.0 || $absences >= 5) {
                $atRisk[] = ['code' => $code, 'projected' => $proj, 'absences' => $absences];
            } elseif (($proj < 80.0 && $proj >= 75.0) || ($absences >= 3 && $absences <= 4)) {
                $watchList[] = ['code' => $code, 'projected' => $proj, 'absences' => $absences];
            }
        }
    }

    foreach ([1, 2, 3] as $tid) {
        if ($termCnt[$tid] > 0) {
            $termAvg[$tid] = round($termSum[$tid] / $termCnt[$tid], 2);
        }
    }
    if ($predictN > 0) {
        $avgPredicted = round($predictSum / $predictN, 2);
    }
}

$student = currentStudent();

$chartLabels = json_encode(['Prelim', 'Midterm', 'Final']);
$chartData = json_encode([
    $termAvg[1] !== null ? (float) $termAvg[1] : null,
    $termAvg[2] !== null ? (float) $termAvg[2] : null,
    $termAvg[3] !== null ? (float) $termAvg[3] : null,
]);

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Dashboard</h4>
</div>

<?php if (isset($_GET['profile_complete'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-1"></i> Your profile is complete and your password is set. You can use the student portal as usual.
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <h5 class="card-title">Welcome, <?= htmlspecialchars($student['full_name'] ?? '') ?></h5>
    <p class="text-muted mb-0">Student ID: <?= htmlspecialchars($student['student_id'] ?? '') ?></p>
    <?php if ($activeAY): ?>
      <p class="text-muted small mt-2 mb-0">Active Academic Year: <strong><?= htmlspecialchars($activeAY['school_year']) ?> — <?= htmlspecialchars($activeAY['semester']) ?></strong></p>
    <?php endif; ?>
  </div>
</div>

<!-- Small quick-links row, after the welcome card, full width, 4 columns, smaller style -->
<div class="row g-2 mb-4">
  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>/student/grades.php" class="text-decoration-none">
      <div class="card h-100 border-primary shadow-sm">
        <div class="card-body py-3 px-2 d-flex align-items-center">
          <div class="me-2 fs-3 text-primary"><i class="bi bi-award"></i></div>
          <div>
            <div class="fw-semibold small mb-0">Grades</div>
            <div class="text-muted small">View by subject & year</div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>/student/records.php" class="text-decoration-none">
      <div class="card h-100 border-primary shadow-sm">
        <div class="card-body py-3 px-2 d-flex align-items-center">
          <div class="me-2 fs-3 text-primary"><i class="bi bi-journal-text"></i></div>
          <div>
            <div class="fw-semibold small mb-0">Records</div>
            <div class="text-muted small">Assessment & attendance</div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>/student/schedule.php" class="text-decoration-none">
      <div class="card h-100 border-primary shadow-sm">
        <div class="card-body py-3 px-2 d-flex align-items-center">
          <div class="me-2 fs-3 text-primary"><i class="bi bi-calendar3"></i></div>
          <div>
            <div class="fw-semibold small mb-0">Schedule</div>
            <div class="text-muted small">Your class schedule</div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= $baseUrl ?>/student/ranking.php" class="text-decoration-none">
      <div class="card h-100 border-primary shadow-sm">
        <div class="card-body py-3 px-2 d-flex align-items-center">
          <div class="me-2 fs-3 text-primary"><i class="bi bi-trophy"></i></div>
          <div>
            <div class="fw-semibold small mb-0">Ranking</div>
            <div class="text-muted small">Class ranking by term</div>
          </div>
        </div>
      </div>
    </a>
  </div>
</div>

<?php if ($activeAY && !empty($analyticsCourses)): ?>
<div class="card mb-4 border-0 shadow-sm">
  <div class="card-header bg-white border-bottom">
    <strong><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Predictive analytics</strong>
    <span class="text-muted small fw-normal">— active semester, estimates from your term grades (30% / 30% / 40%)</span>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="p-3 rounded border bg-light h-100">
          <div class="small text-muted text-uppercase">Avg. term grades (your subjects)</div>
          <div class="fs-4 fw-semibold">
            <?php if ($termAvg[1] !== null || $termAvg[2] !== null || $termAvg[3] !== null): ?>
              P <?= $termAvg[1] !== null ? number_format($termAvg[1], 1) : '—' ?>
              · M <?= $termAvg[2] !== null ? number_format($termAvg[2], 1) : '—' ?>
              · F <?= $termAvg[3] !== null ? number_format($termAvg[3], 1) : '—' ?>
            <?php else: ?>
              <span class="text-muted fs-6">No term grades yet</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-3 rounded border bg-light h-100">
          <div class="small text-muted text-uppercase">Avg. projected course grade</div>
          <div class="fs-4 fw-semibold text-primary">
            <?= $avgPredicted !== null ? number_format($avgPredicted, 2) : '—' ?>
          </div>
          <div class="small text-muted">Uses partial terms when needed (see table).</div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="p-3 rounded border h-100 <?= count($atRisk) > 0 ? 'border-danger bg-danger-subtle' : (count($watchList) > 0 ? 'border-warning bg-warning-subtle' : 'border-success bg-success-subtle') ?>">
          <div class="small text-muted text-uppercase">Standing</div>
          <?php if (count($atRisk) > 0): ?>
            <div class="fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= count($atRisk) ?> course(s) need attention</div>
            <div class="small">Projected below 75 or high absences (5+).</div>
          <?php elseif (count($watchList) > 0): ?>
            <div class="fw-semibold text-warning"><i class="bi bi-eye-fill me-1"></i><?= count($watchList) ?> on watch list</div>
            <div class="small">Projected 75–79 or absences 3–4.</div>
          <?php else: ?>
            <div class="fw-semibold text-success"><i class="bi bi-check-circle-fill me-1"></i>On track</div>
            <div class="small">No flags from current projections.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="mb-2 small text-muted">Trend: average of your recorded term grades per period</div>
        <div style="position: relative; height: 220px; max-width: 100%;">
          <canvas id="studentAnalyticsChart"></canvas>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="small text-muted mb-2">How projections work</div>
        <ul class="small ps-3 mb-0">
          <li><strong>High</strong> — all three term grades recorded; final is exact.</li>
          <li><strong>Medium</strong> — one term missing; missing piece is estimated from your other terms (same rule as your Grades page).</li>
          <li><strong>Low</strong> — only one term so far; number is a snapshot, not a full course grade.</li>
        </ul>
      </div>
    </div>

    <div class="table-responsive mt-4">
      <table class="table table-sm table-hover">
        <thead class="table-light">
          <tr>
            <th>Subject</th>
            <th class="text-end">Prelim</th>
            <th class="text-end">Mid</th>
            <th class="text-end">Final</th>
            <th class="text-end">Projected</th>
            <th class="text-center">Confidence</th>
            <th class="text-end">Absences</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($analyticsCourses as $c): ?>
            <tr>
              <td><strong><?= htmlspecialchars($c['code']) ?></strong></td>
              <td class="text-end"><?= $c['prelim'] !== null ? number_format($c['prelim'], 2) : '—' ?></td>
              <td class="text-end"><?= $c['midterm'] !== null ? number_format($c['midterm'], 2) : '—' ?></td>
              <td class="text-end"><?= $c['final'] !== null ? number_format($c['final'], 2) : '—' ?></td>
              <td class="text-end fw-semibold"><?= $c['projected'] !== null ? number_format($c['projected'], 2) : '—' ?></td>
              <td class="text-center"><span class="badge bg-<?= $c['confidence'] === 'high' ? 'success' : ($c['confidence'] === 'medium' ? 'info' : ($c['confidence'] === 'low' ? 'secondary' : 'light text-dark')) ?>"><?= htmlspecialchars($c['confidence']) ?></span></td>
              <td class="text-end <?= $c['absences'] >= 3 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $c['absences'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php elseif ($activeAY): ?>
<div class="alert alert-info mb-4">
  <i class="bi bi-info-circle me-1"></i> Predictive analytics will appear once you have enrollments in the active semester.
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();

$footerScripts = '';
if ($activeAY && !empty($analyticsCourses) && ($termAvg[1] !== null || $termAvg[2] !== null || $termAvg[3] !== null)) {
    $footerScripts = <<<HTML
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var ctx = document.getElementById('studentAnalyticsChart');
  if (!ctx || !window.Chart) return;
  var raw = {$chartData};
  var labels = {$chartLabels};
  var data = raw.map(function (v) { return v === null ? null : v; });
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Your avg. term grade',
        data: data,
        borderColor: 'rgb(13, 110, 253)',
        backgroundColor: 'rgba(13, 110, 253, 0.08)',
        tension: 0.25,
        fill: true,
        spanGaps: true
      }, {
        label: 'Passing reference (75)',
        data: [75, 75, 75],
        borderColor: 'rgba(220, 53, 69, 0.5)',
        borderDash: [6, 4],
        pointRadius: 0,
        fill: false
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: { min: 0, max: 100, ticks: { stepSize: 10 } }
      },
      plugins: { legend: { position: 'bottom' } }
    }
  });
})();
</script>
HTML;
}

require __DIR__ . '/includes/layout.php';
