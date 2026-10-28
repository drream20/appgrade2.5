<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();
// Program context
$programDepartment = deanSelectedProgramDepartment();
$programCourses = $programDepartment ? deanCoursesForDepartment($programDepartment) : [];
$programDeptFilters = $programDepartment ? deanDepartmentFilterValues($programDepartment) : [];

// Active academic term (system-wide)
$activeAY = getActiveAcademicYear($db);
$selectedAyId = $activeAY ? (int)$activeAY['id'] : null;
$activeAyLabel = $activeAY ? trim(($activeAY['school_year'] ?? '') . ' ' . ($activeAY['semester'] ?? '')) : null;

// Terms and attendance filters
$terms = [];
try {
    $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $terms = [];
}
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
if ($termId <= 0 && !empty($terms)) {
    $termId = (int)($terms[0]['id'] ?? 0);
}

// Sections and subjects for selected AY
// Sections and subjects for selected AY
$classSections = [];
if ($selectedAyId) {
    $sql = "
        SELECT
          cs.id,
          cs.section,
          cs.year_level,
          cs.department,
          s.code,
          s.description,
          i.full_name AS instructor_name
        FROM class_sections cs
        JOIN subjects s ON s.id = cs.subject_id
        LEFT JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
        LEFT JOIN instructors i ON i.id = isub.instructor_id
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
    $sql .= " ORDER BY s.code, cs.year_level, cs.section";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $classSections = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$classSectionIds = array_map(static fn($cs) => (int)($cs['id'] ?? 0), $classSections);
$classSectionIds = array_values(array_filter($classSectionIds, static fn($id) => $id > 0));

// Available attendance dates for the selected term (scoped to the visible classes)
$attendanceDates = [];
$selectedAttendanceDate = '';
$today = date('Y-m-d');
if ($termId > 0 && !empty($classSectionIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($classSectionIds), '?'));
        $params = array_merge([$termId], $classSectionIds);
        $stmt = $db->prepare("
            SELECT DISTINCT ar.attendance_date
            FROM attendance_records ar
            INNER JOIN enrollments e ON e.id = ar.enrollment_id
            INNER JOIN class_sections cs ON cs.id = e.class_section_id
            WHERE ar.term_id = ?
              AND cs.id IN ($placeholders)
              AND ar.attendance_date IS NOT NULL
            ORDER BY ar.attendance_date DESC
            LIMIT 30
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $attendanceDates = array_values(array_map(static fn($r) => (string)($r['attendance_date'] ?? ''), $rows));
    } catch (Throwable $e) {
        $attendanceDates = [];
    }
}

// Pick selected date: URL param > latest available > today
if (!empty($_GET['attendance_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['attendance_date'])) {
    $selectedAttendanceDate = (string)$_GET['attendance_date'];
} else {
    $selectedAttendanceDate = !empty($attendanceDates) ? (string)($attendanceDates[0] ?? '') : $today;
}

// Precompute "checked attendance" for blinking dot (complete for all enrolled students)
$attendanceCheckedMap = [];
if ($termId > 0 && validDateYmd($selectedAttendanceDate ?? '')) {
    if (!empty($classSectionIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($classSectionIds), '?'));
            $params = array_merge([$termId, $selectedAttendanceDate], $classSectionIds);
            $stmt = $db->prepare("
                SELECT
                    cs.id AS class_section_id,
                    COUNT(DISTINCT e.id) AS expected_students,
                    COUNT(DISTINCT ar.enrollment_id) AS attended_students
                FROM class_sections cs
                INNER JOIN subjects s ON s.id = cs.subject_id
                INNER JOIN enrollments e ON e.class_section_id = cs.id AND e.status = 'enrolled'
                LEFT JOIN attendance_records ar
                    ON ar.enrollment_id = e.id
                   AND ar.term_id = ?
                   AND ar.attendance_date = ?
                WHERE cs.id IN ($placeholders)
                GROUP BY cs.id
            ");
            $stmt->execute($params);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $csId = (int)($r['class_section_id'] ?? 0);
                $expected = (int)($r['expected_students'] ?? 0);
                $attended = (int)($r['attended_students'] ?? 0);
                $attendanceCheckedMap[$csId] = ($expected > 0 && $attended >= $expected);
            }
        } catch (Throwable $e) {
            $attendanceCheckedMap = [];
        }
    }
}

function validDateYmd(string $s): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s);
}

