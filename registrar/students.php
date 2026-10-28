<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

// Search
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 1;
$students = [];
if ($search !== '') {
    $pattern = '%' . $search . '%';
    $countStmt = $db->prepare("
      SELECT COUNT(*) AS total
      FROM students
      WHERE student_id LIKE ? OR full_name LIKE ? OR email LIKE ?
    ");
    $countStmt->execute([$pattern, $pattern, $pattern]);
    $totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $stmt = $db->prepare("
      SELECT id, student_id, full_name, email
      FROM students
      WHERE student_id LIKE ? OR full_name LIKE ? OR email LIKE ?
      ORDER BY full_name
      LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$pattern, $pattern, $pattern]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $totalRows = (int)($db->query("SELECT COUNT(*) AS total FROM students")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }
    $stmt = $db->query("SELECT id, student_id, full_name, email FROM students ORDER BY full_name LIMIT $perPage OFFSET $offset");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Students';
$currentPage = 'students';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Students</h4>
  <a href="<?= $baseUrl ?>/dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Dashboard</a>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" action="" class="row g-3">
      <div class="col-md-10">
        <label class="form-label">Search by Student ID, Name, or Email</label>
        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white">
    <strong>Student List</strong>
    <?php if ($search): ?>
      <span class="text-muted small">— <?= count($students) ?> result(s)</span>
    <?php endif; ?>
  </div>
  <div class="card-body p-0">
    <?php if (empty($students)): ?>
      <p class="text-muted p-3 mb-0">
        <?= $search ? 'No students found.' : 'Enter a search term or leave blank to view first 50 students.' ?>
      </p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead>
            <tr>
              <th style="background-color:rgb(28, 77, 146); color: #ffffff;">Student ID</th>
              <th style="background-color:rgb(28, 77, 146); color: #ffffff;">Full Name</th>
              <th style="background-color:rgb(28, 77, 146); color: #ffffff;">Email</th>
              <th class="text-center" style="background-color:rgb(28, 77, 146); color: #ffffff;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['student_id']) ?></td>
                <td><?= htmlspecialchars($s['full_name']) ?></td>
                <td><?= htmlspecialchars($s['email'] ?? '-') ?></td>
                <td class="text-center">
                  <div class="btn-group btn-group-sm" role="group">
                    <a href="#"
                      class="btn btn-outline-success btn-download-tor"
                      data-student-id="<?= (int)$s['id'] ?>"
                      title="Transcript of Record">
                      <i class="bi bi-file-earmark-text me-1"></i>TOR
                    </a>
                    <a href="#"
                      class="btn btn-outline-primary btn-download-grade-copy"
                      data-student-id="<?= (int)$s['id'] ?>"
                      title="All subjects: term grades and final (Excel)">
                      <i class="bi bi-table me-1"></i>Grades
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
        <div class="d-flex flex-column align-items-center gap-2 p-3 border-top">
          <small class="text-muted">
            Page <?= $page ?> of <?= $totalPages ?> (<?= $totalRows ?> total)
          </small>
          <nav aria-label="Students pagination">
            <ul class="pagination pagination-sm mb-0">
              <?php
                $buildPageUrl = function (int $p) use ($search) {
                    $params = ['page' => $p];
                    if ($search !== '') {
                        $params['search'] = $search;
                    }
                    return '?' . http_build_query($params);
                };
              ?>
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars($buildPageUrl(1)) ?>" title="First page">First</a>
              </li>
              <li class="page-item <?= $page <= 2 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 2 ? '#' : htmlspecialchars($buildPageUrl(max(1, $page - 2))) ?>" title="Skip back 2 pages">&laquo;</a>
              </li>
              <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars($buildPageUrl($page - 1)) ?>">Previous</a>
              </li>
              <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
              ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars($buildPageUrl($i)) ?>"><?= $i ?></a>
                </li>
              <?php endfor; ?>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($buildPageUrl($page + 1)) ?>">Next</a>
              </li>
              <li class="page-item <?= $page >= $totalPages - 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages - 1 ? '#' : htmlspecialchars($buildPageUrl(min($totalPages, $page + 2))) ?>" title="Skip forward 2 pages">&raquo;</a>
              </li>
              <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars($buildPageUrl($totalPages)) ?>" title="Last page">Last</a>
              </li>
            </ul>
          </nav>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="downloadGradeCopyModal" tabindex="-1" aria-labelledby="downloadGradeCopyModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="downloadGradeCopyModalLabel">Grade copy — signatories</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Enter names for the student grade copy (Printable Excel).</p>
        <input type="hidden" id="gradeCopyStudentId" value="">
        <div class="row g-3">
          <div class="col-md-6">
            <label for="gcExecutiveDean" class="form-label">Executive Dean</label>
            <input type="text" class="form-control" id="gcExecutiveDean" placeholder="e.g. DR. LYLANI S. CLARO">
          </div>
          <div class="col-md-6">
            <label for="gcProgramChair" class="form-label">Program Chairperson</label>
            <input type="text" class="form-control" id="gcProgramChair" placeholder="Program Chairperson">
          </div>
          <div class="col-md-6">
            <label for="gcSchoolPresident" class="form-label">School President</label>
            <input type="text" class="form-control" id="gcSchoolPresident" placeholder="School President">
          </div>
          <div class="col-md-6">
            <label for="gcRegistrarOfficer" class="form-label">Registrar Officer</label>
            <input type="text" class="form-control" id="gcRegistrarOfficer" placeholder="Registrar Officer">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="gradeCopySaveNamesBtn">Save names</button>
        <button type="button" class="btn btn-success" id="gradeCopyDownloadBtn">
          <i class="bi bi-download me-1"></i>Download grade copy
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="downloadTorModal" tabindex="-1" aria-labelledby="downloadTorModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="downloadTorModalLabel">TOR signatories</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Enter names as they should appear on the transcript (template: Prepared by, Check by, Dean).</p>
        <input type="hidden" id="torStudentId" value="">
        <div class="mb-3">
          <label for="torDeanName" class="form-label">Dean</label>
          <input type="text" class="form-control" id="torDeanName" value="" placeholder="e.g. DR. JANE DOE">
        </div>
        <div class="mb-3">
          <label for="torPreparedBy" class="form-label">Prepared by</label>
          <input type="text" class="form-control" id="torPreparedBy" value="" placeholder="Registrar staff name">
        </div>
        <div class="mb-1">
          <label for="torCheckedBy" class="form-label">Checked by</label>
          <input type="text" class="form-control" id="torCheckedBy" value="" placeholder="Verifier name">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="torSaveSignatoryBtn">Save names</button>
        <button type="button" class="btn btn-success" id="torConfirmDownloadBtn">
          <i class="bi bi-download me-1"></i>Download TOR
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var keyDean = 'registrar_tor_dean_name';
  var keyPrepared = 'registrar_tor_prepared_by';
  var keyChecked = 'registrar_tor_checked_by';

  var studentInput = document.getElementById('torStudentId');
  var deanInput = document.getElementById('torDeanName');
  var preparedInput = document.getElementById('torPreparedBy');
  var checkedInput = document.getElementById('torCheckedBy');
  var saveBtn = document.getElementById('torSaveSignatoryBtn');
  var downloadBtn = document.getElementById('torConfirmDownloadBtn');
  var modalEl = document.getElementById('downloadTorModal');
  if (!modalEl || !studentInput) return;

  var modal = new bootstrap.Modal(modalEl);

  function loadSavedTorNames() {
    deanInput.value = localStorage.getItem(keyDean) || '';
    preparedInput.value = localStorage.getItem(keyPrepared) || '';
    checkedInput.value = localStorage.getItem(keyChecked) || '';
  }

  function saveTorNames() {
    localStorage.setItem(keyDean, (deanInput.value || '').trim());
    localStorage.setItem(keyPrepared, (preparedInput.value || '').trim());
    localStorage.setItem(keyChecked, (checkedInput.value || '').trim());
  }

  document.querySelectorAll('.btn-download-tor').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      studentInput.value = this.getAttribute('data-student-id') || '';
      loadSavedTorNames();
      modal.show();
    });
  });

  saveBtn && saveBtn.addEventListener('click', function () {
    saveTorNames();
  });

  downloadBtn && downloadBtn.addEventListener('click', function () {
    saveTorNames();
    var sid = studentInput.value;
    if (!sid) return;
    var dean = encodeURIComponent((deanInput.value || '').trim());
    var prepared = encodeURIComponent((preparedInput.value || '').trim());
    var checked = encodeURIComponent((checkedInput.value || '').trim());
    var url = "<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/download_tor.php?student_id=" + encodeURIComponent(sid)
      + "&dean_name=" + dean
      + "&prepared_by=" + prepared
      + "&checked_by=" + checked;
    window.location.href = url;
    modal.hide();
  });

  // Grade copy (student-grade-copy-template)
  var gcKeys = {
    executive: 'registrar_grade_copy_executive_dean',
    chair: 'registrar_grade_copy_program_chairperson',
    president: 'registrar_grade_copy_school_president',
    registrar: 'registrar_grade_copy_registrar_officer'
  };
  var gcStudentId = document.getElementById('gradeCopyStudentId');
  var gcExec = document.getElementById('gcExecutiveDean');
  var gcChair = document.getElementById('gcProgramChair');
  var gcPres = document.getElementById('gcSchoolPresident');
  var gcReg = document.getElementById('gcRegistrarOfficer');
  var gcSave = document.getElementById('gradeCopySaveNamesBtn');
  var gcDl = document.getElementById('gradeCopyDownloadBtn');
  var gcModalEl = document.getElementById('downloadGradeCopyModal');
  if (gcModalEl && gcStudentId) {
    var gcModal = new bootstrap.Modal(gcModalEl);
    function loadGc() {
      gcExec.value = localStorage.getItem(gcKeys.executive) || '';
      gcChair.value = localStorage.getItem(gcKeys.chair) || '';
      gcPres.value = localStorage.getItem(gcKeys.president) || '';
      gcReg.value = localStorage.getItem(gcKeys.registrar) || '';
    }
    function saveGc() {
      localStorage.setItem(gcKeys.executive, (gcExec.value || '').trim());
      localStorage.setItem(gcKeys.chair, (gcChair.value || '').trim());
      localStorage.setItem(gcKeys.president, (gcPres.value || '').trim());
      localStorage.setItem(gcKeys.registrar, (gcReg.value || '').trim());
    }
    document.querySelectorAll('.btn-download-grade-copy').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        gcStudentId.value = this.getAttribute('data-student-id') || '';
        loadGc();
        gcModal.show();
      });
    });
    gcSave && gcSave.addEventListener('click', saveGc);
    gcDl && gcDl.addEventListener('click', function () {
      saveGc();
      var sid = gcStudentId.value;
      if (!sid) return;
      var base = "<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/download_prospectus.php?student_id=" + encodeURIComponent(sid);
      var q = [
        'executive_dean=' + encodeURIComponent((gcExec.value || '').trim()),
        'program_chairperson=' + encodeURIComponent((gcChair.value || '').trim()),
        'school_president=' + encodeURIComponent((gcPres.value || '').trim()),
        'registrar_officer=' + encodeURIComponent((gcReg.value || '').trim())
      ].join('&');
      window.location.href = base + '&' + q;
      gcModal.hide();
    });
  }
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
