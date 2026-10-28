<?php
if (!function_exists('currentInstructor')) {
    require __DIR__ . '/../config/database.php';
    require __DIR__ . '/auth.php';
}
$instructor = currentInstructor();
$baseUrl = $baseUrl ?? '';
// Compute avatar (same as in profile.php logic for path fallback)
$avatarPath = !empty($instructor['avatar_path']) ? $instructor['avatar_path'] : null;
if ($avatarPath) {
    $avatarUrl = htmlspecialchars($avatarPath);
} else {
    $avatarUrl = $baseUrl . '/assets/img/default-user.png';
}
$showWelcomeModal = !empty($instructor['id']) && (int)($instructor['welcome_modal_ack'] ?? 0) === 0;
/** Replace with your GIF/PNG: same folder, e.g. instructor-welcome.gif */
$welcomeModalImageUrl = rtrim(dirname($baseUrl), '/') . '/student/assets/welcome/logo.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Instructor') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --sidebar-width: 240px; --header-h: 56px; }
    html { height: 100%; }
    body {
      min-height: 100%;
      height: 100%;
      max-height: 100vh;
      background: #f0f2f5;
      padding-top: var(--header-h);
      overflow-x: hidden;
      overflow-y: hidden;
      box-sizing: border-box;
    }
    .app-header {
      position: fixed;
      top: 0; left: 0; right: 0;
      height: var(--header-h);
      background: linear-gradient(135deg, #0F3468, #0F3468 100%);
      color: #fff;
      z-index: 1100;
    }
    .main-content {
      flex: 1;
      min-width: 0;
      min-height: 0;
      padding: 1.5rem;
      margin-left: var(--sidebar-width);
      height: calc(100vh - var(--header-h));
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .table th { font-weight: 600; color: #495057; }
    /* Sidebar styles */
    .sidebar {
      position: fixed;
      top: var(--header-h);
      left: 0;
      bottom: 0;
      width: var(--sidebar-width);
      background: #0F3468;
      height: calc(100vh - var(--header-h));
      transition: left 0.2s;
      overflow-y: hidden;
      z-index: 1090;
    }
    .sidebar-backdrop {
      /* We will toggle this via JS instead of CSS media queries */
      display: none;
      position: fixed;
      top: var(--header-h);
      left: 0;
      width: 100vw;
      height: calc(100vh - var(--header-h));
      background: rgba(0,0,0,0.25);
      z-index: 1080;
    }
    .sidebar .nav-link { color: rgba(255,255,255,.85); padding: .6rem 1rem; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
    @media (max-width: 991.98px) {
      .sidebar {
        left: -100%;
        height: calc(100vh - var(--header-h));
        width: 220px;
        overflow-y: auto;
        box-shadow: 2px 0 8px rgba(0,0,0,0.08);
      }
      .sidebar.show {
        left: 0;
      }
      .main-content {
        padding: 1rem 0.8rem;
        margin-left: 0;
        height: calc(100vh - var(--header-h));
      }
    }
    .instructor-avatar-header {
      width: 36px;
      height: 36px;
      object-fit: cover;
      border-radius: 6px;
      border: 2px solid rgba(255, 255, 255, 0.35);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
      flex-shrink: 0;
      margin-right: 0.65rem;
      cursor: zoom-in;
    }
    #instructorAvatarZoomModal {
      --bs-modal-zindex: 12100;
    }
    .instructor-name-header {
      display: inline;
    }
    @media (max-width: 991.98px) {
      .instructor-name-header {
        display: none !important;
      }
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
  <div class="app-header d-flex align-items-center px-3">
    <!-- Sidebar Toggle Button for mobile -->
    <button class="btn btn-sm btn-outline-light d-lg-none me-2" type="button" id="sidebarToggler" aria-label="Open navigation">
      <i class="bi bi-list fs-4"></i>
    </button>
    <span class="fw-bold"><i class="bi bi-journal-check me-2"></i>Instructor</span>
    <div class="ms-auto d-flex align-items-center">
      <img src="<?= $avatarUrl ?>" alt="" class="instructor-avatar-header" id="instructorAvatarHeader" width="36" height="36" role="button" tabindex="0" aria-label="View profile photo larger"/>
      <span class="me-3 instructor-name-header"><?= htmlspecialchars($instructor['full_name'] ?? '') ?></span>
      <a href="<?= $baseUrl ?>/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </div>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <div class="d-flex">
    <nav class="sidebar py-2" id="sidebar">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'schedule' ? 'active' : '' ?>" href="<?= $baseUrl ?>/schedule.php"><i class="bi bi-calendar3 me-2"></i>Schedule</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'records' ? 'active' : '' ?>" href="<?= $baseUrl ?>/records.php"><i class="bi bi-journal-text me-2"></i>Class Records</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'class' ? 'active' : '' ?>" href="<?= $baseUrl ?>/class.php"><i class="bi bi-people me-2"></i>Class</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'grades' ? 'active' : '' ?>" href="<?= $baseUrl ?>/grades.php"><i class="bi bi-award me-2"></i>Grades</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
      </ul>
    </nav>
    <main class="main-content w-100">
      <?= $content ?? '' ?>
    </main>
  </div>
  <div class="modal fade" id="instructorAvatarZoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content bg-transparent border-0 shadow-none">
        <div class="modal-body p-0 text-center position-relative">
          <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="filter: drop-shadow(0 0 2px rgba(0,0,0,.85));"></button>
          <img id="instructorAvatarZoomPreview" src="" alt="Profile photo" class="img-fluid rounded" style="max-height: 85vh; object-fit: contain;">
        </div>
      </div>
    </div>
  </div>
  <?php if (!empty($showWelcomeModal)):
    $firstName = explode(' ', trim($instructor['full_name'] ?? 'Instructor'))[0];
  ?>
  <div class="modal fade modal-welcome" id="welcomeModalInstructor" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content border-0 bg-transparent">
        <div class="welcome-modal-card">
          <div class="welcome-modal-img-wrap">
            <img src="<?= htmlspecialchars($welcomeModalImageUrl) ?>" alt="Welcome">
          </div>
          <div class="welcome-modal-body">
            <h2>Welcome, <?= htmlspecialchars($firstName) ?>!</h2>
            <p>Your portal is ready. Manage your classes, record grades, and keep track of your schedule all in one place.</p>
            <button type="button" class="welcome-modal-close-btn" data-bs-dismiss="modal">Get Started</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Responsive sidebar logic
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');
    const toggler = document.getElementById('sidebarToggler');

    function isMobile() {
      return window.innerWidth < 992;
    }

    function openSidebar() {
      sidebar.classList.add('show');
      // Only show the backdrop if mobile AND sidebar is open
      if (isMobile()) {
        sidebarBackdrop.style.display = 'block';
        sidebarBackdrop.style.pointerEvents = 'auto';
        document.body.style.overflow = 'hidden';
      }
    }
    function closeSidebar() {
      sidebar.classList.remove('show');
      sidebarBackdrop.style.display = 'none';
      sidebarBackdrop.style.pointerEvents = 'none';
      document.body.style.overflow = '';
    }

    if (toggler) {
      toggler.addEventListener('click', openSidebar);
    }

    if (sidebarBackdrop) {
      sidebarBackdrop.addEventListener('click', closeSidebar);
    }

    // Close sidebar on navigation (for improved UX)
    sidebar.querySelectorAll('.nav-link').forEach(link => {
      link.addEventListener('click', () => {
        if (isMobile()) closeSidebar();
      });
    });

    // On window resize, close sidebar if desktop, and manage backdrop
    window.addEventListener('resize', () => {
      if (!isMobile()) {
        closeSidebar();
      } else if (sidebar.classList.contains('show')) {
        sidebarBackdrop.style.display = 'block';
        sidebarBackdrop.style.pointerEvents = 'auto';
        document.body.style.overflow = 'hidden';
      } else {
        sidebarBackdrop.style.display = 'none';
        sidebarBackdrop.style.pointerEvents = 'none';
        document.body.style.overflow = '';
      }
    });

    // Make sure on initial load, backdrop is only active if sidebar is open and on mobile
    window.addEventListener('DOMContentLoaded', () => {
      if (!isMobile()) {
        closeSidebar();
      }
    });

    (function () {
      var headerImg = document.getElementById('instructorAvatarHeader');
      var zoomModalEl = document.getElementById('instructorAvatarZoomModal');
      var zoomPreviewEl = document.getElementById('instructorAvatarZoomPreview');
      if (!headerImg || !zoomModalEl || !zoomPreviewEl || !window.bootstrap) return;
      var zoomModal = bootstrap.Modal.getOrCreateInstance(zoomModalEl);
      function openZoom() {
        zoomPreviewEl.src = headerImg.getAttribute('src') || '';
        zoomModal.show();
      }
      headerImg.addEventListener('click', openZoom);
      headerImg.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openZoom();
        }
      });
      zoomModalEl.addEventListener('shown.bs.modal', function () {
        var backs = document.querySelectorAll('body > .modal-backdrop');
        if (backs.length) backs[backs.length - 1].style.setProperty('z-index', '12090');
      });
      zoomModalEl.addEventListener('hidden.bs.modal', function () {
        zoomPreviewEl.removeAttribute('src');
        document.querySelectorAll('body > .modal-backdrop').forEach(function (b) {
          b.style.removeProperty('z-index');
        });
      });
    })();
  </script>
  <?php if (!empty($showWelcomeModal)): ?>
  <script>
  (function () {
    var el = document.getElementById('welcomeModalInstructor');
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
