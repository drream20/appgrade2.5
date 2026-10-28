<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
// Reuse dean program list so deans are tied to specific programs (or Executive Dean).
if (!function_exists('deanPrograms')) {
    require __DIR__ . '/../dean/includes/auth.php';
}
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();
$programs = deanPrograms();

$message = '';
$error = '';
$shouldRefresh = false;
$refreshAction = null;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $fullName = trim($_POST['full_name'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '') ?: null;
        // Department holds the full program name, or blank for Executive Dean (all programs).
        $dept = trim($_POST['department'] ?? '') ?: null;
        if ($email && $password && $fullName) {
            $hash = hash_password_for_storage($password);
            try {
                $db->prepare("INSERT INTO deans (email, password_hash, full_name, employee_id, department) VALUES (?,?,?,?,?)")
                    ->execute([$email, $hash, $fullName, $employeeId, $dept]);
                logActivity('mis', currentMisUserId(), 'create', 'dean', (int)$db->lastInsertId(), $fullName);
                $message = 'Dean added.';
            } catch (PDOException $e) {
                $error = $e->getCode() == 23000 ? 'Email already exists.' : $e->getMessage();
            }
        } else {
            $error = 'Email, password, and full name are required.';
        }
    } elseif ($action === 'delete' && ($id = (int)($_POST['id'] ?? 0))) {
        $stmt = $db->prepare("DELETE FROM deans WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'delete', 'dean', $id);
            $message = 'Dean deleted.';
            $shouldRefresh = true;
            $refreshAction = 'delete';
        }
    } elseif ($action === 'reset_password' && ($id = (int)($_POST['id'] ?? 0))) {
        // Automatic reset to default password
        $defaultPass = APPGRADE_DEFAULT_PASSWORD;
        $hash = hash_password_for_storage($defaultPass);
        $stmt = $db->prepare("UPDATE deans SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'update', 'dean', $id, 'Reset dean password to default');
            $message = 'Dean password has been reset to default.';
            $shouldRefresh = true;
            $refreshAction = 'reset_password';
        }
    }
    // Do not header() refresh immediately; we will use JS after message for visual feedback
}

$deans = $db->query("SELECT id, email, full_name, employee_id, department FROM deans ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Deans';
$currentPage = 'deans';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Manage Deans</h4>
  <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg me-1"></i>Add Dean</button>
</div>

<?php if ($message): ?>
  <div class="alert alert-success" id="auto-refresh-message"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($shouldRefresh): ?>
<script>
  // Wait 1s, then reload the page so user sees the confirmation first
  setTimeout(function() {
    window.location = '<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>';
  }, 1000);
</script>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>Email</th><th>Name</th><th>Employee ID</th><th>Program / Role</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($deans as $d): ?>
          <?php
            $dept = trim($d['department'] ?? '');
            $label = $dept !== '' ? $dept : 'Executive Dean (All Programs)';
          ?>
          <tr>
            <td><?= htmlspecialchars($d['email']) ?></td>
            <td><?= htmlspecialchars($d['full_name']) ?></td>
            <td><?= htmlspecialchars($d['employee_id'] ?? '-') ?></td>
            <td><?= htmlspecialchars($label) ?></td>
            <td>
              <form method="post" class="d-inline" onsubmit="return confirm('Reset this dean\'s password to the default?');">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-primary">Reset</button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this dean?');">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header"><h5 class="modal-title">Add Dean</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <input type="hidden" name="action" value="add">
          <div class="mb-2"><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
          <div class="row">
            <div class="col"><label class="form-label">Employee ID</label><input type="text" name="employee_id" class="form-control"></div>
            <div class="col">
              <label class="form-label">Program / Role</label>
              <select name="department" class="form-select">
                <option value="">Executive Dean (All Programs)</option>
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
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
