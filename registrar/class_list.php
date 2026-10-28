<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$academicYears = [];
$activeAyId = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester, is_active FROM academic_years ORDER BY school_year DESC, semester DESC");
    $academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($academicYears as $ay) {
        if (!empty($ay['is_active'])) {
            $activeAyId = (int)$ay['id'];
            break;
        }
    }
} catch (Throwable $e) {
    $academicYears = [];
}

$selectedAyId = isset($_GET['academic_year_id']) && $_GET['academic_year_id'] !== ''
    ? (int)$_GET['academic_year_id']
    : $activeAyId;

$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 1;

$deansByDept = [];
try {
    $stmtDeans = $db->query("SELECT full_name, department FROM deans WHERE department IS NOT NULL AND department != ''");
    foreach ($stmtDeans->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dept = trim($d['department']);
        $name = trim($d['full_name']);
        if ($dept === '' || $name === '') continue;
        $lower = strtolower($dept);
        if ($lower === 'executive' || $lower === 'executive dean') continue;
        $deansByDept[$dept] = $name;
    }
} catch (Throwable $e) {}

$deptToDeanMap = [];
$deptCodeMap = [
    'SCS' => ['School of Computer Studies', 'School of Science in Computer Studies'],
    'SHM' => ['School Hospitality Management'],
    'STE' => ['School of Teacher Education'],
    'SBAA' => ['School of Business Administration & Accountancy'],
    'SCJE' => ['School of Criminal Justice Education'],
    'SN' => ['School of Nursing'],
];
$courseToDept = [
    'BSCS' => 'School of Computer Studies',
    'BSHM' => 'School Hospitality Management',
    'BEED' => 'School of Teacher Education',
    'BSEDFIL' => 'School of Teacher Education',
    'BSEDMAT' => 'School of Teacher Education',
    'BSEDENG' => 'School of Teacher Education',
    'BSEDSS' => 'School of Teacher Education',
    'BSA' => 'School of Business Administration & Accountancy',
    'BSBAFM' => 'School of Business Administration & Accountancy',
    'BSCRIM' => 'School of Criminal Justice Education',
    'BSISM' => 'School of Criminal Justice Education',
    'BSN' => 'School of Nursing',
];

foreach ($deansByDept as $dept => $deanName) {
    $normalizedDept = strtolower(trim($dept));
    foreach ($deptCodeMap as $code => $names) {
        foreach ($names as $fullName) {
            if (strtolower($fullName) === $normalizedDept) {
                $deptToDeanMap[$code] = $deanName;
                $deptToDeanMap[$fullName] = $deanName;
                foreach ($names as $alias) {
                    $deptToDeanMap[$alias] = $deanName;
                }
            }
        }
    }
    $deptToDeanMap[$dept] = $deanName;
}
foreach ($courseToDept as $course => $fullDeptName) {
    if (isset($deptToDeanMap[$fullDeptName])) {
        $deptToDeanMap[$course] = $deptToDeanMap[$fullDeptName];
    }
}

