<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

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

$pageTitle = 'Reports';
$currentPage = 'reports';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Downloadable Reports</h4>
  <?php if ($activeAyLabel): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-award me-2"></i>Overall Final Grades per Subject</strong>
      </div>
      <div class="card-body">
        <p class="text-muted small">
          Per subject and student: Prelim, Midterm, Final term grades and computed overall grade (30% / 30% / 40%).
        </p>
        <a href="<?= $baseUrl ?>/report_final_grades.php?format=csv" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download (Excel/CSV)
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-people me-2"></i>Instructor Class Grade Report</strong>
      </div>
      <div class="card-body">
        <p class="text-muted small">
          Per instructor, per class: subject, section, enrolled count, and grade submission status.
        </p>
        <a href="<?= $baseUrl ?>/report_instructor_classes.php?format=csv" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download (Excel/CSV)
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-clipboard-x me-2"></i>Students with 3+ Absences</strong>
      </div>
      <div class="card-body">
        <p class="text-muted small">
          List of students who have accumulated three or more absences per subject and term, for early intervention.
        </p>
        <a href="<?= $baseUrl ?>/report_absences.php?format=csv" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download (Excel/CSV)
        </a>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header bg-white">
        <strong><i class="bi bi-clipboard-check me-2"></i>Instructor Submission Status</strong>
      </div>
      <div class="card-body">
        <p class="text-muted small">
          Overview of which instructors have submitted class records for their assigned subjects and which remain pending.
        </p>
        <a href="<?= $baseUrl ?>/report_submission_status.php?format=csv" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i>Download (Excel/CSV)
        </a>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';

