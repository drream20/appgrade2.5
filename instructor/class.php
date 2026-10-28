<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
requireLogin();

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$appBaseUrl = rtrim(dirname($baseUrl), '/');

$db = getDb();
$instructorId = currentInstructorId();

// --- Academic Year/Semester: Dynamic from admin (all available years/semesters) ---
$stmt = $db->prepare("SELECT DISTINCT school_year, semester FROM academic_years ORDER BY school_year DESC, semester ASC");
$stmt->execute();
$acadYearSemList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- All instructor_subjects for this instructor, to check which years/semesters they handle ---
$stmt = $db->prepare("
    SELECT id, school_year, semester
    FROM instructor_subjects
    WHERE instructor_id = ?
");
$stmt->execute([$instructorId]);
$instructorYearSem = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Build fast lookup for year/sem pairs the instructor has...
$instructorYearSemPairs = [];
foreach ($instructorYearSem as $r) {
    $instructorYearSemPairs[$r['school_year'].'###'.$r['semester']] = true;
}

// --- Figure selected academic year/semester ---
// If a year-semester was selected, keep it; otherwise, pick the first year/sem the instructor has
$schoolYear = isset($_GET['school_year']) ? $_GET['school_year'] : '';
$semester = isset($_GET['semester']) ? $_GET['semester'] : '';
// If nothing selected (e.g. direct page load), pick the most recent academic year/semester that instructor actually has classes in
if (!$schoolYear || !$semester) {
    foreach ($acadYearSemList as $ysem) {
        if (isset($instructorYearSemPairs[$ysem['school_year'].'###'.$ysem['semester']])) {
            $schoolYear = $ysem['school_year'];
            $semester = $ysem['semester'];
            break;
        }
    }
}

// --- Filter instructor_subjects for this instructor & selected academic year/semester ---
$subjects = [];
if ($schoolYear && $semester) {
    $stmt = $db->prepare("
      SELECT isub.id as instructor_subject_id,
             s.code, s.description, isub.school_year, isub.semester
      FROM instructor_subjects isub
      JOIN subjects s ON s.id = isub.subject_id
      WHERE isub.instructor_id = ?
        AND isub.school_year = ?
        AND isub.semester = ?
      ORDER BY s.code,  s.description
    ");
    $stmt->execute([$instructorId, $schoolYear, $semester]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$instructorSubjectId = isset($_GET['instructor_subject_id'])
    ? (int)$_GET['instructor_subject_id']
    : (isset($subjects[0]['instructor_subject_id']) ? (int)$subjects[0]['instructor_subject_id'] : null);

$sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : null;

$subjectInfo = null;
$sections = [];
$enrollments = [];

if ($instructorSubjectId) {
    foreach ($subjects as $s) {
        if ((int)$s['instructor_subject_id'] === $instructorSubjectId) {
            $subjectInfo = $s;
            break;
        }
    }

    if ($subjectInfo) {
        $stmt = $db->prepare("
          SELECT id, section, year_level
          FROM class_sections
          WHERE instructor_subject_id = ?
          ORDER BY year_level, section
        ");
        $stmt->execute([$instructorSubjectId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $params = [$instructorSubjectId, $instructorSubjectId];
        $sectionFilterSql = '';
        if ($sectionId) {
            $sectionFilterSql = " AND e.class_section_id = ? ";
            $params[] = $sectionId;
        }

        // Fetch st.image_path directly as stored in database
        $sql = "
          SELECT 
            e.id as enrollment_id,
            st.id as student_db_id,
            st.student_id as student_number,
            st.full_name,
            st.image_path,
            cs.section,
            COALESCE(e.status, 'enrolled') as enrollment_status
          FROM enrollments e
          JOIN students st ON st.id = e.student_id
          LEFT JOIN class_sections cs ON cs.id = e.class_section_id
          WHERE (e.instructor_subject_id = ?
             OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?))
          $sectionFilterSql
          ORDER BY st.full_name
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'Class Management';
$currentPage = 'class';
ob_start();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Class Management</h4>
</div>
<div class="alert alert-info py-2 mb-3 small" style="font-size: 11px;">
  <i class="bi bi-info-circle"></i>
  This list is <strong>read-only</strong>. Enrolling students or changing enrollment status (drop/re-enroll) is done in <strong>MIS</strong> (uploads / schedules). Students marked <span class="text-danger">Dropped</span> do not appear when recording assessments or attendance; only <strong>Enrolled</strong> students are used for grades and attendance.
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong>Class roster</strong>
  </div>
  <div class="card-body">
    <?php if (empty($acadYearSemList)): ?>
      <p class="text-muted mb-0">No academic years or classes found.<br>Please contact the administrator.</p>
    <?php else: ?>
      <form method="get" class="row g-2 align-items-end mb-3" id="filterForm">
        <div class="col-md-3">
          <label class="form-label">Academic Year</label>
          <select name="school_year" class="form-select" id="school_year_select"
            onchange="const year = this.value; const semSel = document.getElementById('semester_select'); for(let i=0;i<semSel.options.length;i++){ semSel.options[i].style.display = (semSel.options[i].dataset.year===year)?'':'none';} semSel.selectedIndex=0; semSel.form.submit();">
            <?php
              $yearsShown = [];
              foreach ($acadYearSemList as $ys) {
                if (!in_array($ys['school_year'], $yearsShown, true)) {
                  $hasClass = isset($instructorYearSemPairs[$ys['school_year'].'###'.$ys['semester']]);
                  // Only enable years that have a matching class for instructor (any semester)
                  $foundAtAll = false;
                  foreach ($acadYearSemList as $cmp) {
                    if ($cmp['school_year'] === $ys['school_year'] && isset($instructorYearSemPairs[$cmp['school_year'].'###'.$cmp['semester']])) {
                      $foundAtAll = true;
                      break;
                    }
                  }
                  ?>
                  <option value="<?= htmlspecialchars($ys['school_year']) ?>"
                    <?= $ys['school_year'] === $schoolYear ? 'selected' : '' ?> <?= $foundAtAll ? '' : 'disabled' ?>>
                    <?= htmlspecialchars($ys['school_year']) ?><?= $foundAtAll ? '' : ' (no class)' ?>
                  </option>
                  <?php
                  $yearsShown[] = $ys['school_year'];
                }
              }
            ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Semester</label>
          <select name="semester" id="semester_select" class="form-select" onchange="this.form.submit()">
            <?php
              $done = [];
              foreach ($acadYearSemList as $ys) {
                $key = $ys['school_year'].'###'.$ys['semester'];
                if (isset($done[$key])) continue;
                $done[$key] = true;
                if (!isset($instructorYearSemPairs[$key])) continue; // Only show semesters where instructor has class
            ?>
              <option 
                value="<?= htmlspecialchars($ys['semester']) ?>"
                data-year="<?= htmlspecialchars($ys['school_year']) ?>"
                style="<?= $ys['school_year'] === $schoolYear ? '' : 'display:none;' ?>"
                <?= $ys['school_year'] === $schoolYear && $ys['semester'] === $semester ? 'selected' : '' ?>
              ><?= htmlspecialchars($ys['semester']) ?></option>
            <?php } ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Subjects</label>
          <select name="instructor_subject_id" class="form-select" onchange="this.form.submit()" <?= empty($subjects) ? 'disabled' : '' ?>>
            <?php foreach ($subjects as $s): ?>
              <option value="<?= (int)$s['instructor_subject_id'] ?>" <?= (int)$s['instructor_subject_id'] === (int)$instructorSubjectId ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['code'].' - '.$s['description']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Section</label>
          <select name="section_id" class="form-select" onchange="this.form.submit()" <?= empty($subjects) ? 'disabled' : '' ?>>
            <option value="">All sections</option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?= (int)$sec['id'] ?>" <?= $sectionId && (int)$sec['id'] === (int)$sectionId ? 'selected' : '' ?>>
                <?= htmlspecialchars($sec['section']) ?><?= $sec['year_level'] ? ' - Y' . (int)$sec['year_level'] : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-1 d-none d-md-block">
          <button type="submit" class="btn btn-primary w-100">Filter</button>
        </div>
      </form>

      <?php if (empty($subjects)): ?>
        <p class="text-muted mb-0">No subjects found for the selected academic year & semester.</p>
      <?php elseif (empty($enrollments)): ?>
        <p class="text-muted mb-0">No students found for the selected subject/section.</p>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th style="width: 7%;">Profile</th>
                <th style="width: 15%;">Student No.</th>
                <th style="width: 45%;">Name</th>
                <th style="width: 15%;">Section</th>
                <th style="width: 18%;">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($enrollments as $e): ?>
                <tr>
                  <td>
                    <?php
                      $rawImagePath = isset($e['image_path']) ? trim((string)$e['image_path']) : '';
                      if ($rawImagePath !== '') {
                          if (preg_match('/^https?:\/\//i', $rawImagePath) || str_starts_with($rawImagePath, '/')) {
                              $profileImg = $rawImagePath;
                          } else {
                              // DB stores paths like student/uploads/..., resolve from app root.
                              $profileImg = $appBaseUrl . '/' . ltrim($rawImagePath, '/\\');
                          }
                      } else {
                          $profileImg = $baseUrl . '/assets/default-profile.png';
                      }
                    ?>
                    <img
                      src="<?= htmlspecialchars($profileImg) ?>"
                      alt="Profile"
                      class="rounded student-profile-thumb"
                      data-full-src="<?= htmlspecialchars($profileImg) ?>"
                      style="width:42px;height:42px;object-fit:cover;aspect-ratio:1/1;display:block;border:1px solid #ddd;background:#f8f8f8;cursor:zoom-in;"
                    >
                  </td>
                  <td><?= htmlspecialchars($e['student_number']) ?></td>
                  <td><?= htmlspecialchars($e['full_name']) ?></td>
                  <td><?= htmlspecialchars($e['section'] ?? '') ?></td>
                  <td>
                    <?php if ($e['enrollment_status'] === 'dropped'): ?>
                      <span class="badge bg-danger">Dropped</span>
                    <?php else: ?>
                      <span class="badge bg-success">Enrolled</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="modal fade" id="studentImageZoomModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-transparent border-0 shadow-none">
              <div class="modal-body p-0 text-center position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-2" data-bs-dismiss="modal" aria-label="Close"></button>
                <img id="studentImageZoomPreview" src="" alt="Student profile preview" class="img-fluid rounded" style="max-height: 80vh; object-fit: contain;">
              </div>
            </div>
          </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          var zoomModalEl = document.getElementById('studentImageZoomModal');
          var zoomPreviewEl = document.getElementById('studentImageZoomPreview');
          var zoomModal = zoomModalEl ? new bootstrap.Modal(zoomModalEl) : null;
          document.querySelectorAll('.student-profile-thumb').forEach(function(imgEl) {
              imgEl.addEventListener('click', function() {
                  if (!zoomModal || !zoomPreviewEl) return;
                  zoomPreviewEl.src = this.getAttribute('data-full-src') || this.src;
                  zoomModal.show();
              });
          });

          // Dynamically show available semesters for the selected year
          var yearSel = document.getElementById('school_year_select');
          var semSel = document.getElementById('semester_select');
          if (yearSel && semSel) {
              yearSel.addEventListener('change', function() {
                  for(let i=0;i<semSel.options.length;i++) {
                      semSel.options[i].style.display = (semSel.options[i].dataset.year===yearSel.value)?'':'none';
                  }
                  var displayed = Array.from(semSel.options).filter(o=>o.style.display !== 'none');
                  if (displayed.length) {
                      displayed[0].selected = true;
                  }
              });
              // Auto-hide non-matching semesters on page load
              for(let i=0;i<semSel.options.length;i++) {
                  semSel.options[i].style.display = (semSel.options[i].dataset.year===yearSel.value)?'':'none';
              }
              // Ensure one is selected
              var anyDisplayed = Array.from(semSel.options).find(o => o.style.display !== 'none');
              if(anyDisplayed) anyDisplayed.selected = true;
          }
        });
        </script>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
