<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
// Use the same program mapping as deans to standardize courses.
if (!function_exists('deanPrograms')) {
    require __DIR__ . '/../dean/includes/auth.php';
}
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();
$programs = deanPrograms();

$message = '';
$error = '';

// Default password: APPGRADE_DEFAULT_PASSWORD (includes/password_payload.php)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $studentId = trim($_POST['student_id'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $givenName = trim($_POST['given_name'] ?? '');
        $middleName = trim($_POST['middle_name'] ?? '');
        $gender = strtoupper(substr(trim($_POST['gender'] ?? ''), 0, 1)) ?: null;
        // Course holds the short code (e.g., BSCS); department holds full program name for dean filters.
        $courseKey = $_POST['course'] ?? '';
        $course = $courseKey !== '' ? $courseKey : null;
        $dept = null;
        if ($courseKey !== '' && isset($programs[$courseKey])) {
            $dept = $programs[$courseKey];
        } else {
            $dept = trim($_POST['department'] ?? '') ?: null;
        }
        if ($studentId && $fullName) {
            try {
                // Use named parameters ONLY for this query to avoid mixing named and positional parameters
                $stmt = $db->prepare("INSERT INTO students 
                    (student_id, full_name, email, last_name, given_name, middle_name, gender, course, department, password_hash) 
                    VALUES (:student_id, :full_name, :email, :last_name, :given_name, :middle_name, :gender, :course, :department, :password_hash)");
                $stmt->execute([
                    ':student_id' => $studentId,
                    ':full_name' => $fullName,
                    ':email' => $email ?: null,
                    ':last_name' => $lastName ?: null,
                    ':given_name' => $givenName ?: null,
                    ':middle_name' => $middleName ?: null,
                    ':gender' => $gender,
                    ':course' => $course,
                    ':department' => $course,
                    ':password_hash' => hash_password_for_storage(APPGRADE_DEFAULT_PASSWORD),
                ]);
                logActivity('mis', currentMisUserId(), 'create', 'student', (int)$db->lastInsertId(), $fullName);
                $message = 'Student added.';
            } catch (PDOException $e) {
                $error = $e->getCode() == 23000 ? 'Student ID already exists.' : $e->getMessage();
            }
        } else {
            $error = 'Student ID and Full Name are required.';
        }
    } elseif ($action === 'delete' && ($id = (int)($_POST['id'] ?? 0))) {
        $stmt = $db->prepare("DELETE FROM students WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'delete', 'student', $id);
            $message = 'Student deleted.';
        }
    } elseif ($action === 'reset_password' && ($id = (int)($_POST['id'] ?? 0))) {
        // Automatic reset to default password
        $defaultPass = APPGRADE_DEFAULT_PASSWORD;
        $hash = hash_password_for_storage($defaultPass);
        $stmt = $db->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'update', 'student', $id, 'Reset portal password to default');
            $message = 'Student portal password has been reset to default.';
        }
    } elseif ($action === 'set_password' && ($id = (int)($_POST['id'] ?? 0))) {
        $newPass = $_POST['password'] ?? '';
        if (strlen($newPass) >= 4) {
            $hash = hash_password_for_storage($newPass);
            $stmt = $db->prepare("UPDATE students SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            if ($stmt->rowCount()) {
                logActivity('mis', currentMisUserId(), 'update', 'student', $id, 'Set portal password');
                $message = 'Student portal password set.';
            }
        } else {
            $error = 'Password must be at least 4 characters.';
        }
    }
}

// === PAGINATION LOGIC ===
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$students = [];
$sel = "id, student_id, full_name, email";
try {
    $stmt = $db->query("SHOW COLUMNS FROM students LIKE 'last_name'");
    if ($stmt->fetch()) $sel .= ", last_name, given_name, middle_name, gender, course, department";
} catch (Throwable $e) {}

$params = [];
$where = '';
if ($search !== '') {
    $where = "WHERE student_id LIKE :p OR full_name LIKE :p OR email LIKE :p";
    $params[':p'] = '%' . $search . '%';
}

// Count total students (FOR PAGING)
$countSql = "SELECT COUNT(*) FROM students " . ($where ?: "");
$countStmt = $db->prepare($countSql);
if ($params) $countStmt->execute($params);
else $countStmt->execute();
$totalStudents = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalStudents/$perPage));
if ($page > $totalPages) $page = $totalPages > 0 ? $totalPages : 1;
$offset = ($page-1)*$perPage;

// Retrieve students for this page
if ($where) {
    $dataSql = "SELECT $sel FROM students $where ORDER BY full_name LIMIT :limit OFFSET :offset";
    $dataStmt = $db->prepare($dataSql);
    foreach ($params as $k => $v) $dataStmt->bindValue($k, $v, PDO::PARAM_STR);
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $students = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $dataSql = "SELECT $sel FROM students ORDER BY full_name LIMIT :limit OFFSET :offset";
    $dataStmt = $db->prepare($dataSql);
    $dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    $students = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Students';
$currentPage = 'students';
ob_start();
?>
<style>
.students-header-sticky {
  position: sticky;
  top: 0;
  z-index: 1020;
  /* background:rgb(156, 156, 156); */
  padding-top: 0.5rem;
}
.students-table-scroll {
  /* Adaptive max-height using responsive breakpoints */
  max-height: 55vh;
  overflow-y: auto;
}
@media (max-width: 991.98px) {
  .students-table-scroll {
    max-height: 45vh;
  }
}
@media (max-width: 767.98px) {
  .students-table-scroll {
    max-height: 38vh;
  }
}
@media (max-width: 575.98px) {
  .students-table-scroll {
    max-height: 30vh;
  }
}
.students-table-scroll thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: #f8f9fa;
  background-color: rgb(38, 77, 132) !important;
  color: #fff;
}
/* Make the table horizontally scrollable on small screens */
@media (max-width: 767.98px) {
  .students-table-scroll {
    max-width: 100vw;
    overflow-x: auto;
  }
  .students-table-scroll table {
    min-width: 600px;
  }
}
/* Center pagination nav */
.students-pagination-center {
  display: flex;
  justify-content: center !important;
  align-items: center;
  width: 100%;
  padding: 0.75rem 0 0.35rem 0;
}
</style>

