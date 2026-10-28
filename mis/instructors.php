<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
// Share the same program list as deans for consistency.
if (!function_exists('deanPrograms')) {
    require __DIR__ . '/../dean/includes/auth.php';
}
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();
$programs = deanPrograms();

$message = '';
$error = '';
$resetSuccess = false;

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '') ?: null;
        // Department holds the full program name, to match Dean program filters.
        $dept = trim($_POST['department'] ?? '') ?: null;
        if ($email && $password && $fullName) {
            $hash = hash_password_for_storage($password);
            try {
                $db->prepare("INSERT INTO instructors (email, password_hash, full_name, employee_id, department) VALUES (?,?,?,?,?)")
                    ->execute([$email, $hash, $fullName, $employeeId, $dept]);
                logActivity('mis', currentMisUserId(), 'create', 'instructor', (int)$db->lastInsertId(), $fullName);
                $message = 'Instructor added.';
            } catch (PDOException $e) {
                $error = $e->getCode() == 23000 ? 'Email already exists.' : $e->getMessage();
            }
        } else {
            $error = 'Email, password, and full name are required.';
        }
    } elseif ($action === 'upload_csv') {
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $path = $_FILES['csv_file']['tmp_name'];
            $rows = [];
            if (($h = fopen($path, 'r')) !== false) {
                $bom = fread($h, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($h);
                }
                while (($r = fgetcsv($h)) !== false) {
                    $rows[] = $r;
                }
                fclose($h);
            }
            if (count($rows) < 2) {
                $error = 'CSV must have a header row and at least one data row.';
            } else {
                $header = array_map('trim', array_map('strtolower', $rows[0]));
                // Expected columns (format: employee_id, email, firstname, middlename, lastname, department, phone, address)
                $expectedCols = [
                    'employee_id',
                    'email',
                    'firstname',
                    'middlename',
                    'lastname',
                    'department',
                    'phone',
                    'address'
                ];
                $mismatch = false;
                foreach ($expectedCols as $idx => $name) {
                    if (!isset($header[$idx]) || $header[$idx] !== strtolower($name)) {
                        $mismatch = true;
                        break;
                    }
                }
                if ($mismatch) {
                    $error = 'Invalid CSV header. Expected columns (in this order): employee_id, email, firstname, middlename, lastname, department, phone, address';
                } else {
                    $defaultPasswordHash = hash_password_for_storage(APPGRADE_DEFAULT_PASSWORD);
                    $created = 0;
                    $updated = 0;
                    $skipped = 0;
                    $rowErrors = [];
                    $upsert = $db->prepare(
                        "INSERT INTO instructors (email, password_hash, full_name, employee_id, department, phone, address)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                            full_name = VALUES(full_name),
                            employee_id = COALESCE(VALUES(employee_id), employee_id),
                            department = COALESCE(VALUES(department), department),
                            phone = COALESCE(VALUES(phone), phone),
                            address = COALESCE(VALUES(address), address)"
                    );
                    $findIdByEmail = $db->prepare("SELECT id FROM instructors WHERE email = ? LIMIT 1");
                    for ($i = 1; $i < count($rows); $i++) {
                        $r = $rows[$i];
                        // skip all-blank lines
                        if (count(array_filter($r, static function ($v) { return trim((string)$v) !== ''; })) === 0) {
                            continue;
                        }
                        // Mapping according to template:
                        // $r[0] => employee_id
                        // $r[1] => email
                        // $r[2] => firstname
                        // $r[3] => middlename
                        // $r[4] => lastname
                        // $r[5] => department
                        // $r[6] => phone
                        // $r[7] => address

                        $employeeId = isset($r[0]) ? trim((string)$r[0]) : null;
                        $email     = isset($r[1]) ? trim((string)$r[1]) : '';
                        $firstname = isset($r[2]) ? trim((string)$r[2]) : '';
                        $middlename = isset($r[3]) ? trim((string)$r[3]) : '';
                        $lastname  = isset($r[4]) ? trim((string)$r[4]) : '';
                        $department = isset($r[5]) ? trim((string)$r[5]) : null;
                        $phone = isset($r[6]) ? trim((string)$r[6]) : null;
                        $address = isset($r[7]) ? trim((string)$r[7]) : null;
                        $lineNum = $i + 1;

                        // Build full name: "FIRSTNAME M [optional] LASTNAME", skip empty tokens
                        $nameParts = [];
                        if ($firstname !== '') $nameParts[] = $firstname;
                        if ($middlename !== '') $nameParts[] = $middlename;
                        if ($lastname !== '') $nameParts[] = $lastname;
                        $fullName = trim(implode(' ', $nameParts));

                        if ($email === '' || $fullName === '') {
                            $rowErrors[] = "Row {$lineNum}: email and at least first + last name are required.";
                            $skipped++;
                            continue;
                        }
                        $email = strtolower($email);
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $rowErrors[] = "Row {$lineNum}: invalid email ({$email}).";
                            $skipped++;
                            continue;
                        }
                        try {
                            $upsert->execute([
                                $email,
                                $defaultPasswordHash,
                                $fullName,
                                $employeeId !== '' ? $employeeId : null,
                                $department !== '' ? $department : null,
                                $phone !== '' ? $phone : null,
                                $address !== '' ? $address : null,
                            ]);
                            $affected = (int)$upsert->rowCount();
                            if ($affected === 1) {
                                logActivity('mis', currentMisUserId(), 'create', 'instructor', (int)$db->lastInsertId(), $fullName);
                                $created++;
                            } else {
                                $findIdByEmail->execute([$email]);
                                $existingId = (int)$findIdByEmail->fetchColumn();
                                if ($existingId > 0) {
                                    logActivity('mis', currentMisUserId(), 'update', 'instructor', $existingId, $fullName);

                                    // Auto-upgrade legacy default password hashes when re-uploading instructors.
                                    $hashStmt = $db->prepare('SELECT password_hash FROM instructors WHERE id = ?');
                                    $hashStmt->execute([$existingId]);
                                    $existingHash = (string)($hashStmt->fetchColumn() ?? '');

                                    if (trim($existingHash) === '') {
                                        $db->prepare('UPDATE instructors SET password_hash = ? WHERE id = ?')
                                            ->execute([$defaultPasswordHash, $existingId]);
                                    } else {
                                        $upgradedHash = maybe_upgrade_legacy_default_password($existingHash);
                                        if ($upgradedHash !== null) {
                                            $db->prepare('UPDATE instructors SET password_hash = ? WHERE id = ?')
                                                ->execute([$upgradedHash, $existingId]);
                                        }
                                    }
                                }
                                $updated++;
                            }
                        } catch (PDOException $e) {
                            $rowErrors[] = "Row {$lineNum}: " . $e->getMessage();
                            $skipped++;
                        }
                    }
                    if ($created > 0 || $updated > 0) {
                        $message = "Upload complete. Created: {$created}, Updated: {$updated}.";
                        if ($skipped > 0) {
                            $message .= " Skipped: {$skipped}.";
                        }
                    }
                    if (!empty($rowErrors)) {
                        $maxErrors = 20;
                        $shown = array_slice($rowErrors, 0, $maxErrors);
                        $error = implode(' ', $shown);
                        if (count($rowErrors) > $maxErrors) {
                            $remaining = count($rowErrors) - $maxErrors;
                            $error .= " ({$remaining} more error(s) not shown.)";
                        }
                    }
                }
            }
        } else {
            $error = 'Please choose a CSV file to upload.';
        }
    } elseif ($action === 'delete' && ($id = (int)($_POST['id'] ?? 0))) {
        // Use prepared statement and check affected rows
        $stmt = $db->prepare("DELETE FROM instructors WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'delete', 'instructor', $id);
            $message = 'Instructor deleted.';
        }
    } elseif ($action === 'reset' && ($id = (int)($_POST['id'] ?? 0))) {
        $newPassword = hash_password_for_storage(APPGRADE_DEFAULT_PASSWORD);
        $stmt = $db->prepare("UPDATE instructors SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newPassword, $id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'reset-password', 'instructor', $id);
            // Set flag to show message and trigger meta refresh
            $resetSuccess = true;
            $message = 'Password has been reset to the default. Redirecting...';
        } else {
            $error = 'Failed to reset password.';
        }
    }
}

