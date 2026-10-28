<?php
if (!function_exists('currentDean')) {
    require __DIR__ . '/../config/database.php';
    require __DIR__ . '/auth.php';
}
$dean = currentDean();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

// Program context (dropdown for Executive Dean; badge for program deans) — rendered in main content
$programs = deanPrograms();
$selectedProgramKey = deanSelectedProgramKey(); // short code (e.g., BSCS) or null for "All"
$isExecutiveDean = deanIsExecutive();

// Profile image: pull from deans.image_path (fallback to default if empty)
$profileImgDir = __DIR__ . '/../uploads/dean_pics/';
$profileImgUrl = $baseUrl . '/uploads/dean_pics';
$hasCustomImage = !empty($dean['image_path']) && file_exists($profileImgDir . $dean['image_path']);
$deanProfileImg = $hasCustomImage
    ? ($profileImgUrl . '/' . rawurlencode($dean['image_path']) . '?t=' . @filemtime($profileImgDir . $dean['image_path']))
    : ($baseUrl . '/assets/img/default-user.png');

$showWelcomeModal = !empty($dean['id']) && (int)($dean['welcome_modal_ack'] ?? 0) === 0;
$welcomeModalImageUrl = rtrim(dirname($baseUrl), '/') . '/student/assets/welcome/logo.png';