<div class="students-header-sticky mb-2">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
    <h4 class="mb-0">Manage Students</h4>
    <div class="mt-2 mt-md-0">
      <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Student</button>
      <a href="<?= $baseUrl ?>/upload_students.php" class="btn btn-outline-primary btn-sm">Upload Enrollments</a>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success py-2 mb-2"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2 mb-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card mb-0">
    <div class="card-body py-2">
      <form method="get" class="row g-2">
        <div class="col-8 col-md-10">
          <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by ID, name, email..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="col-4 col-md-2">
          <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Search</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive students-table-scroll">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>Student ID</th>
            <th>Name</th>
            <th>Gender</th>
            <th>Course</th>
            <th>Email</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['student_id']) ?></td>
            <td><?= htmlspecialchars($s['full_name']) ?></td>
            <td><?= htmlspecialchars($s['gender'] ?? '-') ?></td>
            <td><?= htmlspecialchars($s['course'] ?? '-') ?></td>
            <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
            <td>
              <form method="post" class="d-inline" onsubmit="return confirm('Reset this student\'s password to default?');">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">Reset</button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this student?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($students)): ?><p class="text-muted p-3 mb-0">No students found.</p><?php endif; ?>

    <?php if ($totalPages > 1): ?>
      <nav class="students-pagination-center">
        <ul class="pagination pagination-sm mb-0">
          <?php
              // Get URL and preserve search
              $getVars = $_GET;

              // Show 'First' and 'Previous'
              $getVars['page'] = 1;
              $firstUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              $getVars['page'] = max(1, $page - 1);
              $prevUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);

              // Show 'Next' and 'Last'
              $getVars['page'] = min($totalPages, $page + 1);
              $nextUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              $getVars['page'] = $totalPages;
              $lastUrl = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);

              // Choose range: 2 before and after current (max 5 pages)
              $displayRange = 2;
              $startP = max(1, $page - $displayRange);
              $endP = min($totalPages, $page + $displayRange);
              if ($endP - $startP < $displayRange*2) {
                if ($startP == 1) $endP = min($totalPages, $startP + $displayRange*2);
                if ($endP == $totalPages) $startP = max(1, $endP - $displayRange*2);
              }
          ?>

            <!-- First and Previous -->
            <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $firstUrl ?>" tabindex="-1">First</a>
            </li>
            <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $prevUrl ?>" tabindex="-1">&laquo;</a>
            </li>

            <?php if ($startP > 1): ?>
              <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>

            <?php for ($p = $startP; $p <= $endP; $p++): ?>
              <?php
                $getVars['page'] = $p;
                $url = htmlspecialchars($_SERVER['PHP_SELF']) . '?' . http_build_query($getVars);
              ?>
              <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $url ?>"><?= $p ?></a>
              </li>
            <?php endfor; ?>

            <?php if ($endP < $totalPages): ?>
              <li class="page-item disabled"><span class="page-link">…</span></li>
            <?php endif; ?>

            <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $nextUrl ?>">&raquo;</a>
            </li>
            <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $lastUrl ?>">Last</a>
            </li>
        </ul>
      </nav>
    <?php endif; ?>

  </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header"><h5 class="modal-title">Add Student</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="add">
          <div class="mb-2"><label class="form-label">Student ID *</label><input type="text" name="student_id" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
          <div class="row">
            <div class="col"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control"></div>
            <div class="col"><label class="form-label">Given Name</label><input type="text" name="given_name" class="form-control"></div>
            <div class="col"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control"></div>
          </div>
          <div class="row mt-2">
            <div class="col"><label class="form-label">Gender</label><input type="text" name="gender" class="form-control" placeholder="M/F"></div>
            <div class="col">
              <label class="form-label">Course / Program</label>
              <select name="course" class="form-select">
                <option value="">— Select program —</option>
                <?php foreach ($programs as $key => $name): ?>
                  <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($key . ' — ' . $name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col">
              <label class="form-label">Department (optional override)</label>
              <input type="text" name="department" class="form-control" placeholder="Leave blank to use program name">
            </div>
          </div>
          <div class="mt-2"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div>
      </form>
    </div>
  </div>
</div>

<!-- The manual set password modal remains available for admin use if needed -->
<div class="modal fade" id="pwModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header"><h5 class="modal-title">Set Student Portal Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="set_password">
          <input type="hidden" name="id" id="pwStudentId" value="">
          <p class="text-muted small">Student ID: <strong id="pwStudentName"></strong></p>
          <div class="mb-2"><label class="form-label">New Password (min 4 chars)</label><input type="password" name="password" class="form-control" required minlength="4"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Set Password</button></div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('pwModal').addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  document.getElementById('pwStudentId').value = btn.dataset.studentId;
  document.getElementById('pwStudentName').textContent = btn.dataset.studentName;
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
