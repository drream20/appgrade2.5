<?php
// Check if currentMisUser function is defined, if not, require dependencies that provide it
if (!function_exists('currentMisUser')) {
    require __DIR__ . '/../config/database.php';
    require __DIR__ . '/auth.php';
}
// Call the currentMisUser() function to get the MIS user info
$misUser = currentMisUser();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$showWelcomeModal = !empty($misUser['id']) && (int)($misUser['welcome_modal_ack'] ?? 0) === 0;
$welcomeModalImageUrl = rtrim(dirname($baseUrl), '/') . '/student/assets/welcome/logo.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'MIS') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --sidebar-width: 260px; --header-h: 56px; }
    body { min-height: 100vh; background: #f0f2f5; }
    .app-header { 
      height: var(--header-h); 
      background: linear-gradient(135deg, #0F3468 0%, #0F3468 100%); 
      color: #fff;
      position: sticky;
      top: 0;
      z-index: 1040; /* Above page content */
    }
    .sidebar { 
      width: var(--sidebar-width); 
      background: #0F3468; 
      position: sticky;
      top: var(--header-h); /* Stick just below the header */
      z-index: 1030;
      height: calc(100vh - var(--header-h));
      overflow-y: auto; /* Internal scroll for long nav */
    }
    .sidebar .nav-link { color: rgba(255,255,255,.85); padding: .6rem 1rem; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
    .main-content { flex: 1; padding: 1.5rem; }
    .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .table th { font-weight: 600; color: #495057; }
    .badge-active { background: #198754; }
    .badge-inactive { background: #6c757d; }
    .badge-locked { background: #dc3545; }
    .badge-unlocked { background: #0d6efd; }
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
  <div class="app-header d-flex align-items-center px-3">
    <span class="fw-bold"><i class="bi bi-gear-wide-connected me-2"></i>MIS</span>
    <div class="ms-auto d-flex align-items-center">
      <span class="me-3"><?= htmlspecialchars($misUser['full_name'] ?? '') ?></span>
      <a href="<?= $baseUrl ?>/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>
  <div class="d-flex">
    <nav class="sidebar py-2">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'academic_years' ? 'active' : '' ?>" href="<?= $baseUrl ?>/academic_years.php"><i class="bi bi-calendar-range me-2"></i>Academic Years</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'upload_students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/upload_students.php"><i class="bi bi-upload me-2"></i>Upload Enrollments</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'schedules' ? 'active' : '' ?>" href="<?= $baseUrl ?>/schedules.php"><i class="bi bi-calendar3 me-2"></i>Loadings</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/students.php"><i class="bi bi-people me-2"></i>Students</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'instructors' ? 'active' : '' ?>" href="<?= $baseUrl ?>/instructors.php"><i class="bi bi-person-badge me-2"></i>Instructors</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'subjects' ? 'active' : '' ?>" href="<?= $baseUrl ?>/subject.php"><i class="bi bi-book me-2"></i>Subjects</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'deans' ? 'active' : '' ?>" href="<?= $baseUrl ?>/deans.php"><i class="bi bi-person-vcard me-2"></i>Deans</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'users' ? 'active' : '' ?>" href="<?= $baseUrl ?>/users.php"><i class="bi bi-person-gear me-2"></i>User Accounts</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'logs' ? 'active' : '' ?>" href="<?= $baseUrl ?>/logs.php"><i class="bi bi-journal-text me-2"></i>System Logs</a></li>
        
      </ul>
    </nav>
    <main class="main-content w-100">
      <?= $content ?? '' ?>
    </main>
  </div>
  <?php if (!empty($showWelcomeModal)):
    $firstName = explode(' ', trim($misUser['full_name'] ?? 'Admin'))[0];
  ?>
  <div class="modal fade modal-welcome" id="welcomeModalMis" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content border-0 bg-transparent">
        <div class="welcome-modal-card">
          <div class="welcome-modal-img-wrap">
            <img src="<?= htmlspecialchars($welcomeModalImageUrl) ?>" alt="Welcome">
          </div>
          <div class="welcome-modal-body">
            <h2>Welcome, <?= htmlspecialchars($firstName) ?>!</h2>
            <p>Your MIS portal is ready. Manage academic years, enrollments, schedules, and system settings all in one place.</p>
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
    var el = document.getElementById('welcomeModalMis');
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
  <?= $footerScripts ?? '' ?>
</body>
</html>
