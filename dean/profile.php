<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../mis/includes/logger.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();
$deanId = currentDeanId();

// Directory and URLs for images
$profileImgDir = __DIR__ . '/uploads/dean_pics/';
$profileImgUrl = $baseUrl . '/uploads/dean_pics';

// Ensure upload directory exists
if (!is_dir($profileImgDir)) {
    @mkdir($profileImgDir, 0777, true);
}

// Load dean profile including image_path
$stmt = $db->prepare("SELECT id, email, full_name, employee_id, department, image_path FROM deans WHERE id = ?");
$stmt->execute([$deanId]);
$dean = $stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $email = trim($_POST['email'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $employeeId = trim($_POST['employee_id'] ?? '') ?: null;
        $department = trim($_POST['department'] ?? '') ?: null;
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$email || !$fullName) {
            $error = 'Email and Full Name are required.';
        } elseif ($new !== '' || $confirm !== '' || $current !== '') {
            if (strlen($new) < 6) {
                $error = 'New password must be at least 6 characters.';
            } elseif ($new !== $confirm) {
                $error = 'New password and confirmation do not match.';
            } else {
                $stmt = $db->prepare("SELECT password_hash FROM deans WHERE id = ?");
                $stmt->execute([$deanId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $hash = $row['password_hash'] ?? '';
                if (!$row || !verify_password_payload(normalize_password_input($current), $hash)) {
                    $error = 'Current password is incorrect.';
                }
            }
        } else {
            try {
                $stmt = $db->prepare("UPDATE deans SET email = ?, full_name = ?, employee_id = ?, department = ? WHERE id = ?");
                $stmt->execute([$email, $fullName, $employeeId, $department, $deanId]);
                if ($new !== '' && $error === '') {
                    $newHash = hash_password_for_storage($new);
                    $stmt = $db->prepare("UPDATE deans SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$newHash, $deanId]);
                    logActivity('dean', $deanId, 'change_password', 'dean', $deanId);
                }
                if ($stmt->rowCount()) {
                    logActivity('dean', $deanId, 'update_profile', 'dean', $deanId, $fullName);
                }
                $message = 'Profile updated successfully.';
                $dean['email'] = $email;
                $dean['full_name'] = $fullName;
                $dean['employee_id'] = $employeeId;
                $dean['department'] = $department;
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'upload_image') {
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $img = $_FILES['profile_image'];
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
            $type = mime_content_type($img['tmp_name']);
            if (!isset($allowed[$type])) {
                $error = 'Only JPEG, PNG, and GIF images are allowed.';
            } elseif ($img['size'] > 3 * 1024 * 1024) {
                $error = 'Image file size must not exceed 3MB.';
            } else {
                if (!empty($dean['image_path'])) {
                    @unlink($profileImgDir . $dean['image_path']);
                }
                $filename = "dean_{$deanId}_" . uniqid() . '.' . $allowed[$type];
                $dest = $profileImgDir . $filename;
                if (move_uploaded_file($img['tmp_name'], $dest)) {
                    $stmt = $db->prepare("UPDATE deans SET image_path = ? WHERE id = ?");
                    $stmt->execute([$filename, $deanId]);
                    logActivity('dean', $deanId, 'upload_profile_image', 'dean', $deanId);
                    $message = "Profile image uploaded successfully.";
                    $dean['image_path'] = $filename;
                } else {
                    $error = 'Failed to upload image.';
                }
            }
        } else {
            $error = 'No image selected, or upload error.';
        }
    } elseif ($action === 'delete_image') {
        if (!empty($dean['image_path'])) {
            $imgPath = $profileImgDir . $dean['image_path'];
            if (file_exists($imgPath)) {
                unlink($imgPath);
            }
            $stmt = $db->prepare("UPDATE deans SET image_path = NULL WHERE id = ?");
            $stmt->execute([$deanId]);
            logActivity('dean', $deanId, 'delete_profile_image', 'dean', $deanId);
            $message = "Profile image removed.";
            $dean['image_path'] = null;
        }
    }
}

// Recent login history for this dean
$logs = [];
try {
    $stmt = $db->prepare("SELECT action, entity_type, entity_id, details, ip_address, created_at FROM activity_logs WHERE user_type = 'dean' AND user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$deanId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $logs = [];
}

$pageTitle = 'Profile';
$currentPage = 'profile';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Profile</h4>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
<?php elseif ($message): ?>
  <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="profileImageForm" class="d-none">
  <input type="hidden" name="action" value="upload_image">
  <input type="file" name="profile_image" accept="image/*" id="profileImageInput">
</form>
<form method="post" id="deleteImageForm" class="d-none">
  <input type="hidden" name="action" value="delete_image">
</form>
<!-- Green note (light green, small) regarding profile image and changing password -->
<p class="small mt-1 mb-2" style="color: #155724; background-color: #d4edda; border-radius: 4px; padding: 6px 12px;">
  <i class="bi bi-info-circle me-1"></i>
  Only you can change your profile photo here. For extra security, consider updating your password regularly—see the "Change Password" section below.
</p>

<div class="row g-4 align-items-stretch justify-content-center">
  <div class="col-12 col-md-4 d-flex align-items-stretch justify-content-center mb-3 mb-md-0">
    <div class="w-100 d-flex flex-column h-100">
      <div class="card flex-grow-1 shadow-sm h-100">
        <div class="card-body d-flex flex-column align-items-center text-center p-3 pb-2 h-100">

          <?php
            $imageUrl = (!empty($dean['image_path']) && file_exists($profileImgDir . $dean['image_path']))
                ? ($profileImgUrl . '/' . rawurlencode($dean['image_path']) . '?t=' . filemtime($profileImgDir . $dean['image_path']))
                : null;
          ?>
          <div class="position-relative mb-2" style="width: 140px; min-height: 140px; height: auto;">
            <div class="rounded-circle overflow-hidden border" style="width: 140px; height: 140px; background:#f8f9fa;">
              <?php if ($imageUrl): ?>
                <img src="<?= htmlspecialchars($imageUrl) ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                  <i class="bi bi-person-circle" style="font-size:3.5rem;"></i>
                  <small>No photo</small>
                </div>
              <?php endif; ?>
            </div>
            <div class="position-absolute bottom-0 start-50 translate-middle-x d-flex gap-2" style="transform: translate(-50%, 50%)">
              <label class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                     style="width:38px;height:38px;cursor:pointer;" title="Add or change photo" for="profileImageInput">
                <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
              </label>
              <?php if ($imageUrl): ?>
                <button type="submit" form="deleteImageForm" class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                        style="width:38px;height:38px;" title="Remove photo" onclick="return confirm('Remove profile photo?');">
                  <i class="bi bi-trash-fill text-danger fs-5"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
          <p class="mb-1 fw-semibold mt-3"><?= htmlspecialchars($dean['full_name'] ?? '') ?></p>
          <p class="mb-0 text-muted small">Employee ID: <?= htmlspecialchars($dean['employee_id'] ?? 'N/A') ?></p>
          <?php if (!empty($dean['email'])): ?>
            <p class="mb-0 text-muted small"><?= htmlspecialchars($dean['email']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-8">
    <div class="card shadow-sm h-100">
      <div class="card-body p-3">
     
       
        <form method="post" id="profileForm" novalidate>
          <input type="hidden" name="action" value="update_profile">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Full Name</label>
              <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($dean['full_name'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Email</label>
              <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($dean['email'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Employee ID</label>
              <input type="text" name="employee_id" class="form-control" value="<?= htmlspecialchars($dean['employee_id'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Department</label>
              <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($dean['department'] ?? '') ?>">
            </div>
            <div class="col-12"><hr class="my-2"></div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Current Password</label>
              <div class="input-group mb-1">
                <input type="password" name="current_password" class="form-control password-toggle-input" id="profile-current-password">
                <button class="btn btn-outline-secondary password-toggle-btn" type="button" data-toggle-target="profile-current-password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Only required to change password</small>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">New Password</label>
              <div class="input-group mb-1">
                <input type="password" name="new_password" class="form-control password-toggle-input" id="profile-new-password">
                <button class="btn btn-outline-secondary password-toggle-btn" type="button" data-toggle-target="profile-new-password">
                  <i class="bi bi-eye"></i>
                </button>
              </div>
              <small class="text-muted">Min 6 characters</small>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Confirm New Password</label>
              <div class="input-group mb-1">
                <input type="password" name="confirm_password" class="form-control password-toggle-input" id="profile-confirm-password">
                <button class="btn btn-outline-secondary password-toggle-btn" type="button" data-toggle-target="profile-confirm-password">
                  <i class="bi bi-eye"></i>
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
  .password-toggle-input { min-width: 0; max-width: 100%; }
</style>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var profileImageInput = document.getElementById('profileImageInput');
    var profileImageForm = document.getElementById('profileImageForm');
    if (profileImageInput && profileImageForm) {
      profileImageInput.addEventListener('change', function () {
        if (profileImageInput.files && profileImageInput.files.length > 0) {
          profileImageForm.submit();
        }
      });
    }

    var passwordToggleBtns = document.querySelectorAll('.password-toggle-btn');
    passwordToggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var inputId = btn.getAttribute('data-toggle-target');
        var input = document.getElementById(inputId);
        if (!input) return;
        if (input.type === 'password') {
          input.type = 'text';
          btn.querySelector('i').classList.remove('bi-eye');
          btn.querySelector('i').classList.add('bi-eye-slash');
        } else {
          input.type = 'password';
          btn.querySelector('i').classList.remove('bi-eye-slash');
          btn.querySelector('i').classList.add('bi-eye');
        }
      });
    });
  });
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
