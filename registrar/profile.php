<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
ensureRegistrarProfileColumns($db);

$registrar = currentRegistrar();
$message = '';
$err = '';

$avatarPath = !empty($registrar['avatar_path']) ? $registrar['avatar_path'] : null;
$phoneValue = $registrar['phone'] ?? '';
$addressValue = $registrar['address'] ?? '';
$avatarUrl = $avatarPath
    ? htmlspecialchars($avatarPath)
    : rtrim(dirname($baseUrl), '/') . '/instructor/assets/img/default-user.png';

function removeRegistrarProfileImage($db, $id) {
    $stmt = $db->prepare('SELECT avatar_path FROM registrars WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['avatar_path'])) {
        $relative = str_replace(dirname($_SERVER['SCRIPT_NAME']), '', $row['avatar_path']);
        $filePath = __DIR__ . $relative;
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }
    $stmt = $db->prepare('UPDATE registrars SET avatar_path = NULL, updated_at = NOW() WHERE id=?');
    $stmt->execute([$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $employeeId = trim($_POST['employee_id'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $profileImagePath = $avatarPath;

    $id = currentRegistrarId();

    if (!$fullName) {
        $err = 'Full name is required.';
    }

    if (!$err && isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        removeRegistrarProfileImage($db, $id);
        $profileImagePath = null;
        $message = 'Profile image removed successfully.';
    } elseif (!$err && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmp = $_FILES['profile_image']['tmp_name'];
        $fileName = basename($_FILES['profile_image']['name']);
        $fileType = mime_content_type($fileTmp);
        $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
        if (in_array($fileType, $allowed)) {
            $uploadsDir = __DIR__ . '/assets/uploads/registrars/';
            @mkdir($uploadsDir, 0777, true);
            $ext = pathinfo($fileName, PATHINFO_EXTENSION);
            $fileSave = $uploadsDir . 'registrar_' . $id . '.' . $ext;
            move_uploaded_file($fileTmp, $fileSave);
            $profileImagePath = $baseUrl . '/assets/uploads/registrars/registrar_' . $id . '.' . $ext;
        } else {
            $err = 'Invalid image format. Only PNG, JPG, JPEG, and WEBP are allowed.';
        }
    }

    if (!$err && $newPassword !== '' && $newPassword !== $confirmPassword) {
        $err = 'New password and confirmation do not match.';
    } elseif (!$err && strlen($newPassword) > 0 && strlen($newPassword) < 6) {
        $err = 'Password must be at least 6 characters.';
    }

    if (!$err) {
        if ($newPassword !== '') {
            $hash = hash_password_for_storage($newPassword);
            $stmt = $db->prepare('UPDATE registrars SET full_name=?, employee_id=?, phone=?, address=?, avatar_path=?, password_hash=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$fullName, $employeeId, $phone, $address, $profileImagePath, $hash, $id]);
        } else {
            $stmt = $db->prepare('UPDATE registrars SET full_name=?, employee_id=?, phone=?, address=?, avatar_path=?, updated_at=NOW() WHERE id=?');
            $stmt->execute([$fullName, $employeeId, $phone, $address, $profileImagePath, $id]);
        }
        if (!$message) {
            $message = 'Profile updated successfully.';
        }
        resetCurrentRegistrarCache();
        $registrar = currentRegistrar();
        $avatarUrl = !empty($registrar['avatar_path'])
            ? htmlspecialchars($registrar['avatar_path'])
            : rtrim(dirname($baseUrl), '/') . '/instructor/assets/img/default-user.png';
        $phoneValue = $registrar['phone'] ?? '';
        $addressValue = $registrar['address'] ?? '';
    }
}

$pageTitle = 'My Profile';
$currentPage = 'profile';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Profile</h4>
</div>
<div class="alert alert-info py-2 mb-2 small" style="font-size: 11px;">
  <i class="bi bi-info-circle"></i>
  <strong>Note:</strong> Your email address cannot be edited. Contact administration if you need to change it.<br>
  <i class="bi bi-info-circle"></i>
  <strong>Password:</strong> If you change your password, make sure to remember it — your new password will be required for future logins.
</div>
<?php if ($message): ?>
  <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger mb-3"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="row g-4 align-items-stretch justify-content-center">
  <div class="col-12 col-md-4 d-flex align-items-stretch justify-content-center mb-3 mb-md-0">
    <div class="w-100 d-flex flex-column h-100">
      <div class="card flex-grow-1 shadow-sm h-100">
        <div class="card-body d-flex flex-column align-items-center text-center p-3 pb-2 h-100">
          <div class="position-relative mb-2" style="width: 140px; min-height: 140px;">
            <div class="rounded-circle overflow-hidden border" style="width: 140px; height: 140px; background:#f8f9fa;">
              <img src="<?= $avatarUrl ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
            </div>
            <div class="position-absolute bottom-0 start-50 translate-middle-x d-flex gap-2" style="transform: translate(-50%, 50%)">
              <label class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                     style="width:38px;height:38px;cursor:pointer;" title="Add or change photo">
                <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
                <input type="file" name="profile_image" accept="image/png, image/jpeg, image/jpg, image/webp"
                       class="d-none" form="registrarProfileForm">
              </label>
              <?php if ($avatarPath): ?>
                <button type="submit" name="remove_image" value="1" form="registrarProfileForm"
                        class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                        style="width:38px;height:38px;" title="Remove photo"
                        onclick="return confirm('Remove profile photo?');">
                  <i class="bi bi-trash-fill text-danger fs-5"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
          <p class="mb-1 fw-semibold mt-3"><?= htmlspecialchars($registrar['full_name'] ?? '') ?></p>
          <?php if (!empty($registrar['email'])): ?>
            <p class="mb-0 text-muted small"><?= htmlspecialchars($registrar['email']) ?></p>
          <?php endif; ?>
          <?php if (!empty($registrar['employee_id'])): ?>
            <p class="mb-0 text-muted small">ID: <?= htmlspecialchars($registrar['employee_id']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-8">
    <div class="card shadow-sm h-100">
      <div class="card-body p-3">
        <form method="post" enctype="multipart/form-data" id="registrarProfileForm" novalidate>
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Full name</label>
              <input type="text" name="full_name" class="form-control" required
                     value="<?= htmlspecialchars($registrar['full_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Email</label>
              <input type="email" class="form-control" value="<?= htmlspecialchars($registrar['email'] ?? '') ?>"
                     readonly tabindex="-1" style="background-color:#f8f9fa;">
              <small class="text-muted">You cannot change your email address.</small>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Employee ID</label>
              <input type="text" name="employee_id" class="form-control"
                     value="<?= htmlspecialchars($registrar['employee_id'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Phone</label>
              <input type="text" name="phone" class="form-control"
                     value="<?= htmlspecialchars($phoneValue) ?>">
            </div>
            <div class="col-12">
              <label class="form-label mb-1">Address</label>
              <input type="text" name="address" class="form-control"
                     value="<?= htmlspecialchars($addressValue) ?>">
            </div>

            <div class="col-12"><hr class="my-2"></div>

            <div class="col-12 col-md-6">
              <label class="form-label mb-1">New password</label>
              <div class="input-group">
                <input type="password" name="new_password" id="newPassword" class="form-control" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" id="toggleNewPassword" style="border: 1px solid #ced4da;">
                  <i class="bi bi-eye" id="iconNewPassword"></i>
                </button>
              </div>
              <small class="text-muted">Leave blank if not changing</small>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Confirm new password</label>
              <div class="input-group">
                <input type="password" name="confirm_password" id="confirmPassword" class="form-control" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" id="toggleConfirmPassword" style="border: 1px solid #ced4da;">
                  <i class="bi bi-eye" id="iconConfirmPassword"></i>
                </button>
              </div>
              <small class="text-muted">&nbsp;</small>
            </div>

            <div class="col-12 d-flex align-items-end justify-content-end">
              <button type="submit" class="btn btn-primary px-4 w-100 w-md-auto">
                <i class="bi bi-save me-1"></i> Save changes
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<style>
  @media (max-width: 991.98px) {
    .card-body.p-3, .card-body.pb-2 { padding: 1rem !important; }
    .card { margin-bottom: 1rem; }
    .col-md-4, .col-md-6, .col-md-8 { margin-bottom: 0.75rem; }
  }
  .col-md-4 > .w-100, .col-md-4 .card { height: 100%; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('registrarProfileForm');
  if (!form) return;

  document.querySelectorAll('input[type="file"][name="profile_image"][form="registrarProfileForm"]')
    .forEach(function (input) {
      input.addEventListener('change', function () {
        if (input.files && input.files.length > 0) form.submit();
      });
    });

  function togglePasswordVisibility(inputId, iconId) {
    var input = document.getElementById(inputId);
    var icon = document.getElementById(iconId);
    if (!input || !icon) return;
    if (input.type === 'password') {
      input.type = 'text';
      icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
      input.type = 'password';
      icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
  }

  var btnNew = document.getElementById('toggleNewPassword');
  if (btnNew) btnNew.addEventListener('click', function () { togglePasswordVisibility('newPassword', 'iconNewPassword'); });

  var btnConfirm = document.getElementById('toggleConfirmPassword');
  if (btnConfirm) btnConfirm.addEventListener('click', function () { togglePasswordVisibility('confirmPassword', 'iconConfirmPassword'); });
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
