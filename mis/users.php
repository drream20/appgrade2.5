<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$message = '';
$error = '';

// Modified password reset to work instantly on button click with confirmation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $type = $_POST['user_type'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // Only support MIS Users and Registrars
    if ($type !== 'mis' && $type !== 'registrar') {
        $error = 'Unsupported user type.';
    } else {
        // --- Add MIS or Registrar user ---
        if ($action === 'add') {
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $fullName = trim($_POST['full_name'] ?? '');
            $empId = trim($_POST['employee_id'] ?? '') ?: null;

            if ($email && $password && $fullName) {
                $hash = hash_password_for_storage($password);
                $tbl = $type === 'mis' ? 'mis_users' : 'registrars';
                $cols = 'email, password_hash, full_name, employee_id';
                $vals = [$email, $hash, $fullName, $empId];
                try {
                    $db->prepare("INSERT INTO $tbl ($cols) VALUES (?,?,?,?)")->execute($vals);
                    logActivity('mis', currentMisUserId(), 'create', $type, (int)$db->lastInsertId(), $fullName);
                    $message = ucfirst($type) . ' account created.';
                } catch (PDOException $e) {
                    $error = $e->getCode() == 23000 ? 'Email already exists.' : $e->getMessage();
                }
            } else {
                $error = 'Email, password, and full name are required.';
            }
        }
        // --- Delete MIS or Registrar user ---
        elseif ($action === 'delete') {
            if ($type === 'mis') {
                $tbl = 'mis_users';
                if ($id === currentMisUserId()) {
                    $error = 'Cannot delete your own MIS account.';
                } else {
                    $stmt = $db->prepare("DELETE FROM $tbl WHERE id = ?");
                    $stmt->execute([$id]);
                    if ($stmt->rowCount()) {
                        logActivity('mis', currentMisUserId(), 'delete', $type, $id);
                        $message = 'Account deleted.';
                    }
                }
            } elseif ($type === 'registrar') {
                $tbl = 'registrars';
                $stmt = $db->prepare("DELETE FROM $tbl WHERE id = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount()) {
                    logActivity('mis', currentMisUserId(), 'delete', $type, $id);
                    $message = 'Account deleted.';
                }
            }
        }
        // --- Reset password for MIS or Registrar user ---
        elseif ($action === 'reset_password' && $id && ($type === 'mis' || $type === 'registrar')) {
            $newPass = APPGRADE_DEFAULT_PASSWORD;
            $hash = hash_password_for_storage($newPass);
            $tbl = $type === 'mis' ? 'mis_users' : 'registrars';
            $stmt = $db->prepare("UPDATE $tbl SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);
            if ($stmt->rowCount()) {
                logActivity('mis', currentMisUserId(), 'reset_password', $type, $id);
                $message = 'Password has been reset to the default.';
            } else {
                $error = 'Password reset failed or no changes made.';
            }
        }
    }
}

$misUsers = $db->query("SELECT id, email, full_name, employee_id FROM mis_users")->fetchAll(PDO::FETCH_ASSOC);
$registrars = $db->query("SELECT id, email, full_name, employee_id FROM registrars")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'User Accounts';
$currentPage = 'users';
ob_start();
?>
<!-- Icons from Bootstrap Icons CDN -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Manage User Accounts</h4>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row">
  <div class="col-md-6 mb-4">
    <div class="card h-100">
      <div class="card-header bg-white d-flex justify-content-between">
        <strong>MIS Users</strong>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal" data-type="mis">
          <i class="bi bi-plus-circle me-1"></i> Add
        </button>
      </div>
      <div class="card-body p-2">
        <table class="table table-sm mb-0 align-middle">
          <?php foreach ($misUsers as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td>
              <form method="post" class="d-inline reset-form" onsubmit="return confirmResetPassword('<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>');">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_type" value="mis">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn reset-btn" title="Reset password to default">
                  <i class="bi bi-arrow-repeat me-1"></i> Reset
                </button>
              </form>
              <?php if ($u['id'] != currentMisUserId()): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_type" value="mis">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn delete-btn" title="Delete Account">
                  <i class="bi bi-trash me-1"></i> Delete
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6 mb-4">
    <div class="card h-100">
      <div class="card-header bg-white d-flex justify-content-between ">
        <strong>Registrars</strong>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addModal" data-type="registrar">
          <i class="bi bi-plus-circle me-1"></i> Add
        </button>
      </div>
      <div class="card-body p-2">
        <table class="table table-sm mb-0 align-middle">
          <?php foreach ($registrars as $u): ?>
          <tr>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td>
              <form method="post" class="d-inline reset-form" onsubmit="return confirmResetPassword('<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>');">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_type" value="registrar">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn reset-btn" title="Reset password to default">
                  <i class="bi bi-arrow-repeat me-1"></i> Reset
                </button>
              </form>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="user_type" value="registrar">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button type="submit" class="btn delete-btn" title="Delete Account">
                  <i class="bi bi-trash me-1"></i> Delete
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="user_type" id="addUserType" value="mis">
        <div class="modal-header"><h5 class="modal-title" id="addModalTitle">Add MIS User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-2 "><label class="form-label">Email *</label><input type="email" name="email" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Password *</label><input type="password" name="password" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Full Name *</label><input type="text" name="full_name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Employee ID</label><input type="text" name="employee_id" class="form-control"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Add</button></div>
      </form>
    </div>
  </div>
</div>

<!-- Remove the old password modal, since password is reset automatically -->

<style>
/* Reset and Delete button minimalist: 1px border, color only on hover, subtle otherwise */
.reset-btn {
  border: 1.3px solid #dee2e6 !important;
  border-radius: 6px !important;
  color: #0d6efd !important;
  padding-left: 0.7em;
  font-size: 12px !important;
  padding-right: 0.7em;
  background: transparent !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 0.25em;
  transition: border 0.2s, background 0.2s, color 0.2s;
}
.reset-btn:hover, .reset-btn:focus {
  border-color: #0d6efd !important;
  background-color: #0d6efd !important;
  color:rgb(255, 255, 255) !important;
  font-size: 12px !important;
  text-decoration: none !important;
}

.delete-btn {
  border: 1.3px solid #dc3545 !important;
  border-radius: 4px !important;
  color: #dc3545 !important;
  font-size: 12px !important;
  padding-left: 0.7em;
  padding-right: 0.7em;
  background: transparent !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 0.25em;
  transition: border 0.2s, background 0.2s, color 0.2s;
}
.delete-btn:hover, .delete-btn:focus {
  border-color:rgb(199, 18, 33) !important;
  background-color:rgb(199, 18, 33) !important;
  color:rgb(255, 255, 255) !important;
  font-size: 12px !important;
  text-decoration: none !important;
}
</style>
<script>
document.getElementById('addModal')?.addEventListener('show.bs.modal', function(e) {
  var btn = e.relatedTarget;
  var title = document.getElementById('addModalTitle');
  if (btn?.dataset?.type) {
    document.getElementById('addUserType').value = btn.dataset.type;
    if (title) {
      title.textContent = btn.dataset.type === 'mis' ? 'Add MIS User' : 'Add Registrar';
    }
  }
});

// Confirm before instantly resetting password
function confirmResetPassword(name) {
  return confirm('Reset the password for "' + name + '" to the default?');
}
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
