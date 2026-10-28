<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/profile_requirements.php';
studentRequireLogin();

$db = getDb();
$studentId = currentStudentId();
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

// Fetch current student info including optional profile fields
$stmt = $db->prepare("
    SELECT id, student_id, full_name, email, image_path, phone, address, password_hash,
           elementary, secondary, elem_year, sec_year
    FROM students
    WHERE id = ?
");
$stmt->execute([$studentId]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $imageOnly = isset($_POST['profile_image_only']) && (string) $_POST['profile_image_only'] === '1';

    if ($imageOnly) {
        $imagePath = $student['image_path'] ?? null;
        $removeImage = isset($_POST['delete_profile_image']) && $_POST['delete_profile_image'] === '1';
        if ($removeImage) {
            $imagePath = null;
        } elseif (!empty($_FILES['profile_image']['name']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['profile_image']['tmp_name'];
                $size = (int) $_FILES['profile_image']['size'];
                if ($size > 3 * 1024 * 1024) {
                    $errors[] = 'Profile image must not exceed 3MB.';
                } else {
                    $finfo = @getimagesize($tmpName);
                    if ($finfo === false) {
                        $errors[] = 'Uploaded file must be an image.';
                    } else {
                        $ext = image_type_to_extension($finfo[2], false);
                        if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                            $errors[] = 'Allowed image types: JPG, PNG, GIF, WEBP.';
                        } else {
                            $uploadDir = __DIR__ . '/uploads';
                            if (!is_dir($uploadDir)) {
                                @mkdir($uploadDir, 0775, true);
                            }
                            $fileName = 'student_' . $studentId . '_' . time() . '.' . $ext;
                            $destPath = $uploadDir . '/' . $fileName;
                            if (move_uploaded_file($tmpName, $destPath)) {
                                $imagePath = 'student/uploads/' . $fileName;
                            } else {
                                $errors[] = 'Failed to save uploaded image.';
                            }
                        }
                    }
                }
            } else {
                $errors[] = 'Error uploading image.';
            }
        }
        if (empty($errors)) {
            $stmt = $db->prepare('UPDATE students SET image_path = :image_path WHERE id = :id');
            $stmt->execute(['image_path' => $imagePath, 'id' => $studentId]);
            $stmt = $db->prepare("
                SELECT id, student_id, full_name, email, image_path, phone, address, password_hash,
                       elementary, secondary, elem_year, sec_year
                FROM students WHERE id = ?
            ");
            $stmt->execute([$studentId]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: $student;
            $success = 'Profile photo updated.';
        }
    } else {

    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $elementary = trim($_POST['elementary'] ?? '');
    $secondary = trim($_POST['secondary'] ?? '');
    $elemYear = trim($_POST['elem_year'] ?? '');
    $secYear = trim($_POST['sec_year'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $removeImage = isset($_POST['delete_profile_image']) && $_POST['delete_profile_image'] === '1';

    $posted = [
        'full_name' => $fullName,
        'phone' => $phone,
        'address' => $address,
        'elementary' => $elementary,
        'secondary' => $secondary,
        'elem_year' => $elemYear,
        'sec_year' => $secYear,
    ];

    foreach (student_profile_required_fields() as $key => $label) {
        if (trim((string) ($posted[$key] ?? '')) === '') {
            $errors[] = $label . ' is required.';
        }
    }

    $stillDefault = student_uses_default_password($student['password_hash'] ?? null);
    if ($stillDefault) {
        if (strlen($password) < 6) {
            $errors[] = 'You must set a new password (at least 6 characters). Replace the default password.';
        }
        if ($password !== $passwordConfirm) {
            $errors[] = 'Password confirmation does not match.';
        }
    } elseif ($password !== '' && $password !== $passwordConfirm) {
        $errors[] = 'Password confirmation does not match.';
    }

    // Handle image upload / removal
    $imagePath = $student['image_path'] ?? null;
    if ($removeImage) {
        $imagePath = null;
    } elseif (!empty($_FILES['profile_image']['name']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['profile_image']['tmp_name'];
            $size = (int) $_FILES['profile_image']['size'];

            if ($size > 3 * 1024 * 1024) {
                $errors[] = 'Profile image must not exceed 3MB.';
            } else {
                $finfo = @getimagesize($tmpName);
                if ($finfo === false) {
                    $errors[] = 'Uploaded file must be an image.';
                } else {
                    $ext = image_type_to_extension($finfo[2], false);
                    if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                        $errors[] = 'Allowed image types: JPG, PNG, GIF, WEBP.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads';
                        if (!is_dir($uploadDir)) {
                            @mkdir($uploadDir, 0775, true);
                        }
                        $fileName = 'student_' . $studentId . '_' . time() . '.' . $ext;
                        $destPath = $uploadDir . '/' . $fileName;
                        if (move_uploaded_file($tmpName, $destPath)) {
                            $imagePath = 'student/uploads/' . $fileName;
                        } else {
                            $errors[] = 'Failed to save uploaded image.';
                        }
                    }
                }
            }
        } else {
            $errors[] = 'Error uploading image.';
        }
    }

    if (empty($errors)) {
        $params = [
            'full_name' => $fullName,
            'phone' => $phone !== '' ? $phone : null,
            'address' => $address !== '' ? $address : null,
            'elementary' => $elementary !== '' ? $elementary : null,
            'secondary' => $secondary !== '' ? $secondary : null,
            'elem_year' => $elemYear !== '' ? $elemYear : null,
            'sec_year' => $secYear !== '' ? $secYear : null,
            'image_path' => $imagePath,
            'id' => $studentId,
        ];

        $passwordSql = '';
        if ($password !== '') {
            $passwordSql = ', password_hash = :password_hash';
            $params['password_hash'] = hash_password_for_storage($password);
        }

        $sql = "
            UPDATE students
            SET full_name = :full_name,
                phone = :phone,
                address = :address,
                elementary = :elementary,
                secondary = :secondary,
                elem_year = :elem_year,
                sec_year = :sec_year,
                image_path = :image_path
                $passwordSql
            WHERE id = :id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        // Refresh data for display
        $stmt = $db->prepare("
            SELECT id, student_id, full_name, email, image_path, phone, address, password_hash,
                   elementary, secondary, elem_year, sec_year
            FROM students
            WHERE id = ?
        ");
        $stmt->execute([$studentId]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC) ?: $student;

        $success = 'Profile updated successfully.';
        if (student_portal_access_allowed($student)) {
            header('Location: ' . $baseUrl . '/student/dashboard.php?profile_complete=1');
            exit;
        }
    }
    }
}

$pageTitle = 'My Profile';
$currentPage = 'profile';
$missingProfileLabels = student_profile_missing_labels($student);
$needsPasswordChange = student_uses_default_password($student['password_hash'] ?? null);
$profileGateOpen = !empty($missingProfileLabels) || $needsPasswordChange;
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Profile</h4>
</div>

<?php if (isset($_GET['required']) || $profileGateOpen): ?>
  <div class="alert alert-warning border-warning">
    <strong class="d-block mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Complete your profile to use the student portal</strong>
    <p class="mb-2 small">You must fill in all required fields and set your own password before you can open Dashboard, Grades, Records, Schedule, or Ranking.</p>
    <?php if (!empty($missingProfileLabels)): ?>
      <p class="mb-1 small"><strong>Missing:</strong> <?= htmlspecialchars(implode(', ', $missingProfileLabels)) ?>.</p>
    <?php endif; ?>
    <?php if ($needsPasswordChange): ?>
      <p class="mb-0 small"><strong>Password:</strong> enter a <strong>new password</strong> below (at least 6 characters). You cannot keep the default password.</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php elseif ($success): ?>
  <div class="alert alert-success mb-3">
    <?= htmlspecialchars($success) ?>
  </div>
<?php endif; ?>

<div class="row g-4 align-items-stretch justify-content-center">
  <!-- Profile image and edit as before -->
  <div class="col-12 col-md-4 d-flex align-items-stretch justify-content-center mb-3 mb-md-0">
    <div class="w-100 d-flex flex-column h-100">
      <div class="card flex-grow-1 shadow-sm h-100">
        <div class="card-body d-flex flex-column align-items-center text-center p-3 pb-2 h-100">
          <?php
            $imageUrl = $student['image_path'] ? ($baseUrl . '/' . ltrim($student['image_path'], '/')) : null;
          ?>
          <div class="position-relative mb-2" style="width: 140px; min-height: 140px; height: auto;">
            <div class="rounded-circle overflow-hidden border" style="width: 140px; height: 140px; background:#f8f9fa;">
              <?php if ($imageUrl): ?>
                <img src="<?= htmlspecialchars($imageUrl) ?>"
                     alt="Profile photo"
                     style="width:100%;height:100%;object-fit:cover;">
              <?php else: ?>
                <div class="w-100 h-100 d-flex flex-column align-items-center justify-content-center text-muted">
                  <i class="bi bi-person-circle" style="font-size:3.5rem;"></i>
                  <small>No photo</small>
                </div>
              <?php endif; ?>
            </div>
            <!-- Add/Edit and Delete Icon Buttons (Floating at the bottom center of image) -->
            <div class="position-absolute bottom-0 start-50 translate-middle-x d-flex gap-2" style="transform: translate(-50%, 50%)">
              <!-- Add/Edit photo -->
              <label class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                     style="width:38px;height:38px;cursor:pointer;" title="Add or change photo">
                <i class="bi bi-plus-circle-fill text-primary fs-5"></i>
                <input type="file" name="profile_image" accept="image/*" class="d-none" form="profileForm">
              </label>
              <!-- Remove photo -->
              <?php if ($imageUrl): ?>
                <button type="submit"
                        name="delete_profile_image"
                        value="1"
                        form="profileForm"
                        class="btn btn-light rounded-circle shadow-sm border d-flex align-items-center justify-content-center p-2 m-0"
                        style="width:38px;height:38px;"
                        title="Remove photo"
                        onclick="document.getElementById('profileImageOnly').value='1'; return confirm('Remove profile photo?');">
                  <i class="bi bi-trash-fill text-danger fs-5"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>
          <p class="mb-1 fw-semibold mt-3"><?= htmlspecialchars($student['full_name'] ?? '') ?></p>
          <p class="mb-0 text-muted small">Student ID: <?= htmlspecialchars($student['student_id'] ?? '') ?></p>
          <?php if (!empty($student['email'])): ?>
            <p class="mb-0 text-muted small"><?= htmlspecialchars($student['email']) ?></p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Profile fields: edit form -->
  <div class="col-12 col-md-8">
    <div class="card shadow-sm h-100">
      <div class="card-body p-3">
        <form method="post" enctype="multipart/form-data" id="profileForm" novalidate>
          <input type="hidden" name="profile_image_only" value="0" id="profileImageOnly">
          <div class="row g-3">
            <!-- Full name -->
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Full name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" required
                     value="<?= htmlspecialchars($student['full_name'] ?? '') ?>" autocomplete="name">
            </div>
            <!-- Phone number -->
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Phone number <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" required
                     value="<?= htmlspecialchars($student['phone'] ?? '') ?>">
            </div>
            <!-- Address -->
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Address <span class="text-danger">*</span></label>
              <input type="text" name="address" class="form-control" required
                     value="<?= htmlspecialchars($student['address'] ?? '') ?>">
            </div>
            <div class="col-12 mt-1">
              <h6 class="text-muted text-uppercase small mb-2 mb-md-3">Educational background</h6>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Elementary school<span class="text-danger">*</span></label>
              <input type="text" name="elementary" class="form-control" required
                     value="<?= htmlspecialchars($student['elementary'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Elementary year <span class="text-danger">*</span></label>
              <input type="text" name="elem_year" class="form-control" required
                     value="<?= htmlspecialchars($student['elem_year'] ?? '') ?>">
            </div>
            
            <div class="col-12"><hr class="my-2"></div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Secondary school <span class="text-danger">*</span></label>
              <input type="text" name="secondary" class="form-control" required
                     value="<?= htmlspecialchars($student['secondary'] ?? '') ?>">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label mb-1">Secondary year <span class="text-danger">*</span></label>
              <input type="text" name="sec_year" class="form-control" required
                     value="<?= htmlspecialchars($student['sec_year'] ?? '') ?>">
            </div>
            <!-- Spacer for row wrap -->
            <div class="col-12"><hr class="my-2"></div>
            <!-- Change password -->
            <!-- Password fields: stacked with toggle, full width -->
            <div class="col-12 col-md-6 mb-2 mb-md-0">
                <label class="form-label mb-1">New password <?php if ($needsPasswordChange): ?><span class="text-danger">*</span><?php endif; ?></label>
                <div class="input-group mb-1">
                  <input type="password" name="password" class="form-control password-toggle-input" autocomplete="new-password" id="profile-password" <?= $needsPasswordChange ? 'required minlength="6"' : '' ?>>
                  <button class="btn btn-outline-secondary password-toggle-btn" type="button" tabindex="-1" aria-label="Show/hide password" data-toggle-target="profile-password">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
                <small class="text-muted"><?= $needsPasswordChange ? '<span class="text-danger">Required</span> until you replace the default password.' : 'Leave blank if not changing.' ?></small>
            </div>
            <div class="col-12 col-md-6">
                <label class="form-label mb-1">Confirm new password <?php if ($needsPasswordChange): ?><span class="text-danger">*</span><?php endif; ?></label>
                <div class="input-group mb-1">
                  <input type="password" name="password_confirm" class="form-control password-toggle-input" autocomplete="new-password" id="profile-password-confirm" <?= $needsPasswordChange ? 'required minlength="6"' : '' ?>>
                  <button class="btn btn-outline-secondary password-toggle-btn" type="button" tabindex="-1" aria-label="Show/hide password" data-toggle-target="profile-password-confirm">
                    <i class="bi bi-eye"></i>
                  </button>
                </div>
                <small class="text-muted">&nbsp;</small>
            </div>
            <div class="col-12 d-flex align-items-end justify-content-end">
              <button type="submit" class="btn btn-primary px-4 w-100 w-md-auto" id="btnSaveProfile" onclick="document.getElementById('profileImageOnly').value='0';">
                <i class="bi bi-save me-1"></i> Save changes
              </button>
            </div>
          </div>
        </form>
        <div class="mt-2 text-muted small d-none d-md-block">
          <em>Leave password fields blank if you do not want to change your password.</em>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Responsive adjustment for fitting content -->
<style>
  @media (max-width: 991.98px) {
    .card-body.p-3, .card-body.pb-2 { padding: 1rem !important; }
    .card { margin-bottom: 1rem; }
    .col-md-4, .col-md-6, .col-md-8 { margin-bottom: 0.75rem; }
  }
  /* Make profile image card auto height always filled */
  .col-md-4 > .w-100, .col-md-4 .card { height: 100%; }
  /* Adjust password toggle input widths */
  .password-toggle-input {
    min-width: 0;
    max-width: 100%;
  }
</style>
<script>
  // Automatically submit the form when a new profile image is selected
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('profileForm');
    if (!form) return;

    // Any profile_image file input associated with this form will trigger autosave
    var fileInputs = document.querySelectorAll('input[type="file"][name="profile_image"][form="profileForm"]');
    var imgOnly = document.getElementById('profileImageOnly');
    fileInputs.forEach(function (input) {
      input.addEventListener('change', function () {
        if (input.files && input.files.length > 0) {
          if (imgOnly) imgOnly.value = '1';
          form.submit();
        }
      });
    });

    // Password show/hide toggle
    var passwordToggleBtns = document.querySelectorAll('.password-toggle-btn');
    passwordToggleBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var inputId = btn.getAttribute('data-toggle-target');
        var input = document.getElementById(inputId);
        if (input) {
          if (input.type === 'password') {
            input.type = 'text';
            btn.querySelector('i').classList.remove('bi-eye');
            btn.querySelector('i').classList.add('bi-eye-slash');
          } else {
            input.type = 'password';
            btn.querySelector('i').classList.remove('bi-eye-slash');
            btn.querySelector('i').classList.add('bi-eye');
          }
        }
      });
    });
  });
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
