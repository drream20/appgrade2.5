<?php
if (!function_exists('currentStudent')) {
    require __DIR__ . '/../../instructor/config/database.php';
    require __DIR__ . '/auth.php';
}
$student = currentStudent();
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$showWelcomeModal = !empty($student['id']) && (int)($student['welcome_modal_ack'] ?? 0) === 0;
$welcomeFirstName = explode(' ', trim($student['full_name'] ?? 'Student'))[0];
/** When the welcome modal shows: hero image under student/assets/welcome/ (replace PNG/JPG/SVG/GIF). */
$welcomeModalImageUrl = $showWelcomeModal ? ($baseUrl . '/student/assets/welcome/logo.png') : '';

// Determine student image path using uploaded file (student/uploads) when available,
// falling back to a generated avatar when no image is set.
$studentImage = !empty($student['image_path'])
    ? htmlspecialchars($baseUrl . '/' . ltrim($student['image_path'], '/'))
    : 'https://ui-avatars.com/api/?name=' . urlencode($student['full_name'] ?? 'Student');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Student') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <?php if (!empty($showWelcomeModal)): ?>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?php endif; ?>
  <style>
    :root { --sidebar-width: 240px; --header-h: 56px; }
    html { height: 100%; }
    body {
      height: 100%;
      max-height: 100vh;
      margin: 0;
      background: #f0f2f5;
      overflow-x: hidden;
      overflow-y: hidden;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
    }
    .app-header {
      position: sticky;
      top: 0;
      z-index: 1100;
      height: var(--header-h);
      min-height: var(--header-h);
      background: linear-gradient(135deg, #0F3468 0%, #0F3468 100%);
      color: #fff;
      flex-shrink: 0;
    }
    .app-body-row {
      flex: 1 1 0;
      min-height: 0;
      display: flex;
      overflow: hidden;
    }
    .sidebar {
      width: var(--sidebar-width);
      background: #0F3468;
      flex-shrink: 0;
      align-self: stretch;
      min-height: 0;
      overflow: hidden;
    }
    .sidebar .nav-link { color: rgba(255,255,255,.85); padding: .6rem 1rem; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
    .main-content {
      flex: 1 1 0;
      min-width: 0;
      min-height: 0;
      padding: 1.5rem;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    .table th { font-weight: 600; color: #495057; }
    /* Mobile: hide desktop sidebar, reduce padding */
    @media (max-width: 991.98px) {
      .sidebar-desktop { display: none !important; }
      .main-content { padding: 1rem; }
    }
    @media (min-width: 992px) {
      .sidebar-toggle { display: none !important; }
    }
    /* Offcanvas sidebar (mobile): Make offcanvas only half the viewport width on phones */
    .offcanvas-sidebar {
      background: #0F3468;
      width: 100vw;
      max-width: 100vw;
    }
    @media (max-width: 575.98px) {
      .offcanvas-sidebar {
        width: 50vw !important;
        max-width: 50vw !important;
      }
    }
    .offcanvas-sidebar .offcanvas-body { padding: 0; }
    .offcanvas-sidebar .nav-link { color: rgba(255, 255, 255, 0.85); padding: .75rem 1.25rem; }
    .offcanvas-sidebar .nav-link:hover, .offcanvas-sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.1); }
    /* Header mobile: wrap and truncate */
    .header-user { max-width: 140px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (min-width: 576px) { .header-user { max-width: 220px; } }

    /* Student image in header */
    .header-user-image {
      display: inline-block;
      width: 36px;
      height: 36px;
      object-fit: cover;
      object-position: center;
      border-radius: 0.25rem;
      background: #fff;
      border: 2px solid #e0e5ef;
      box-shadow: 0 2px 6px 0 rgba(0,0,0,0.03);
      vertical-align: middle;
      cursor: zoom-in;
    }
    #studentAvatarZoomModal {
      --bs-modal-zindex: 12100;
    }
    @media (min-width: 576px) {
      .header-user-text { display: inline !important; }
    }
    @media (max-width: 575.98px) {
      .header-user-text { display: none !important; }
    }
    /* Tables: horizontal scroll on small screens */
    .table-responsive { -webkit-overflow-scrolling: touch; }
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
      max-height: min(32vh, 200px);
      max-width: 100%;
      width: auto;
      height: auto;
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
      color:rgb(255, 255, 255);
      margin: 0 0 .35rem;
      letter-spacing: -.01em;
    }
    .welcome-modal-body p {
      font-weight: 400;
      font-size: .92rem;
      color: rgba(255, 255, 255, 0.95);
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
<body class="d-flex flex-column">
  <header class="app-header d-flex align-items-center px-2 px-sm-3">
    <button class="btn btn-link text-white p-2 me-1 sidebar-toggle d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMobile" aria-controls="sidebarMobile" aria-label="Open menu">
      <i class="bi bi-list fs-4"></i>
    </button>
    <span class="fw-bold text-truncate"><i class="bi bi-person-badge me-2 d-none d-sm-inline"></i>Student Portal</span>
    <div class="ms-auto d-flex align-items-center gap-1 gap-sm-2">
      <img src="<?= $studentImage ?>" alt="" class="header-user-image me-1 me-sm-2" id="studentAvatarHeader" width="36" height="36" role="button" tabindex="0" aria-label="View profile photo larger" title="<?= htmlspecialchars($student['full_name'] ?? '') ?>">
      <span class="header-user header-user-text text-white small me-1 me-sm-2" title="<?= htmlspecialchars($student['full_name'] ?? '') ?> (<?= htmlspecialchars($student['student_id'] ?? '') ?>)"><?= htmlspecialchars($student['full_name'] ?? '') ?> <span class="d-none d-sm-inline">(<?= htmlspecialchars($student['student_id'] ?? '') ?>)</span></span>
      <a href="<?= $baseUrl ?>/student/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </header>
  <div class="app-body-row">
    <nav class="sidebar sidebar-desktop py-2">
      <ul class="nav flex-column">
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'grades' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/grades.php"><i class="bi bi-award me-2"></i>Grades</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'records' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/records.php"><i class="bi bi-journal-text me-2"></i>Records</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'schedule' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/schedule.php"><i class="bi bi-calendar3 me-2"></i>Schedule</a></li>
        <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'ranking' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/ranking.php"><i class="bi bi-trophy me-2"></i>Ranking</a></li>
      </ul>
    </nav>
    <div class="offcanvas offcanvas-start offcanvas-sidebar" tabindex="-1" id="sidebarMobile" aria-labelledby="sidebarMobileLabel">
      <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title text-white" id="sidebarMobileLabel">Menu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body p-0">
        <ul class="nav flex-column">
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'profile' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'grades' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/grades.php"><i class="bi bi-award me-2"></i>Grades</a></li>
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'records' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/records.php"><i class="bi bi-journal-text me-2"></i>Records</a></li>
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'schedule' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/schedule.php"><i class="bi bi-calendar3 me-2"></i>Schedule</a></li>
          <li class="nav-item"><a class="nav-link <?= ($currentPage ?? '') === 'ranking' ? 'active' : '' ?>" href="<?= $baseUrl ?>/student/ranking.php"><i class="bi bi-trophy me-2"></i>Ranking</a></li>
        </ul>
      </div>
    </div>
    <main class="main-content w-100">
      <?= $content ?? '' ?>
    </main>
  </div>
  <?php if (!empty($showWelcomeModal)): ?>
  <div class="modal fade modal-welcome" id="welcomeModalStudent" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
      <div class="modal-content border-0 bg-transparent">
        <div class="welcome-modal-card">
          <div class="welcome-modal-img-wrap">
            <img src="<?= htmlspecialchars($welcomeModalImageUrl) ?>" alt="Welcome illustration" width="450" height="350" loading="eager" decoding="async">
          </div>
          <div class="welcome-modal-body">
            <h2>Welcome, <?= htmlspecialchars($welcomeFirstName) ?>!</h2>
            <p>Your portal is ready. View your grades, records, schedule, and class ranking—all in one place.</p>
            <button type="button" class="welcome-modal-close-btn" data-bs-dismiss="modal">Get Started</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <div class="modal fade" id="studentAvatarZoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content bg-transparent border-0 shadow-none">
        <div class="modal-body p-0 text-center position-relative">
          <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal" aria-label="Close" style="filter: drop-shadow(0 0 2px rgba(0,0,0,.85));"></button>
          <img id="studentAvatarZoomPreview" src="" alt="Profile photo" class="img-fluid rounded" style="max-height: 85vh; object-fit: contain;">
        </div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.querySelectorAll('#sidebarMobile .nav-link').forEach(function(el) {
      el.addEventListener('click', function() { var off = document.getElementById('sidebarMobile'); if (off && window.bootstrap?.Offcanvas) bootstrap.Offcanvas.getInstance(off)?.hide(); });
    });
    (function () {
      var headerImg = document.getElementById('studentAvatarHeader');
      var zoomModalEl = document.getElementById('studentAvatarZoomModal');
      var zoomPreviewEl = document.getElementById('studentAvatarZoomPreview');
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
    var el = document.getElementById('welcomeModalStudent');
    if (!el || !window.bootstrap) return;
    var modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
    var ackSent = false;
    modal.show();
    el.addEventListener('hidden.bs.modal', function () {
      if (ackSent) return;
      ackSent = true;
      fetch(<?= json_encode($baseUrl . '/student/dismiss_welcome_modal.php', JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES) ?>, {
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