// Portal header title depending on role
$portalTitle = $isExecutiveDean ? 'Executive Dean Portal' : 'Dean Portal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Dean') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --sidebar-width: 260px; --header-h: 56px; }
    body { min-height: 100vh; background: #f0f2f5; }
    .app-header {
      position: sticky;
      top: 0;
      z-index: 1040;
      min-height: var(--header-h);
      background: linear-gradient(135deg, #0F3468 0%, #0F3468 100%);
      color: #fff;
    }
    .sidebar {
      position: sticky;
      top: var(--header-h);
      align-self: flex-start;
      z-index: 1030;
      width: var(--sidebar-width);
      flex-shrink: 0;
      background: #0F3468;
      min-height: calc(100vh - var(--header-h));
      max-height: calc(100vh - var(--header-h));
      overflow-y: auto;
    }
    .sidebar .nav-link { color: rgba(255,255,255,.85); padding: .6rem 1rem; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
    .main-content { flex: 1; padding: 1.5rem; }
    .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .table th { font-weight: 600; color: #495057; }
    .sidebar-mobile { width: 82vw; max-width: 320px; background: #0F3468; }
    .sidebar-mobile .nav-link { color: rgba(255,255,255,.9); padding: .7rem 1rem; }
    .sidebar-mobile .nav-link:hover, .sidebar-mobile .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }

    .header-profile-img {
      width: 38px;
      height: 38px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid #fff;
      background: #e0e0e0;
      display: block;
      cursor: pointer;
    }

    /* Fullscreen overlay for the profile image */
    .image-fullscreen-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      width: 100vw;
      height: 100vh;
      z-index: 2050;
      display: none;
      background: rgba(20, 20, 30, 0.85);
      align-items: center;
      justify-content: center;
      animation: fadein 0.2s;
    }
    .image-fullscreen-overlay.show {
      display: flex;
    }
    .image-fullscreen-overlay img {
      max-width: 96vw;
      max-height: 92vh;
      border-radius: 15px;
      box-shadow: 0 8px 36px rgba(0,0,0,0.85);
      border: 4px solid #fff;
      background: #f7f7f7;
      transition: box-shadow .2s;
    }
    .image-fullscreen-overlay .close-btn {
      position: absolute;
      top: 26px;
      right: 32px;
      font-size: 2.3rem;
      color: #fff;
      background: none;
      border: none;
      z-index: 2;
      opacity: 0.87;
      cursor: pointer;
      transition: color 0.15s;
    }
    .image-fullscreen-overlay .close-btn:active {
      color: #eee;
    }

    @keyframes fadein {
      0% { opacity: 0; }
      100% { opacity: 1; }
    }

    .dean-program-bar {
      background: #fff;
      border-radius: .5rem;
    }
    .dean-program-bar .form-select {
      min-width: 140px;
      max-width: 280px;
    }
    @media (max-width: 991.98px) {
      .main-content { padding: 1rem; }
      .header-user-name { display: none !important; }
      .header-profile-img { display: block !important; }
    }
    @media (min-width: 992px) {
      .header-profile-img { display: none !important; }
    }
    .modal-welcome { z-index: 12050 !important; }
    .modal-welcome ~ .modal-backdrop { z-index: 12040 !important; }
    .welcome-modal-card {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      border-radius: 1rem;
      overflow: hidden;
      background: #fff;
      box-shadow: 0 24px 48px rgba(0,0,0,.12), 0 8px 16px rgba(0,0,0,.08);
    }
    .welcome-modal-img-wrap {
      background: linear-gradient(135deg,rgb(255, 255, 255) 0%,rgb(255, 255, 255) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem 2rem 1rem;
    }
    .welcome-modal-img-wrap img {
      max-height: min(42vh, 320px);
      max-width: 100%;
      object-fit: contain;
      border-radius: .75rem;
    }
    .welcome-modal-body {
      padding: 1.75rem 2rem 2rem;
      text-align: center;
      background-color: #0F3468;
    }
    .welcome-modal-body h2 {
      font-weight: 700;
      font-size: 1.55rem;
      color: #FFFDF1;
      margin: 0 0 .35rem;
      letter-spacing: -.01em;
    }
    .welcome-modal-body p {
      font-weight: 400;
      font-size: .92rem;
      color: #FFFDF1;
      margin: 0 0 1.5rem;
      line-height: 1.5;
    }
    .welcome-modal-close-btn {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      font-weight: 600;
      font-size: .88rem;
      letter-spacing: .01em;
      padding: .55rem 2rem;
      border-radius: .5rem;
      border: none;
      color: #fff;
      background: rgb(30, 75, 139);
      transition: background .15s, box-shadow .15s;
      cursor: pointer;
    }
    .welcome-modal-close-btn:hover {
      background: #163d7a;
      box-shadow: 0 4px 12px rgba(15,52,104,.25);
    }
    @media (max-width: 575.98px) {
      .welcome-modal-img-wrap { padding: 1.25rem 1.25rem .75rem; }
      .welcome-modal-body { padding: 1.25rem 1.25rem 1.5rem; }
      .welcome-modal-body h2 { font-size: 1.3rem; }
    }
  </style>
</head>
<body>
  <div class="app-header d-flex align-items-center px-2 px-md-3 py-2">
    <button class="btn btn-outline-light btn-sm d-lg-none me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#deanMobileSidebar" aria-controls="deanMobileSidebar">
      <i class="bi bi-list"></i>
    </button>
    <span class="fw-bold"><i class="bi bi-mortarboard me-2"></i>
      <span class="d-none d-sm-inline">
        <?= htmlspecialchars($portalTitle) ?>
      </span>
    </span>
    <div class="ms-auto d-flex align-items-center flex-wrap justify-content-end gap-2">
      <span class="header-user-name me-1 me-md-2 small"><?= htmlspecialchars($dean['full_name'] ?? '') ?></span>
      <!-- Profile image for mobile only -->
      <img id="deanProfileImgMobile" src="<?= htmlspecialchars($deanProfileImg) ?>" alt="Profile Image" class="header-profile-img ms-1" />
      <a href="<?= $baseUrl ?>/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>

  <!-- Fullscreen overlay for mobile profile image -->
  <div class="image-fullscreen-overlay" id="deanProfileImgOverlay" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true" style="display: none;">
    <button class="close-btn" id="deanProfileImgOverlayClose" type="button" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    <img src="<?= htmlspecialchars($deanProfileImg) ?>" alt="Profile Image Fullscreen" draggable="false" />
  </div>

  <div class="offcanvas offcanvas-start sidebar-mobile" tabindex="-1" id="deanMobileSidebar" aria-labelledby="deanMobileSidebarLabel">
    <div class="offcanvas-header border-bottom border-light border-opacity-25">
      <h5 class="offcanvas-title text-white" id="deanMobileSidebarLabel"><i class="bi bi-mortarboard me-2"></i>Dean Menu</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0">
      <ul class="nav flex-column py-2">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/students.php"><i class="bi bi-people me-2"></i>Students</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'instructors' ? 'active' : '' ?>" href="<?= $baseUrl ?>/instructors.php"><i class="bi bi-person-badge me-2"></i>Instructors</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'attendance' ? 'active' : '' ?>" href="<?= $baseUrl ?>/attendance.php"><i class="bi bi-clipboard-check me-2"></i>Attendance</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'sections' ? 'active' : '' ?>" href="<?= $baseUrl ?>/sections.php"><i class="bi bi-journal-text me-2"></i>Attendance</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'class_records' ? 'active' : '' ?>" href="<?= $baseUrl ?>/class_records.php"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Class Records</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'reports' ? 'active' : '' ?>" href="<?= $baseUrl ?>/reports.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/profile.php"><i class="bi bi-person-gear me-2"></i>Profile</a></li>
      </ul>
    </div>
  </div>

  <div class="d-flex">
    <nav class="sidebar py-2 d-none d-lg-block">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/students.php"><i class="bi bi-people me-2"></i>Students</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'instructors' ? 'active' : '' ?>" href="<?= $baseUrl ?>/instructors.php"><i class="bi bi-person-badge me-2"></i>Instructors</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'attendance' ? 'active' : '' ?>" href="<?= $baseUrl ?>/attendance.php"><i class="bi bi-clipboard-check me-2"></i>Attedance</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'sections' ? 'active' : '' ?>" href="<?= $baseUrl ?>/sections.php"><i class="bi bi-journal-text me-2"></i>Sections</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'class_records' ? 'active' : '' ?>" href="<?= $baseUrl ?>/class_records.php"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Class Records</a></li>
        <!-- <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'reports' ? 'active' : '' ?>" href="<?= $baseUrl ?>/reports.php"><i class="bi bi-file-earmark-bar-graph me-2"></i>Reports</a></li> -->
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/profile.php"><i class="bi bi-person-gear me-2"></i>Profile</a></li>
      </ul>
    </nav>
    <main class="main-content w-100">
      <?php if ($isExecutiveDean || $selectedProgramKey): ?>
      <div class="dean-program-bar card border-0 shadow-sm mb-3">
        <div class="card-body py-2 py-md-3 d-flex flex-wrap align-items-center gap-2 gap-md-3">
          <?php if ($isExecutiveDean): ?>
            <form method="get" class="d-flex flex-wrap align-items-center gap-2 mb-0 flex-grow-1">
              <?php
              foreach ($_GET as $k => $v) {
                  if ($k === 'program' || is_array($v)) {
                      continue;
                  }
                  echo '<input type="hidden" name="' . htmlspecialchars((string) $k) . '" value="' . htmlspecialchars((string) $v) . '">';
              }
              ?>
              <label class="small mb-0 fw-semibold text-secondary">Program</label>
              <select name="program" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">Select Program</option>
                <?php foreach ($programs as $key => $name): ?>
                  <option value="<?= htmlspecialchars($key) ?>" <?= $selectedProgramKey === $key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($key) ?> — <?= htmlspecialchars($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php elseif ($selectedProgramKey): ?>
            <span class="small text-secondary fw-semibold me-1">Program</span>
            <span class="badge bg-primary fs-6"><?= htmlspecialchars($selectedProgramKey) ?></span>
            <?php if ($programs && isset($programs[$selectedProgramKey])): ?>
              <span class="text-muted small"><?= htmlspecialchars($programs[$selectedProgramKey]) ?></span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?= $content ?? '' ?>
    </main>
  </div>
  <?php if (!empty($showWelcomeModal)):
    $firstName = explode(' ', trim($dean['full_name'] ?? 'Dean'))[0];
  ?>
  <div class="modal fade modal-welcome" id="welcomeModalDean" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content border-0 bg-transparent">
        <div class="welcome-modal-card">
          <div class="welcome-modal-img-wrap">
            <img src="<?= htmlspecialchars($welcomeModalImageUrl) ?>" alt="Welcome">
          </div>
          <div class="welcome-modal-body">
            <h2>Welcome, <?= htmlspecialchars($firstName) ?>!</h2>
            <p>Your Dean portal is ready. Manage students, instructors, and academic records all in one place.</p>
            <button type="button" class="welcome-modal-close-btn" data-bs-dismiss="modal">Get Started</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <?php if (!empty($showWelcomeModal)): ?>
  <script>
  (function () {
    var el = document.getElementById('welcomeModalDean');
    if (!el || !window.bootstrap) return;
    var modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
    var ackSent = false;
    modal.show();
    el.addEventListener('hidden.bs.modal', function () {
      if (ackSent) return;
      ackSent = true;
      fetch(<?= json_encode($baseUrl . '/dismiss_welcome_modal.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?>, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).catch(function () {});
    });
  })();
  </script>
  <?php endif; ?>
  <script>
    // Only enable this JS in mobile view; harmless in desktop, but only the .header-profile-img is displayed in mobile via CSS
    (function() {
      var profileImg = document.getElementById('deanProfileImgMobile');
      var overlay = document.getElementById('deanProfileImgOverlay');
      var overlayClose = document.getElementById('deanProfileImgOverlayClose');
      if (profileImg && overlay && overlayClose) {
        var showOverlay = function(e) {
          // Only for mobile width. 
          if (window.innerWidth < 992) {
            overlay.classList.add('show');
            overlay.style.display = "flex";
            overlay.setAttribute("aria-hidden", "false");
            // Optional: Prevent scroll on body
            document.body.style.overflow = 'hidden';
          }
        };
        var hideOverlay = function(e) {
          overlay.classList.remove('show');
          overlay.style.display = "none";
          overlay.setAttribute("aria-hidden", "true");
          document.body.style.overflow = '';
        };

        profileImg.addEventListener('click', showOverlay);
        overlayClose.addEventListener('click', hideOverlay);

        // Clicking outside image (on overlay) closes it
        overlay.addEventListener('click', function(e) {
          if (
            e.target === overlay
            || (e.target.classList && e.target.classList.contains('image-fullscreen-overlay'))
          ) {
            hideOverlay();
          }
        });

        // ESC key closes it
        window.addEventListener('keydown', function(e) {
          if (e.key === "Escape" && overlay.classList.contains('show')) {
            hideOverlay();
          }
        });
      }
    })();
  </script>
  <?= $footerScripts ?? '' ?>
</body>
</html>
