<?php
if (!function_exists('currentRegistrar')) {
    require __DIR__ . '/../config/database.php';
    require __DIR__ . '/auth.php';
}
$registrar = currentRegistrar();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$showWelcomeModal = !empty($registrar['id']) && (int)($registrar['welcome_modal_ack'] ?? 0) === 0;
$welcomeModalImageUrl = rtrim(dirname($baseUrl), '/') . '/student/assets/welcome/logo.png';
$mobileAvatarUrl = !empty($registrar['avatar_path'])
    ? $registrar['avatar_path']
    : rtrim(dirname($baseUrl), '/') . '/instructor/assets/img/default-user.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Registrar') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root { --sidebar-width: 240px; --header-h: 56px; }
    body { min-height: 100vh; background: #f0f2f5; }
    .app-header { 
      height: var(--header-h); 
      background: linear-gradient(135deg, #0F3468 0%, #0F3468 100%);
      color: #fff;
      position: sticky;
      top: 0;
      z-index: 1040; /* Stay above page content */
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
    .main-content { flex: 1; padding: 1.5rem; min-width: 0; }
    .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .table th { font-weight: 600; color: #495057; }
    .mobile-menu-btn { display: none; }
    .mobile-menu-btn i { font-size: 1.35rem; line-height: 1; }
    .mobile-only { display: none !important; }
    .mobile-avatar {
      width: 32px;
      height: 32px;
      border-radius: 5px;
      object-fit: cover;
      border: 2px solid rgba(255,255,255,.75);
      cursor: pointer;
    }
    .avatar-zoom-modal .modal-content {
      background: transparent;
      border: 0;
      box-shadow: none;
    }
    .avatar-zoom-modal img {
      width: min(90vw, 420px);
      max-height: 80vh;
      object-fit: contain;
      border-radius: 8px;
      border: 2px solid rgba(255,255,255,.7);
      box-shadow: 0 10px 30px rgba(0,0,0,.4);
    }
    .offcanvas-registrar {
      background: #0F3468;
      color: #fff;
    }
    .offcanvas-registrar .nav-link {
      color: rgba(255,255,255,.85);
      padding: .65rem 1rem;
    }
    .offcanvas-registrar .nav-link:hover,
    .offcanvas-registrar .nav-link.active {
      color: #fff;
      background: rgba(255,255,255,.1);
    }
    .offcanvas-registrar .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
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
    @media (max-width: 991.98px) {
      :root { --header-h: 60px; }
      .mobile-menu-btn { display: inline-flex; }
      .mobile-menu-btn { padding: .4rem .45rem !important; }
      .mobile-only { display: inline-flex !important; }
      .desktop-only { display: none !important; }
      .sidebar { display: none; }
      .offcanvas-registrar {
        width: 50vw !important;
        max-width: 50vw !important;
        top: var(--header-h) !important;
        height: calc(100vh - var(--header-h)) !important;
      }
      .offcanvas-registrar .offcanvas-body {
        padding-top: 1rem !important;
      }
      .main-content { padding: 1rem .9rem; }
      .app-header { padding-left: .75rem !important; padding-right: .75rem !important; }
      .app-header .btn { padding: .25rem .5rem; font-size: .8rem; }
    }
    @media (max-width: 575.98px) {
      .main-content { padding: 1rem .75rem; }
      .welcome-modal-img-wrap { padding: 1.25rem 1.25rem .75rem; }
      .welcome-modal-body { padding: 1.25rem 1.25rem 1.5rem; }
      .welcome-modal-body h2 { font-size: 1.3rem; }
    }
  </style>
</head>
<body>
  <div class="app-header d-flex align-items-center px-3">
    <button class="btn btn-outline-light btn-sm me-2 mobile-menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#registrarMobileNav" aria-controls="registrarMobileNav" aria-label="Open menu">
      <i class="bi bi-list"></i>
    </button>
    <span class="fw-bold"><i class="bi bi-building me-2"></i>Registrar</span>
    <div class="ms-auto d-flex align-items-center">
      <span class="me-3 desktop-only"><?= htmlspecialchars($registrar['full_name'] ?? '') ?></span>
      <img src="<?= htmlspecialchars($mobileAvatarUrl) ?>" alt="Profile" class="mobile-avatar mobile-only me-2"
           data-bs-toggle="modal" data-bs-target="#mobileAvatarZoomModal">
      <a href="<?= $baseUrl ?>/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>
  <div class="modal fade avatar-zoom-modal" id="mobileAvatarZoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body p-0 text-center">
          <img src="<?= htmlspecialchars($mobileAvatarUrl) ?>" alt="Profile zoomed">
        </div>
      </div>
    </div>
  </div>
  <div class="offcanvas offcanvas-start offcanvas-registrar" tabindex="-1" id="registrarMobileNav" aria-label="Registrar navigation">
    <div class="offcanvas-body p-0">
      <ul class="nav flex-column py-2">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'classlist' ? 'active' : '' ?>" href="<?= $baseUrl ?>/class_list.php"><i class="bi bi-people me-2"></i>Class List</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'student_management' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student_management.php"><i class="bi bi-table me-2"></i>Student Management</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/students.php"><i class="bi bi-person-badge me-2"></i>Students</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
      </ul>
    </div>
  </div>
  <div class="d-flex">
    <nav class="sidebar py-2">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'classlist' ? 'active' : '' ?>" href="<?= $baseUrl ?>/class_list.php"><i class="bi bi-people me-2"></i>Class List</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'student_management' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student_management.php"><i class="bi bi-table me-2"></i>Student Management</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'students' ? 'active' : '' ?>" href="<?= $baseUrl ?>/students.php"><i class="bi bi-person-badge me-2"></i>Students</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/profile.php"><i class="bi bi-person-circle me-2"></i>My Profile</a></li>
      </ul>
    </nav>
    <main class="main-content w-100">
      <?= $content ?? '' ?>
    </main>
  </div>
  <?php if (!empty($showWelcomeModal)):
    $firstName = explode(' ', trim($registrar['full_name'] ?? 'Registrar'))[0];
  ?>
  <div class="modal fade modal-welcome" id="welcomeModalRegistrar" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content border-0 bg-transparent">
        <div class="welcome-modal-card">
          <div class="welcome-modal-img-wrap">
            <img src="<?= htmlspecialchars($welcomeModalImageUrl) ?>" alt="Welcome">
          </div>
          <div class="welcome-modal-body">
            <h2>Welcome, <?= htmlspecialchars($firstName) ?>!</h2>
            <p>Your Registrar portal is ready. Manage class lists, student records, and enrollment data all in one place.</p>
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
    var el = document.getElementById('welcomeModalRegistrar');
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
