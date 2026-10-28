<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

// Stats
$stmt = $db->query("SELECT COUNT(*) as n FROM students");
$totalStudents = (int) $stmt->fetch()['n'];

$stmt = $db->query("SELECT COUNT(*) as n FROM instructors");
$totalInstructors = (int) $stmt->fetch()['n'];

$stmt = $db->query("SELECT COUNT(*) as n FROM subjects");
$totalSubjects = (int) $stmt->fetch()['n'];

$stmt = $db->query("SELECT COUNT(*) as n FROM academic_years");
$totalAcademicYears = (int) $stmt->fetch()['n'];

// Active academic year
$activeAY = null;
$stmt = $db->query("SELECT id, school_year, semester, is_active, is_locked FROM academic_years WHERE is_active = 1 LIMIT 1");
if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $activeAY = $row;
}

$pageTitle = 'MIS Dashboard';
$currentPage = 'dashboard';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">MIS Dashboard</h4>
</div>

<?php if ($activeAY): ?>
<div class="alert alert-info d-flex align-items-center mb-4">
  <i class="bi bi-calendar-check me-2 fs-4"></i>
  <div>
    <strong>Active semester:</strong> <?= htmlspecialchars($activeAY['school_year']) ?> — <?= htmlspecialchars($activeAY['semester']) ?>
    <?php if ($activeAY['is_locked']): ?>
      <span class="badge bg-danger ms-2">Locked</span> <small class="text-muted">(Instructors cannot record grades/attendance)</small>
    <?php else: ?>
      <span class="badge bg-success ms-2">Unlocked</span> <small class="text-muted">(Recording enabled)</small>
    <?php endif; ?>
    <div class="mt-2 small">You can <a href="<?= $baseUrl ?>/upload_students.php" class="alert-link">upload enrollments</a> and <a href="<?= $baseUrl ?>/schedules.php" class="alert-link">upload schedules</a> for this semester. New subjects are created automatically when needed.</div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4">
  <i class="bi bi-exclamation-triangle me-2"></i>No active Academic Year. <a href="<?= $baseUrl ?>/academic_years.php">Create and activate one</a> before uploading data.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3"><i class="bi bi-people text-primary fs-4"></i></div>
        <div>
          <div class="text-muted small">Students</div>
          <div class="fs-4 fw-bold"><?= $totalStudents ?></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0"><a href="<?= $baseUrl ?>/students.php" class="small">Manage</a></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3"><i class="bi bi-person-badge text-success fs-4"></i></div>
        <div>
          <div class="text-muted small">Instructors</div>
          <div class="fs-4 fw-bold"><?= $totalInstructors ?></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0"><a href="<?= $baseUrl ?>/instructors.php" class="small">Manage</a></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3"><i class="bi bi-book text-info fs-4"></i></div>
        <div>
          <div class="text-muted small">Subjects</div>
          <div class="fs-4 fw-bold"><?= $totalSubjects ?></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0"><a href="<?= $baseUrl ?>/subject.php" class="small">Manage</a></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body d-flex align-items-center">
        <div class="rounded-circle bg-secondary bg-opacity-10 p-3 me-3"><i class="bi bi-calendar-range text-secondary fs-4"></i></div>
        <div>
          <div class="text-muted small">Academic Years</div>
          <div class="fs-4 fw-bold"><?= $totalAcademicYears ?></div>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0"><a href="<?= $baseUrl ?>/academic_years.php" class="small">Manage</a></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white">
    <strong><i class="bi bi-lightning me-2"></i>Quick Actions</strong>
  </div>
  <div class="card-body">
    <div class="row g-2">
      <div class="col-md-4"><a href="<?= $baseUrl ?>/academic_years.php" class="btn btn-outline-primary w-100"><i class="bi bi-calendar-range me-1"></i>Academic Years</a></div>
      <div class="col-md-4"><a href="<?= $baseUrl ?>/upload_students.php" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1"></i>Upload Enrollments</a></div>
      <div class="col-md-4"><a href="<?= $baseUrl ?>/schedules.php" class="btn btn-outline-primary w-100"><i class="bi bi-calendar3 me-1"></i>Schedules</a></div>
      <div class="col-md-4"><a href="<?= $baseUrl ?>/users.php" class="btn btn-outline-secondary w-100"><i class="bi bi-person-gear me-1"></i>User Accounts</a></div>
      <div class="col-md-4"><a href="<?= $baseUrl ?>/logs.php" class="btn btn-outline-secondary w-100"><i class="bi bi-journal-text me-1"></i>System Logs</a></div>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