$classRows = [];
if ($selectedAyId) {
    $sqlBase = "
        FROM class_sections cs
        INNER JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
        LEFT JOIN instructors i ON i.id = isub.instructor_id
        LEFT JOIN academic_years ay ON ay.id = cs.academic_year_id
        WHERE cs.academic_year_id = :ayId
    ";
    $params = [':ayId' => $selectedAyId];

    if ($searchTerm !== '') {
        $sqlBase .= "
            AND (
                cs.year_level LIKE :search OR
                cs.section LIKE :search OR
                cs.department LIKE :search OR
                s.code LIKE :search OR
                s.description LIKE :search OR
                s.department LIKE :search OR
                i.full_name LIKE :search OR
                ay.school_year LIKE :search OR
                ay.semester LIKE :search
            )
        ";
        $params[':search'] = '%' . $searchTerm . '%';
    }

    $countSql = "
        SELECT COUNT(*) AS total
        FROM (
            SELECT cs.id
            $sqlBase
            GROUP BY cs.id
        ) counted_classes
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $sql = "
        SELECT
            cs.id AS class_section_id,
            cs.year_level,
            cs.section,
            cs.department AS class_department,
            s.code AS subject_code,
            s.description AS subject_description,
            s.department AS subject_department,
            i.full_name AS instructor_name,
            isub.id AS instructor_subject_id,
            ay.school_year,
            ay.semester,
            COUNT(DISTINCT e.student_id) AS student_count
        FROM class_sections cs
        INNER JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
        LEFT JOIN instructors i ON i.id = isub.instructor_id
        LEFT JOIN enrollments e ON e.class_section_id = cs.id
        LEFT JOIN academic_years ay ON ay.id = cs.academic_year_id
        WHERE cs.academic_year_id = :ayId
    ";

    if ($searchTerm !== '') {
        $sql .= "
            AND (
                cs.year_level LIKE :search OR
                cs.section LIKE :search OR
                cs.department LIKE :search OR
                s.code LIKE :search OR
                s.description LIKE :search OR
                s.department LIKE :search OR
                i.full_name LIKE :search OR
                ay.school_year LIKE :search OR
                ay.semester LIKE :search
            )
        ";
    }

    $sql .= "
        GROUP BY
            cs.id, cs.year_level, cs.section, cs.department,
            s.code, s.description, s.department,
            i.full_name, isub.id, ay.school_year, ay.semester
        ORDER BY s.code ASC, cs.year_level ASC, cs.section ASC
        LIMIT $perPage OFFSET $offset
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Class List';
$currentPage = 'classlist';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Class List</h4>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-center" id="classListForm">
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">Academic Year</label>
        <select name="academic_year_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php foreach ($academicYears as $ay): ?>
            <?php $ayId = (int)($ay['id'] ?? 0); ?>
            <?php $ayLabel = trim(($ay['school_year'] ?? '') . ' ' . ($ay['semester'] ?? '')); ?>
            <option value="<?= $ayId ?>" <?= $selectedAyId === $ayId ? 'selected' : '' ?>>
              <?= htmlspecialchars($ayLabel) ?><?= !empty($ay['is_active']) ? ' (Active)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group input-group-sm">
          <input
            type="text"
            name="search"
            class="form-control form-control-sm"
            placeholder="Search all fields..."
            value="<?= htmlspecialchars($searchTerm) ?>"
            autocomplete="off"
          />
          <button class="btn btn-primary btn-sm" type="submit">
            <i class="bi bi-search"></i> Search
          </button>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="small text-muted mt-3 mt-md-4">
          Showing all classes.
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-file-earmark-spreadsheet me-2"></i>All Classes</strong>
    <span class="text-muted small"><?= $totalRows ?> class(es)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($classRows)): ?>
      <p class="text-muted p-3 mb-0">No class records found for the selected filter.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="background-color:rgb(28, 77, 146); color: #ffffff;">Subject</th>
              <th style="background-color: rgb(28, 77, 146); color: #ffffff;">Class</th>
              <th style="background-color: rgb(28, 77, 146); color: #ffffff;">Department</th>
              <th style="background-color: rgb(28, 77, 146); color: #ffffff;">Instructor</th>
              <th style="background-color: rgb(28, 77, 146); color: #ffffff;">Students</th>
              <th class="text-center" style="background-color: rgb(28, 77, 146); color: #ffffff;">Download</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classRows as $row): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($row['subject_code'] ?? '') ?></strong><br>
                  <span class="small text-muted"><?= htmlspecialchars($row['subject_description'] ?? '') ?></span>
                </td>
                <td>
                  <?= htmlspecialchars($row['section'] ?? '-') ?><br>
                  <span class="small text-muted"><?= htmlspecialchars(trim(($row['school_year'] ?? '') . ' ' . ($row['semester'] ?? ''))) ?></span>
                </td>
                <td><?= htmlspecialchars($row['class_department'] ?: ($row['subject_department'] ?: '-')) ?></td>
                <td><?= htmlspecialchars($row['instructor_name'] ?? 'Not assigned') ?></td>
                <td><?= (int)($row['student_count'] ?? 0) ?></td>
                <td class="text-center">
                  <a
                    href="#"
                    class="btn btn-outline-success btn-sm btn-download-grade-report"
                    data-class-section-id="<?= (int)$row['class_section_id'] ?>"
                    data-department="<?= htmlspecialchars($row['class_department'] ?: ($row['subject_department'] ?: '')) ?>"
                  >
                    <i class="bi bi-download me-1"></i>Excel
                  </a>
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
          <nav aria-label="Class list pagination">
            <ul class="pagination pagination-sm mb-0">
              <?php
                $buildPageUrl = function (int $p) use ($selectedAyId, $searchTerm) {
                    $params = ['page' => $p];
                    if ($selectedAyId) {
                        $params['academic_year_id'] = $selectedAyId;
                    }
                    if ($searchTerm !== '') {
                        $params['search'] = $searchTerm;
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

<div class="modal fade" id="downloadGradeReportModal" tabindex="-1" aria-labelledby="downloadGradeReportModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="downloadGradeReportModalLabel">Signatory Names</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="small text-muted mb-3">Set or edit names before downloading the grade report.</p>
        <input type="hidden" id="downloadClassSectionId" value="">
        <div class="mb-3">
          <label for="deanName" class="form-label">Dean</label>
          <input type="text" class="form-control" id="deanName" value="">
          <div class="form-text" id="deanNameHint"></div>
        </div>
        <div class="mb-3">
          <label for="executiveDeanName" class="form-label">Executive Dean</label>
          <input type="text" class="form-control" id="executiveDeanName" value="DR. LYLANI S. CLARO">
        </div>
        <div class="mb-1">
          <label for="vpAcademicName" class="form-label">Vice President for Academic Affairs</label>
          <input type="text" class="form-control" id="vpAcademicName" value="DR. RAQUEL T. STA. INES.">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="saveSignatoryNamesBtn">Save Names</button>
        <button type="button" class="btn btn-success" id="confirmDownloadGradeReportBtn">
          <i class="bi bi-download me-1"></i>Download
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var deptToDean = <?= json_encode($deptToDeanMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>;
  var defaultExecutiveDean = 'DR. LYLANI S. CLARO';
  var defaultVpAcademic = 'DR. RAQUEL T. STA. INES.';
  var keyExecutiveDean = 'registrar_grade_report_exec_dean_name';
  var keyVpAcademic = 'registrar_grade_report_vp_academic_name';

  var classSectionInput = document.getElementById('downloadClassSectionId');
  var deanNameInput = document.getElementById('deanName');
  var deanNameHint = document.getElementById('deanNameHint');
  var executiveDeanInput = document.getElementById('executiveDeanName');
  var vpAcademicInput = document.getElementById('vpAcademicName');
  var saveBtn = document.getElementById('saveSignatoryNamesBtn');
  var downloadBtn = document.getElementById('confirmDownloadGradeReportBtn');
  var modalEl = document.getElementById('downloadGradeReportModal');
  if (!modalEl) return;

  var modal = new bootstrap.Modal(modalEl);

  function loadSavedNames() {
    var savedExecutiveDean = localStorage.getItem(keyExecutiveDean);
    var savedVpAcademic = localStorage.getItem(keyVpAcademic);
    executiveDeanInput.value = savedExecutiveDean && savedExecutiveDean.trim() !== '' ? savedExecutiveDean : defaultExecutiveDean;
    vpAcademicInput.value = savedVpAcademic && savedVpAcademic.trim() !== '' ? savedVpAcademic : defaultVpAcademic;
  }

  function saveNames() {
    var executiveDean = (executiveDeanInput.value || '').trim();
    var vpAcademic = (vpAcademicInput.value || '').trim();
    localStorage.setItem(keyExecutiveDean, executiveDean !== '' ? executiveDean : defaultExecutiveDean);
    localStorage.setItem(keyVpAcademic, vpAcademic !== '' ? vpAcademic : defaultVpAcademic);
  }

  function resolveDeanName(dept) {
    if (!dept) return '';
    if (deptToDean[dept]) return deptToDean[dept];
    var upper = dept.toUpperCase();
    for (var key in deptToDean) {
      if (key.toUpperCase() === upper) return deptToDean[key];
    }
    return '';
  }

  document.querySelectorAll('.btn-download-grade-report').forEach(function (btn) {
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      classSectionInput.value = this.getAttribute('data-class-section-id') || '';
      var dept = this.getAttribute('data-department') || '';
      var autoName = resolveDeanName(dept);
      deanNameInput.value = autoName;
      deanNameHint.textContent = autoName
        ? 'Auto-filled from department: ' + dept
        : (dept ? 'No dean found for: ' + dept : '');
      loadSavedNames();
      modal.show();
    });
  });

  saveBtn.addEventListener('click', function () {
    saveNames();
  });

  downloadBtn.addEventListener('click', function () {
    saveNames();
    var classSectionId = classSectionInput.value;
    if (!classSectionId) return;

    var deanName = encodeURIComponent((deanNameInput.value || '').trim());
    var executiveDean = encodeURIComponent((executiveDeanInput.value || '').trim());
    var vpAcademic = encodeURIComponent((vpAcademicInput.value || '').trim());
    var url = "<?= $baseUrl ?>/download_grade_report.php?class_section_id=" + encodeURIComponent(classSectionId)
      + "&dean_name=" + deanName
      + "&executive_dean=" + executiveDean
      + "&vp_academic_affairs=" + vpAcademic;
    window.location.href = url;
    modal.hide();
  });

  // Remove auto-search on input for the search box (previously, none here, but double check)
  // No extra JS needed since search now occurs on form submission via the search button
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