$pageTitle = 'Sections & Subjects';
$currentPage = 'sections';
ob_start();
?>
<style>
  .attendance-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    display: inline-block;
    margin-left: 8px;
    vertical-align: middle;
    background: #cfd4da;
  }
  .attendance-dot.blink {
    background: #198754;
    animation: attendanceBlink 1s infinite;
  }
  @keyframes attendanceBlink {
    0%, 100% { opacity: 0.25; }
    50% { opacity: 1; }
  }

  /* Class Sections — search toolbar */
  .sections-search-card {
    border-radius: 12px;
    overflow: hidden;
  }
  .sections-search-card .sections-title .bi-journal-text {
    color: #0f3468;
  }
  .sections-search-card .card-header.sections-card-header {
    background: linear-gradient(180deg, #f8f9fb 0%, #ffffff 55%);
    border-bottom: 1px solid rgba(15, 52, 104, 0.09);
    padding: 1rem 1.25rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem 1rem;
  }
  .sections-search-card .sections-header-left {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.85rem;
    min-width: 0;
  }
  .sections-search-card .card-header .sections-title {
    font-size: 1.05rem;
    letter-spacing: -0.02em;
    color: #1a2b3c;
    display: inline-flex;
    align-items: center;
    line-height: 1.2;
    margin: 0;
  }
  .sections-search-card .sections-search-toolbar {
    display: flex;
    align-items: center;
    flex: 1 1 260px;
    max-width: 420px;
    min-width: min(100%, 200px);
    margin-left: auto;
  }
  .sections-search-card .sections-search-wrap {
    width: 100%;
  }
  .sections-search-bar {
    display: flex;
    align-items: stretch;
    background: #fff;
    border: 2px solid rgb(207, 207, 207);
    border-radius: 4px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(15, 52, 104, 0.06);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
  }
  .sections-search-bar:focus-within {
    border-color: rgba(15, 52, 104, 0.35);
    box-shadow: 0 0 0 3px rgba(15, 52, 104, 0.1), 0 2px 8px rgba(15, 52, 104, 0.06);
  }
  .sections-search-bar .sections-search-field {
    position: relative;
    flex: 1;
    display: flex;
    align-items: center;
    min-width: 0;
  }
  .sections-search-bar .sections-search-field .bi-search {
    position: absolute;
    left: 1rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 1rem;
    color: #8b95a1;
    pointer-events: none;
    z-index: 1;
  }
  .sections-search-bar .form-control {
    border: 0;
    box-shadow: none;
    padding: 0.5rem 0.85rem 0.5rem 2.65rem;
    font-size: 0.9rem;
    background: transparent;
  }
  .sections-search-bar .form-control::placeholder {
    color: #9aa5b1;
  }
  .sections-search-bar .form-control:focus {
    box-shadow: none;
  }
  .sections-search-bar .btn-sections-search {
    flex-shrink: 0;
    padding: 0.45rem 1.15rem;
    font-weight: 600;
    font-size: 0.875rem;
    letter-spacing: 0.01em;
    border: 0;
    border-radius: 0;
    background: linear-gradient(180deg, #124a8f 0%, #0f3468 100%);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    transition: filter 0.15s ease, transform 0.1s ease;
  }
  .sections-search-bar .btn-sections-search:hover {
    color: #fff;
    filter: brightness(1.06);
  }
  .sections-search-bar .btn-sections-search:active {
    transform: scale(0.98);
  }
  .sections-search-count {
    font-size: 0.8rem;
    font-weight: 500;
    padding: 0.4rem 0.85rem;
    border-radius: 999px;
    background: #fff;
    border: 1px solid #e8ecf0 !important;
    color: #5c6770;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
  }
  .sections-search-count .sections-count-num {
    color: #0f3468;
    font-weight: 700;
  }
  .sections-search-card .table thead.table-light th {
    background: linear-gradient(180deg, #f5f7fa 0%, #eef1f5 100%) !important;
    border-bottom: 2px solid rgba(15, 52, 104, 0.1);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #495057;
    padding-top: 0.85rem;
    padding-bottom: 0.85rem;
  }
  .sections-search-card .table tbody td {
    padding-top: 0.9rem;
    padding-bottom: 0.9rem;
    border-color: #f0f2f4;
  }
  @media (max-width: 575.98px) {
    .sections-search-card .sections-search-toolbar {
      margin-left: 0;
      max-width: none;
    }
  }
</style>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Sections &amp; Subjects (View Only)</h4>
  <?php if ($activeAyLabel): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-body">
    <?php if ($activeAY): ?>
      <p class="text-muted small mb-0">
        Showing sections for the active term only<?= $programDepartment ? ' (filtered to ' . htmlspecialchars($programDepartment) . ')' : '' ?>.
      </p>
    <?php else: ?>
      <div class="alert alert-warning mb-0">No active semester. Please contact MIS to activate an academic year.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <?php if (deanIsExecutive() && isset($_GET['program'])): ?>
        <input type="hidden" name="program" value="<?= htmlspecialchars((string)($_GET['program'] ?? '')) ?>">
      <?php endif; ?>
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">Term</label>
        <select name="term_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php if (empty($terms)): ?>
            <option value="">No terms found</option>
          <?php else: ?>
            <?php foreach ($terms as $t): ?>
              <?php $tid = (int)($t['id'] ?? 0); ?>
              <option value="<?= $tid ?>" <?= $termId === $tid ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)($t['name'] ?? $tid)) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label small text-muted mb-1">Attendance Date</label>
        <select name="attendance_date" class="form-select form-select-sm" onchange="this.form.submit()">
          <?php if (!empty($attendanceDates)): ?>
            <?php foreach ($attendanceDates as $d): ?>
              <option value="<?= htmlspecialchars($d) ?>" <?= $selectedAttendanceDate === $d ? 'selected' : '' ?>>
                <?= htmlspecialchars($d) ?>
              </option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="<?= htmlspecialchars($selectedAttendanceDate) ?>" selected>
              <?= htmlspecialchars($selectedAttendanceDate) ?>
            </option>
          <?php endif; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <div class="text-muted small mb-0">
          Download is per class for the selected date/term.
        </div>
      </div>
    </form>
  </div>
</div>

<div class="card sections-search-card">
  <div class="card-header sections-card-header">
    <div class="sections-header-left">
      <div class="sections-title fw-semibold">
        <i class="bi bi-journal-text me-2" aria-hidden="true"></i>Class Sections
      </div>
      <span class="sections-search-count text-nowrap">
        <span class="sections-count-num" id="sectionTableVisibleCount"><?= count($classSections) ?></span>
        <span class="text-muted"> / </span><?= count($classSections) ?>
        <span class="d-none d-sm-inline"> shown</span>
      </span>
    </div>
    <div class="sections-search-toolbar">
      <label class="visually-hidden" for="sectionTableSearch">Search all columns</label>
      <div class="sections-search-wrap">
        <div class="sections-search-bar">
          <div class="sections-search-field">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input
              type="text"
              id="sectionTableSearch"
              class="form-control form-control-sm"
              placeholder="Search subject, year, section, department, instructor…"
              autocomplete="off"
              aria-label="Search class sections"
            >
          </div>
          <button type="button" class="btn btn-sections-search" id="sectionTableSearchBtn" title="Run search" aria-label="Search">
            <i class="bi bi-search d-sm-none" aria-hidden="true"></i>
            <span class="d-none d-sm-inline">Search</span>
          </button>
        </div>
      </div>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($classSections)): ?>
      <p class="text-muted p-3 mb-0">No class sections found for the selected academic year.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table id="classSectionsTable" class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Subject</th>
              <th>Year Level</th>
              <th>Section</th>
              <th>Department</th>
              <th>Instructor</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($classSections as $cs): ?>
              <?php
              $sectionSearchBlob = mb_strtolower(
                trim(
                  ($cs['code'] ?? '') . ' '
                  . ($cs['description'] ?? '') . ' '
                  . (string)(int)($cs['year_level'] ?? 0) . ' '
                  . ($cs['section'] ?? '') . ' '
                  . ($cs['department'] ?? '') . ' '
                  . ($cs['instructor_name'] ?? '')
                ),
                'UTF-8'
              );
              ?>
              <tr data-search="<?= htmlspecialchars($sectionSearchBlob, ENT_QUOTES, 'UTF-8') ?>">
                <td>
                  <strong><?= htmlspecialchars($cs['code']) ?></strong><br>
                  <span class="small text-muted"><?= htmlspecialchars($cs['description'] ?? '') ?></span>
                </td>
                <td><?= (int)$cs['year_level'] ?></td>
                <td><?= htmlspecialchars($cs['section'] ?? '') ?></td>
                <td><?= htmlspecialchars($cs['department'] ?? '-') ?></td>
                <td><?= htmlspecialchars($cs['instructor_name'] ?? 'Not assigned') ?></td>
                <td>
                  <?php $csId = (int)($cs['id'] ?? 0); ?>
                  <?php $checked = !empty($attendanceCheckedMap[$csId]); ?>
                  <div class="d-flex align-items-center justify-content-end">
                    <a
                      href="<?= $baseUrl ?>/download_attendance_report.php?class_section_id=<?= urlencode((string)$csId) ?>&term_id=<?= urlencode((string)$termId) ?>&attendance_date=<?= urlencode((string)$selectedAttendanceDate) ?>"
                      class="btn btn-outline-primary btn-sm"
                      title="Download attendance CSV for this class/date/term"
                    >
                      <i class="bi bi-download"></i> Download
                    </a>
                    <?php if ($checked): ?>
                      <span class="attendance-dot blink" title="Attendance checked"></span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <script>
        (function () {
          var input = document.getElementById('sectionTableSearch');
          var btn = document.getElementById('sectionTableSearchBtn');
          var tbody = document.querySelector('#classSectionsTable tbody');
          var countEl = document.getElementById('sectionTableVisibleCount');
          if (!input || !btn || !tbody || !countEl) return;
          function applyFilter() {
            var q = (input.value || '').trim().toLowerCase();
            var visible = 0;
            tbody.querySelectorAll('tr').forEach(function (tr) {
              var hay = tr.getAttribute('data-search') || '';
              var show = !q || hay.indexOf(q) !== -1;
              tr.style.display = show ? '' : 'none';
              if (show) visible++;
            });
            countEl.textContent = String(visible);
          }
          btn.addEventListener('click', applyFilter);
          input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              applyFilter();
            }
          });
        })();
      </script>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';