// Reset success indicator (from redirect, fallback; old method for compatibility)
if (isset($_GET['reset_success'])) {
    $message = 'Password has been reset to the default.';
    $resetSuccess = true;
}

// --- SEARCH LOGIC ---
$search = trim($_GET['search'] ?? '');
$searchSql = '';
$searchParams = [];
if ($search !== '') {
    $searchSql = "WHERE (email LIKE :search OR full_name LIKE :search OR employee_id LIKE :search OR department LIKE :search)";
    $searchParams[':search'] = '%' . $search . '%';
}

// Paging (same pattern as students.php)
$page = max(1, (int)($_GET['page'] ?? $_POST['return_page'] ?? 1));
$perPage = 20;

// Get total filtered row count
$countQuery = "SELECT COUNT(*) FROM instructors " . ($searchSql ? $searchSql : '');
$countStmt = $db->prepare($countQuery);
if ($searchSql) {
    foreach ($searchParams as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
}
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Get filtered rows
$listQuery = "SELECT id, email, full_name, employee_id, department FROM instructors " .
             ($searchSql ? $searchSql . " " : "") .
             "ORDER BY full_name LIMIT :limit OFFSET :offset";
$listStmt = $db->prepare($listQuery);
if ($searchSql) {
    foreach ($searchParams as $k => $v) {
        $listStmt->bindValue($k, $v);
    }
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$instructors = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$showFrom = $total === 0 ? 0 : $offset + 1;
$showTo = min($offset + $perPage, $total);

$pageTitle = 'Instructors';
$currentPage = 'instructors';
ob_start();
?>

<?php if ($resetSuccess): ?>
  <?php
    $refreshQs = $page > 1 ? '?page=' . (int)$page : '';
    if ($search !== '') {
      $refreshQs .= ($refreshQs ? '&' : '?') . 'search=' . urlencode($search);
    }
    $refreshUrl = $baseUrl . '/instructors.php' . $refreshQs;
  ?>
  <meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($refreshUrl) ?>">
<?php endif; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Manage Instructors</h4>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
    <i class="bi bi-plus-lg me-1"></i>Add Instructor
  </button>
</div>

<div class="mb-3 d-flex flex-wrap gap-2 align-items-end">
  <!-- SEARCH FORM -->
  <form method="get" class="mb-0 flex-grow-1" style="max-width:380px">
    <div class="input-group input-group-sm">
      <input type="text" class="form-control" name="search" placeholder="Search by name, email, employee ID, department..." value="<?= htmlspecialchars($search) ?>">
      <?php if ($page > 1): ?>
        <input type="hidden" name="page" value="<?= (int)$page ?>">
      <?php endif; ?>
      <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
      <?php if ($search !== ''): ?>
        <a href="<?= htmlspecialchars($baseUrl . '/instructors.php') ?>" class="btn btn-outline-secondary" title="Clear search">&times;</a>
      <?php endif; ?>
    </div>
  </form>
  <a href="<?= htmlspecialchars($baseUrl . '/download_instructors_template.php') ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-download me-1"></i>Download template
  </a>
  <button 
    type="button" 
    class="btn btn-sm"
    style="background-color: #28a745; color: #fff; border: none; box-shadow: none;"
    data-bs-toggle="modal" 
    data-bs-target="#uploadModal"
    onmouseover="this.style.backgroundColor='#28a745'" 
    onmouseout="this.style.backgroundColor='#28a745'"
  >
    <i class="bi bi-upload me-1"></i>Upload CSV
  </button>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <style>
        /* Set instructors header color */
        .table#instructors-table thead th,
        .table.instructors-table thead th,
        .table thead th {
          background-color: rgb(38, 77, 132) !important;
          color: #fff !important;
          vertical-align: middle;
        }
      </style>
      <table class="table table-hover mb-0" id="instructors-table">
        <thead>
          <tr><th>Email</th><th>Name</th><th>Employee ID</th><th>Department</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($instructors as $i): ?>
          <tr>
            <td><?= htmlspecialchars($i['email']) ?></td>
            <td><?= htmlspecialchars($i['full_name']) ?></td>
            <td><?= htmlspecialchars($i['employee_id'] ?? '-') ?></td>
            <!-- Show department as uploaded from CSV or added manually -->
            <td><?= htmlspecialchars($i['department'] ?? '-') ?></td>
            <td>
              <!-- Reset Password Button -->
              <form method="post" class="d-inline" onsubmit="return confirm('Reset this instructor\'s password to the default?');">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="return_page" value="<?= (int)$page ?>">
                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary me-1">Reset</button>
              </form>
              <!-- Delete Button -->
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this instructor?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="return_page" value="<?= (int)$page ?>">
                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($instructors)): ?>
      <p class="text-muted p-3 mb-0">
        <?php if ($total === 0 && $search === ''): ?>
          No instructors yet.
        <?php elseif ($total === 0 && $search !== ''): ?>
          No results for this search.
        <?php else: ?>
          No instructors on this page.
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <?php if ($total > 0): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2 border-top small text-muted">
        <span>Showing <?= (int)$showFrom ?>–<?= (int)$showTo ?> of <?= (int)$total ?></span>
      </div>
    <?php endif; ?>
    <?php if ($totalPages > 1): ?>
      <nav class="d-flex justify-content-center pb-3" aria-label="Instructors pagination">
        <ul class="pagination pagination-sm mb-0">
          <?php
          $self = htmlspecialchars($_SERVER['PHP_SELF']);
          $getVars = $_GET;
          $getVars['page'] = 1;
          $firstUrl = $self . '?' . http_build_query($getVars);
          $getVars['page'] = max(1, $page - 1);
          $prevUrl = $self . '?' . http_build_query($getVars);
          $getVars['page'] = min($totalPages, $page + 1);
          $nextUrl = $self . '?' . http_build_query($getVars);
          $getVars['page'] = $totalPages;
          $lastUrl = $self . '?' . http_build_query($getVars);
          $displayRange = 2;
          $startP = max(1, $page - $displayRange);
          $endP = min($totalPages, $page + $displayRange);
          if ($endP - $startP < $displayRange * 2) {
            if ($startP == 1) {
              $endP = min($totalPages, $startP + $displayRange * 2);
            }
            if ($endP == $totalPages) {
              $startP = max(1, $endP - $displayRange * 2);
            }
          }
          ?>
          <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $firstUrl . ($search !== '' ? '&search=' . urlencode($search) : '') ?>">First</a>
          </li>
          <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $prevUrl . ($search !== '' ? '&search=' . urlencode($search) : '') ?>">&laquo;</a>
          </li>
          <?php if ($startP > 1): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <?php for ($p = $startP; $p <= $endP; $p++): ?>
            <?php
            $getVars['page'] = $p;
            $url = $self . '?' . http_build_query($getVars);
            if ($search !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . 'search=' . urlencode($search);
            }
            ?>
            <li class="page-item <?= $p == $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= $url ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <?php if ($endP < $totalPages): ?>
            <li class="page-item disabled"><span class="page-link">…</span></li>
          <?php endif; ?>
          <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $nextUrl . ($search !== '' ? '&search=' . urlencode($search) : '') ?>">&raquo;</a>
          </li>
          <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $lastUrl . ($search !== '' ? '&search=' . urlencode($search) : '') ?>">Last</a>
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
        <div class="modal-header"><h5 class="modal-title">Add Instructor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="return_page" value="<?= (int)$page ?>">
          <input type="hidden" name="action" value="add">
          <div class="mb-2"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
          <div class="row">
            <div class="col"><label class="form-label">Employee ID</label><input type="text" name="employee_id" class="form-control"></div>
            <div class="col">
              <label class="form-label">Program</label>
              <select name="department" class="form-select">
                <option value="">— Select program —</option>
                <?php foreach ($programs as $key => $name): ?>
                  <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($key . ' — ' . $name) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Add</button></div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">Upload Instructors (CSV)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="return_page" value="<?= (int)$page ?>">
          <input type="hidden" name="action" value="upload_csv">
          <div class="mb-3">
            <label class="form-label">CSV File</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
          </div>
          <div class="alert alert-info small mb-0">
            <strong>Required columns (in this exact order):</strong><br>
            employee_id, email, firstname, middlename, lastname, department, phone, address<br>
            Only <strong>email</strong> and <strong>name (first + last)</strong> are required; other fields may be left blank.<br>
            The system will store <strong>department</strong>, <strong>phone</strong>, <strong>address</strong> if present.<br>
            The full name will be created from first + middle + last name.<br>
            Password for uploaded instructors will be set to the default (<code><?= htmlspecialchars(APPGRADE_DEFAULT_PASSWORD) ?></code>).
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload me-1"></i>Upload
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
