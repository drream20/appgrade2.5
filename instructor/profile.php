<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Use avatar_path instead of profile_image
$instructor = currentInstructor();
$message = '';
$err = '';

// Get existing values for phone and address, showing them in the field.
$avatarPath = !empty($instructor['avatar_path']) ? $instructor['avatar_path'] : null;
$phoneValue = isset($instructor['phone']) ? $instructor['phone'] : '';
$addressValue = isset($instructor['address']) ? $instructor['address'] : '';
if ($avatarPath) {
    $avatarUrl = htmlspecialchars($avatarPath);
} else {
    $avatarUrl = $baseUrl . '/assets/img/default-user.png';
}

// Function to remove profile image from database and filesystem
function removeProfileImage($db, $id) {
    // Fetch old image path to delete from filesystem
    $stmt = $db->prepare('SELECT avatar_path FROM instructors WHERE id=?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['avatar_path'])) {
        $avatarPath = $row['avatar_path'];
        // Remove image from filesystem, matching URL to path
        $relative = str_replace(dirname($_SERVER['SCRIPT_NAME']), '', $avatarPath);
        $filePath = __DIR__ . $relative;
        if (file_exists($filePath) && is_file($filePath)) {
            @unlink($filePath);
        }
    }
    // Set avatar_path to NULL in database
    $stmt = $db->prepare('UPDATE instructors SET avatar_path = NULL, updated_at = NOW() WHERE id=?');
    $stmt->execute([$id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    // Email is NOT editable, do not get from POST
    $email = $instructor['email'];
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $profileImagePath = $avatarPath;

    $db = getDb();
    $id = currentInstructorId();

    // Validate email (just presence and format, even if not editable)
    if (!$email) {
        $err = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Invalid email format.';
    }

    // Handle remove image request
    if (!$err && isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
        removeProfileImage($db, $id);
        $profileImagePath = null;
        $message = 'Profile image removed successfully.';
    } elseif (!$err) {
        // Handle image upload
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmp = $_FILES['profile_image']['tmp_name'];
            $fileName = basename($_FILES['profile_image']['name']);
            $fileType = mime_content_type($fileTmp);
            $allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
            if (in_array($fileType, $allowed)) {
                $uploadsDir = __DIR__ . '/assets/uploads/instructors/';
                @mkdir($uploadsDir, 0777, true);
                $ext = pathinfo($fileName, PATHINFO_EXTENSION);
                $fileSave = $uploadsDir . 'instructor_' . currentInstructorId() . '.' . $ext;
                move_uploaded_file($fileTmp, $fileSave);
                $profileImagePath = $baseUrl . '/assets/uploads/instructors/instructor_' . currentInstructorId() . '.' . $ext;
            } else {
                $err = 'Invalid image format. Only PNG, JPG, JPEG, and WEBP are allowed.';
            }
        }
    }

    if (!$err && !$fullName) {
        $err = 'Full name is required.';
    } elseif (!$err && ($newPassword !== '' && $newPassword !== $confirmPassword)) {
        $err = 'New password and confirmation do not match.';
    } elseif (!$err && strlen($newPassword) > 0 && strlen($newPassword) < 6) {
        $err = 'Password must be at least 6 characters.';
    } elseif (!$err) {
        if ($newPassword !== '') {
            $hash = hash_password_for_storage($newPassword);
            $stmt = $db->prepare('UPDATE instructors SET full_name = ?, phone = ?, address = ?, avatar_path = ?, password_hash = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$fullName, $phone, $address, $profileImagePath, $hash, $id]);
        } else {
            $stmt = $db->prepare('UPDATE instructors SET full_name = ?, phone = ?, address = ?, avatar_path = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$fullName, $phone, $address, $profileImagePath, $id]);
        }
        if (!$message) {
            $message = 'Profile updated successfully.';
        }
        $instructor = currentInstructor();
        $avatarUrl = !empty($instructor['avatar_path']) ? htmlspecialchars($instructor['avatar_path']) : $baseUrl . '/assets/img/default-user.png';
        // Update phone and address values after update/post
        $phoneValue = isset($instructor['phone']) ? $instructor['phone'] : '';
        $addressValue = isset($instructor['address']) ? $instructor['address'] : '';
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
 <strong>Note:</strong> Your email address cannot be edited or removed. If you need to change your email, please contact administration.<br>
 <i class="bi bi-info-circle"></i>
 <strong>Password:</strong> If you change your password, make sure to remember it—your new password will be required for future logins.
</div>
<?php if ($message): ?>
  <div class="alert alert-success mb-3"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger mb-3"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div class="row g-4 align-items-stretch justify-content-center">
  <!-- Profile image column (mirroring student layout) -->
  <div class="col-12 col-md-4 d-flex align-items-stretch justify-content-center mb-3 mb-md-0">
    <div class="w-100 d-flex flex-column h-100">
      <div class="card flex-grow-1 shadow-sm h-100">
        <div class="card-body d-flex flex-column align-items-center text-center p-3 pb-2 h-100">
          <div class="position-relative mb-2" style="width: 140px; min-height: 140px; height: auto;">
            <div class="rounded-circle overflow-hidden border" style="width: 140px; height: 140px; background:#f8f9fa;">
              <img src="<?= $avatarUrl ?>"
                   alt="Profile photo"
                   style="width:100%;height:100%;object-fit:cover;">
            </div>
            <!-- Add/Edit and Delete Icon Buttons (floating) -->
            <div class="position-absolute bottom-0 start-50 translate-middle-x d-flex gap-2" style="transform: translate(-50%, 50%)">
              <!-- Add/Edit photo -->
              <label class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                     style="width:38px;height:38px;cursor:pointer;" title="Add or change photo">
                <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
                <input type="file"
                       name="profile_image"
                       accept="image/png, image/jpeg, image/jpg, image/webp"
                       class="d-none"
                       form="instructorProfileForm">
              </label>
              <!-- Remove photo -->
              <?php if ($avatarPath): ?>
                <button type="submit"
                        name="remove_image"
                        value="1"
                        form="instructorProfileForm"
                        class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                        style="width:38px;height:38px;"
                        title="Remove photo"
                        onclick="return confirm('Remove profile photo?');">
                  <i class="bi bi-trash-fill text-danger fs-5"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
          <p class="mb-1 fw-semibold mt-3"><?= htmlspecialchars($instructor['full_name'] ?? '') ?></p>
          <?php if (!empty($instructor['email'])): ?>
            <p class="mb-0 text-muted small"><?= htmlspecialchars($instructor['email']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Profile fields column -->
  <div class="col-12 col-md-8">
    <div class="card shadow-sm h-100">
      <div class="card-body p-3">
        <form method="post" enctype="multipart/form-data" id="instructorProfileForm" novalidate>
          <div class="row g-3">
            <!-- Full name -->
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Full name</label>
              <input type="text"
                     name="full_name"
                     class="form-control"
                     required
                     value="<?= htmlspecialchars($instructor['full_name'] ?? '') ?>">
            </div>
            <!-- Email (not editable) -->
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Email</label>
              <input type="email"
                     name="email"
                     class="form-control"
                     required
                     value="<?= htmlspecialchars($instructor['email'] ?? '') ?>"
                     readonly
                     tabindex="-1"
                     style="background-color:#f8f9fa;">
              <small class="text-muted">You cannot change your email address.</small>
            </div>

            <!-- Phone -->
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Phone</label>
              <input type="text"
                     name="phone"
                     class="form-control"
                     value="<?= htmlspecialchars($phoneValue) ?>">
            </div>
            <!-- Address -->
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Address</label>
              <input type="text"
                     name="address"
                     class="form-control"
                     value="<?= htmlspecialchars($addressValue) ?>">
            </div>

            <div class="col-12"><hr class="my-2"></div>

            <!-- Change password -->
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">New password</label>
              <div class="input-group">
                <input type="password"
                       name="new_password"
                       id="newPassword"
                       class="form-control"
                       autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" id="toggleNewPassword" style="border: 1px solid #ced4da;">
                  <i class="bi bi-eye" id="iconNewPassword"></i>
                </button>
              </div>
              <small class="text-muted">Leave blank if not changing</small>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label mb-1">Confirm new password</label>
              <div class="input-group">
                <input type="password"
                       name="confirm_password"
                       id="confirmPassword"
                       class="form-control"
                       autocomplete="new-password">
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

<!-- Responsive tweaks to better align with student profile -->
<style>
  @media (max-width: 991.98px) {
    .card-body.p-3, .card-body.pb-2 { padding: 1rem !important; }
    .card { margin-bottom: 1rem; }
    .col-md-4, .col-md-6, .col-md-8 { margin-bottom: 0.75rem; }
  }
  .col-md-4 > .w-100, .col-md-4 .card { height: 100%; }
</style>

<script>
  // Automatically submit the form when a new profile image is selected (mirroring student behavior)
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('instructorProfileForm');
    if (!form) return;

    var fileInputs = document.querySelectorAll('input[type="file"][name="profile_image"][form="instructorProfileForm"]');
    fileInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        if (input.files && input.files.length > 0) {
          form.submit();
        }
      });
    });

    // Password show/hide toggle for new/confirm password
    function togglePasswordVisibility(inputId, iconId) {
      var input = document.getElementById(inputId);
      var icon = document.getElementById(iconId);
      if (!input || !icon) return;

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
      }
    }

    var toggleNewPassword = document.getElementById('toggleNewPassword');
    if (toggleNewPassword) {
      toggleNewPassword.addEventListener('click', function () {
        togglePasswordVisibility('newPassword', 'iconNewPassword');
      });
    }
    var toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
    if (toggleConfirmPassword) {
      toggleConfirmPassword.addEventListener('click', function () {
        togglePasswordVisibility('confirmPassword', 'iconConfirmPassword');
      });
    }
  });
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
