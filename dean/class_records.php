<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();
$programDepartment = deanSelectedProgramDepartment();
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

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
$localDeptCodeMap = deanDepartmentCodeMap();
$localCourseToDept = [
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
    foreach ($localDeptCodeMap as $code => $names) {
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
foreach ($localCourseToDept as $course => $fullDeptName) {
    if (isset($deptToDeanMap[$fullDeptName])) {
        $deptToDeanMap[$course] = $deptToDeanMap[$fullDeptName];
    }
}

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

// --- Search addition ---
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';

$classRows = [];
if ($selectedAyId) {
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
    $params = [':ayId' => $selectedAyId];

    if ($programDepartment) {
        $deptExpr = [];

        if (!empty($programDeptFilters)) {
            $csDeptPlaceholders = [];
            $subDeptPlaceholders = [];
            foreach ($programDeptFilters as $idx => $deptVal) {
                $csKey = ':dept_cs_' . $idx;
                $subKey = ':dept_sub_' . $idx;
                $csDeptPlaceholders[] = $csKey;
                $subDeptPlaceholders[] = $subKey;
                $params[$csKey] = $deptVal;
                $params[$subKey] = $deptVal;
            }
            $deptExpr[] = "cs.department IN (" . implode(',', $csDeptPlaceholders) . ")";
            $deptExpr[] = "s.department IN (" . implode(',', $subDeptPlaceholders) . ")";
        }

        if (!empty($programCourses)) {
            $courseCodeLike = [];
            foreach ($programCourses as $idx => $courseCode) {
                $codeKey = ':code_like_' . $idx;
                $courseCodeLike[] = "s.code LIKE " . $codeKey;
                $params[$codeKey] = $courseCode . '%';
            }
            $deptExpr[] = '(' . implode(' OR ', $courseCodeLike) . ')';
        }

        if (!empty($deptExpr)) {
            $sql .= " AND (" . implode(' OR ', $deptExpr) . ")";
        } else {
            $sql .= " AND 0 = 1";
        }
    }

    // --- If search is set, search all fields included in the select ---
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
        $params[':search'] = '%' . $searchTerm . '%';
    }

    $sql .= "
        GROUP BY
            cs.id, cs.year_level, cs.section, cs.department,
            s.code, s.description, s.department,
            i.full_name, isub.id, ay.school_year, ay.semester
        ORDER BY s.code ASC, cs.year_level ASC, cs.section ASC
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Class Records';
$currentPage = 'class_records';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Class Records (Dean View)</h4>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="get" class="row g-2 align-items-center" id="classRecordsSearchForm">
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
        <!-- Search box -->
        <label class="form-label small text-muted mb-1">Search</label>
        <div class="input-group input-group-sm">
          <input
            type="text"
            name="search"
            class="form-control"
            placeholder="Search all fields..."
            value="<?= htmlspecialchars($searchTerm) ?>"
            autocomplete="off"
          />
          <button type="submit" class="btn btn-outline-primary">Search</button>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="small text-muted mt-3 mt-md-4">
          Showing classes<?= $programDepartment ? ' for ' . htmlspecialchars($programDepartment) : '' ?>.
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-file-earmark-spreadsheet me-2"></i>All Classes</strong>
    <span class="text-muted small"><?= count($classRows) ?> class(es)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($classRows)): ?>
      <p class="text-muted p-3 mb-0">No class records found for the selected filter.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Subject</th>
              <th>Class</th>
              <th>Department</th>
              <th>Instructor</th>
              <th>Students</th>
              <th class="text-end">Download</th>
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
                <!-- <?= (int)($row['year_level'] ?? 0) ?> -  -->
                  <?= htmlspecialchars($row['section'] ?? '-') ?><br>
                  <span class="small text-muted"><?= htmlspecialchars(trim(($row['school_year'] ?? '') . ' ' . ($row['semester'] ?? ''))) ?></span>
                </td>
                <td><?= htmlspecialchars($row['class_department'] ?: ($row['subject_department'] ?: '-')) ?></td>
                <td><?= htmlspecialchars($row['instructor_name'] ?? 'Not assigned') ?></td>
                <td><?= (int)($row['student_count'] ?? 0) ?></td>
                <td class="text-end">
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
  var keyExecutiveDean = 'dean_grade_report_exec_dean_name';
  var keyVpAcademic = 'dean_grade_report_vp_academic_name';

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
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
