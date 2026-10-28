<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/grades.php';
require __DIR__ . '/includes/academic_year.php';
require __DIR__ . '/../includes/active_academic_year.php';
require_once __DIR__ . '/includes/subject_code_lab.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();

// Active academic term (system-wide)
$selectedAY = getActiveAcademicYear($db);
$academicYears = $selectedAY ? [$selectedAY] : [];
$selectedAyId = $selectedAY ? (int)$selectedAY['id'] : null;

// Instructor's subjects (with academic_year_id for filtering by semester)
$stmt = $db->prepare("
  SELECT isub.id as instructor_subject_id, s.id as subject_id, s.code, s.title, s.description, isub.school_year, isub.semester, isub.academic_year_id
  FROM instructor_subjects isub
  JOIN subjects s ON s.id = isub.subject_id
  WHERE isub.instructor_id = ?
  ORDER BY s.code, isub.school_year, isub.semester
");
$stmt->execute([$instructorId]);
$instructorSubjectsAll = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Filter by selected academic year
$instructorSubjects = [];
if ($selectedAY) {
    foreach ($instructorSubjectsAll as $is) {
        $match = (int)($is['academic_year_id'] ?? 0) === $selectedAyId
            || (empty($is['academic_year_id']) && $is['school_year'] === $selectedAY['school_year'] && $is['semester'] === $selectedAY['semester']);
        if ($match && !instructor_subject_code_is_lab_suffix($is['code'] ?? '')) {
            $instructorSubjects[] = $is;
        }
    }
} else {
    $instructorSubjects = [];
}

// Sections grouped per instructor subject (for display and navigation)
$sectionsByInstructorSubject = [];
if (!empty($instructorSubjects)) {
    $isubIds = array_column($instructorSubjects, 'instructor_subject_id');
    $placeholders = implode(',', array_fill(0, count($isubIds), '?'));
    $stmt = $db->prepare("
      SELECT id, instructor_subject_id, section, year_level
      FROM class_sections
      WHERE instructor_subject_id IN ($placeholders)
      ORDER BY year_level, section
    ");
    $stmt->execute($isubIds);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sectionsByInstructorSubject[$row['instructor_subject_id']][] = $row;
    }
}

$terms = [];
$stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $terms[$row['id']] = $row['name'];

$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int)$_GET['instructor_subject_id'] : null;
$termId = isset($_GET['term_id']) ? (int)$_GET['term_id'] : null;
$recordType = $_GET['type'] ?? 'assessment'; // assessment | attendance
$sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : null;

if ($instructorSubjectId) {
    $foundInList = false;
    foreach ($instructorSubjects as $is) {
        if ((int) $is['instructor_subject_id'] === $instructorSubjectId) {
            $foundInList = true;
            break;
        }
    }
    if (!$foundInList) {
        $stmt = $db->prepare('SELECT s.code FROM instructor_subjects isub JOIN subjects s ON s.id = isub.subject_id WHERE isub.id = ? AND isub.instructor_id = ? LIMIT 1');
        $stmt->execute([$instructorSubjectId, $instructorId]);
        $cr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cr || instructor_subject_code_is_lab_suffix($cr['code'] ?? '')) {
            header('Location: ' . $baseUrl . '/records.php');
            exit;
        }
    }
}

$canRecord = true;
$academicYearStatus = null;
if ($instructorSubjectId) {
    $academicYearStatus = getAcademicYearStatusForInstructorSubject($db, $instructorSubjectId);
    if ($termId) {
        $canRecord = canRecordTermForInstructorSubject($db, $instructorSubjectId, $termId);
    } else {
        $canRecord = canRecordForInstructorSubject($db, $instructorSubjectId);
    }
}

$enrollments = [];
$subjectInfo = null;
$sections = [];
$currentSectionLabel = null;
if ($instructorSubjectId) {
    foreach ($instructorSubjects as $is) {
        if ((int)$is['instructor_subject_id'] === $instructorSubjectId) {
            $subjectInfo = $is;
            break;
        }
    }
    if ($subjectInfo) {
        // Sections handled under this instructor subject (if any)
        $stmt = $db->prepare("
          SELECT id, section, year_level
          FROM class_sections
          WHERE instructor_subject_id = ?
          ORDER BY year_level, section
        ");
        $stmt->execute([$instructorSubjectId]);
        $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($sectionId && !empty($sections)) {
            foreach ($sections as $sec) {
                if ((int)$sec['id'] === $sectionId) {
                    $currentSectionLabel = ($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section'];
                    break;
                }
            }
        }

        // Base enrollments (all students under this instructor subject & its sections)
        $params = [$instructorSubjectId, $instructorSubjectId];
        $sectionFilterSql = '';
        if ($sectionId) {
            // When a specific section is selected, limit enrollments to that class section
            $sectionFilterSql = " AND e.class_section_id = ? ";
            $params[] = $sectionId;
        }
        $sql = "
        SELECT e.id as enrollment_id, st.id as student_id, st.student_id as student_number, st.full_name
        FROM enrollments e
        JOIN students st ON st.id = e.student_id
        WHERE (e.instructor_subject_id = ?
           OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?))
          AND e.status = 'enrolled'
        $sectionFilterSql
        ORDER BY st.full_name
      ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch existing records when subject + term selected
$assessmentRecords = [];
$assessmentActivities = [];
$performanceRecords = [];
$attendanceRecords = [];
if ($instructorSubjectId && $termId && !empty($enrollments)) {
    $eids = array_column($enrollments, 'enrollment_id');
    $placeholders = implode(',', array_fill(0, count($eids), '?'));
    $params = array_merge($eids, [$termId]);
    // Assessment activities (list view: date, time, description)
    try {
        $stmt = $db->prepare("SELECT * FROM assessment_activities WHERE instructor_subject_id = ? AND term_id = ? ORDER BY activity_date DESC, activity_time DESC, id DESC");
        $stmt->execute([$instructorSubjectId, $termId]);
        $assessmentActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table may not exist yet; run database/migration_assessment_activities.sql
    }
    $stmt = $db->prepare("SELECT * FROM assessment_records WHERE enrollment_id IN ($placeholders) AND term_id = ? ORDER BY recorded_at, id");
    $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $assessmentRecords[$r['enrollment_id']][] = $r;
    }
    $stmt = $db->prepare("SELECT * FROM performance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? ORDER BY recorded_at, id");
    $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $performanceRecords[$r['enrollment_id']][] = $r;
    }
    try {
        $stmt = $db->prepare("SELECT * FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? ORDER BY attendance_date, attendance_time");
        $stmt->execute($params);
    } catch (PDOException $e) {
        $stmt = $db->prepare("SELECT * FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? ORDER BY attendance_date");
        $stmt->execute($params);
    }
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attendanceRecords[$r['enrollment_id']][] = $r;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && currentInstructorId()) {
    require __DIR__ . '/includes/records_post.php';
}

$pageTitle = 'Class Records';
$currentPage = 'records';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap" style="gap: 0.5rem;">
  <h4 class="mb-0">Class Records</h4>
</div>
<div class="alert alert-info py-2 mb-3 small" style="font-size: 11px;">
  <i class="bi bi-info-circle "></i>
  Please ensure you select the correct academic year, semester, section, and term before recording or editing grades and attendance.
  All changes will apply only to the selected class and grading period.
  Subjects whose code ends in <strong>L</strong> (laboratory) appear under <a href="<?= htmlspecialchars($baseUrl) ?>/schedule.php">Schedule</a> only—record grades and attendance on the matching lecture subject (without the trailing L).
</div>

<?php if (!$instructorSubjectId && !$termId): ?>
  <div class="row g-4">
    <?php if (!empty($instructorSubjects)): ?>
      <?php foreach ($instructorSubjects as $subj): ?>
        <?php
          $subjSections = $sectionsByInstructorSubject[$subj['instructor_subject_id']] ?? [];
          if (!empty($subjSections)):
            foreach ($subjSections as $sec):
              $secLabel = ($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section'];
        ?>
              <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-primary">
                  <div class="card-body d-flex flex-column justify-content-between h-100">
                    <div>
                      <h5 class="card-title mb-1">
                        <?= htmlspecialchars($subj['code']) ?> - <?= htmlspecialchars($subj['description']) ?>
                      </h5>
                      <?php if ($subj['school_year']): ?>
                        <div class="mb-1 text-secondary small">
                          <?= htmlspecialchars($subj['school_year'] . ' ' . $subj['semester']) ?>
                        </div>
                      <?php endif ?>
                      <div class="small fw-semibold">
                        Section: <?= htmlspecialchars($secLabel) ?>
                      </div>
                    </div>
                    <div>
                      <!-- <label class="form-label small mt-1 ps-1">Go to Term:</label> -->
                      <div class="d-flex flex-wrap gap-2 mb-2 mt-1">
                        <?php foreach ($terms as $tid => $tname): ?>
                          <a class="btn btn-sm btn-outline-primary"
                             href="<?= $baseUrl ?>/records.php?instructor_subject_id=<?= $subj['instructor_subject_id'] ?>&section_id=<?= $sec['id'] ?>&term_id=<?= $tid ?>">
                            <?= htmlspecialchars($tname) ?>
                          </a>
                        <?php endforeach; ?>
                      </div>
                      <div class="d-flex gap-2 flex-wrap">
                        <a
                          href="<?= $baseUrl ?>/download_all_terms_grades.php?instructor_subject_id=<?= (int)$subj['instructor_subject_id'] ?>&section_id=<?= (int)$sec['id'] ?>"
                          class="btn btn-sm btn-outline-success btn-download-all-terms-grades"
                          data-instructor-subject-id="<?= (int)$subj['instructor_subject_id'] ?>"
                          data-section-id="<?= (int)$sec['id'] ?>"
                        >
                          <i class="bi bi-download"></i> All terms
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= $baseUrl ?>/excel_upload.php?instructor_subject_id=<?= $subj['instructor_subject_id'] ?>">
                          <i class="bi bi-upload me-1"></i> Upload Excel
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-md-6 col-lg-4">
              <div class="card h-100 border-primary">
                <div class="card-body d-flex flex-column justify-content-between h-100">
                  <div>
                    <h5 class="card-title mb-1">
                      <?= htmlspecialchars($subj['code']) ?> - <?= htmlspecialchars($subj['description']) ?>
                    </h5>
                    <?php if ($subj['school_year']): ?>
                      <div class="mb-2 text-secondary small">
                        <?= htmlspecialchars($subj['school_year'] . ' ' . $subj['semester']) ?>
                      </div>
                    <?php endif ?>
                    <div class="small text-muted">No assigned section</div>
                  </div>
                  <div>
                    <label class="form-label small mt-3 ps-1">Go to Term:</label>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                      <?php foreach ($terms as $tid => $tname): ?>
                        <a class="btn btn-sm btn-outline-primary"
                           href="<?= $baseUrl ?>/records.php?instructor_subject_id=<?= $subj['instructor_subject_id'] ?>&term_id=<?= $tid ?>">
                          <?= htmlspecialchars($tname) ?>
                        </a>
                      <?php endforeach; ?>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                      <a
                        href="<?= $baseUrl ?>/download_all_terms_grades.php?instructor_subject_id=<?= (int)$subj['instructor_subject_id'] ?>"
                        class="btn btn-sm btn-outline-success btn-download-all-terms-grades"
                        data-instructor-subject-id="<?= (int)$subj['instructor_subject_id'] ?>"
                        data-section-id="0"
                      >
                        <i class="bi bi-download"></i> Download all terms
                      </a>
                      <a class="btn btn-sm btn-outline-secondary" href="<?= $baseUrl ?>/excel_upload.php?instructor_subject_id=<?= $subj['instructor_subject_id'] ?>">
                        <i class="bi bi-upload me-1"></i> Upload Excel
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          <?php endif; ?>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="col-12">
        <div class="alert alert-info mb-0">No subjects found for this instructor.</div>
      </div>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="card mb-4">
    <div class="card-body">
      <?php if ($selectedAY): ?>
      <div class="alert alert-info py-2 mb-3 small">
        Semester: <strong><?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) ?></strong>.
        <?= !empty($selectedAY['is_locked']) ? ' <span class="text-danger">Locked — you can view but not edit records.</span>' : ' Unlocked — recording allowed.' ?>
      </div>
      <?php endif; ?>
      <form id="auto-record-form" method="get" action="" class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Active Term</label>
          <input type="text" class="form-control" value="<?= $selectedAY ? htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) : '' ?>" readonly>
        </div>
        <div class="col-md-4">
          <label class="form-label">Subject</label>
          <select name="instructor_subject_id" class="form-select" required id="filter-subject">
            <option value="">-- Select subject --</option>
            <?php foreach ($instructorSubjects as $is): ?>
              <option value="<?= $is['instructor_subject_id'] ?>" <?= $instructorSubjectId === (int)$is['instructor_subject_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($is['code'] . ' - ' . $is['description']) ?>
                <?= $is['school_year'] ? ' (' . htmlspecialchars($is['school_year'] . ' ' . $is['semester']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($sections)): ?>
        <div class="col-md-3">
          <label class="form-label">Section</label>
          <select name="section_id" class="form-select" id="filter-section">
            <option value=""><?= htmlspecialchars('All handled sections') ?></option>
            <?php foreach ($sections as $sec): ?>
              <option value="<?= $sec['id'] ?>" <?= $sectionId === (int)$sec['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars(($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3">
          <label class="form-label">Term</label>
          <select name="term_id" class="form-select" required id="filter-term">
            <option value="">-- Select term --</option>
            <?php foreach ($terms as $tid => $tname): ?>
              <option value="<?= $tid ?>" <?= $termId === (int)$tid ? 'selected' : '' ?>><?= htmlspecialchars($tname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Record type</label>
          <div class="d-flex gap-3 flex-wrap flex-md-nowrap w-100 align-items-end" id="filter-type-group">
            <?php
              $queryParams = $_GET;
              $queryParams['type'] = 'assessment';
              $assessmentLink = $baseUrl . '/records.php?' . http_build_query($queryParams);

              $queryParams['type'] = 'attendance';
              $attendanceLink = $baseUrl . '/records.php?' . http_build_query($queryParams);
            ?>
            <a href="<?= htmlspecialchars($assessmentLink) ?>"
              class="btn btn-outline-primary custom-type-btn btn-block <?= $recordType === 'assessment' ? 'active' : '' ?>"
              id="btn-record-type-assessment"
            >
              <span class="d-flex align-items-center justify-content-center flex-grow-1"
                style="font-size: 1.09rem;">
                <i class="bi bi-file-earmark-text me-2"></i>
                Assessment
              </span>
            </a>
            <a href="<?= htmlspecialchars($attendanceLink) ?>"
              class="btn btn-outline-primary custom-type-btn btn-block <?= $recordType === 'attendance' ? 'active' : '' ?>"
              id="btn-record-type-attendance"
            >
              <span class="d-flex align-items-center justify-content-center flex-grow-1"
                style="font-size: 1.09rem;">
                <i class="bi bi-calendar-check me-2"></i>
                Attendance
              </span>
            </a>
          </div>
        </div>
        <!-- Removed the Load button per requirements -->
      </form>
      <style>
        .custom-type-btn {
          min-width: 100%;
          max-width: 100%;
          height: calc(2.2rem + 2px); /* .form-select default height in Bootstrap 5 */
          font-size: 1.10rem;
          font-weight: 500;
          border-radius: 5px;
          transition: 0.17s;
          box-shadow: none;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 0.6em 1.2em;
        }
        .custom-type-btn.active, .custom-type-btn:active, .custom-type-btn:focus {
          background: #1351a2 !important;
          color: #fff !important;
          border-color: #1351a2 !important;
        }
        .custom-type-btn:not(.active):hover {
          background: #e7f0fa !important;
          color: #1351a2 !important;
          border-color: #1351a2 !important;
        }
        #filter-type-group {
          min-width: 100%;
          width: 100%;
        }
        #filter-type-group .btn {
          width: 49%;
          /* On web (>767px) display side by side and match the width of selects */
        }
        @media (max-width: 991.98px) {
          #filter-type-group .btn {
            width: 100%;
            margin-bottom: px;
          }
        }
        @media (max-width: 767.98px) {
          #filter-type-group {
            flex-direction: column !important;
            gap: 0.75rem !important;
            min-width: unset;
            width: 100%;
          }
          .custom-type-btn {
            min-width: 100% !important;
            max-width: 100% !important;
            width: 100% !important;
            font-size: 1rem;
            height: calc(2.25rem + 2px); /* for mobile, Bootstrap md select */
            padding: 0.47em 0.75em;
          }
          #filter-type-group .btn {
            width: 100% !important;
          }
        }
        @media (max-width: 470px) {
          #filter-type-group {
            flex-direction: column !important;
            align-items: stretch !important;
          }
          .custom-type-btn {
            min-width: 100%;
            width: 100%;
            justify-content: flex-start;
            font-size: 0.99rem;
          }
        }
      </style>
      <script>
        // This JS will auto-submit the filter form onchange of any select.
        document.addEventListener('DOMContentLoaded', function () {
          var form = document.getElementById('auto-record-form');
          var selects = form.querySelectorAll('select');
          selects.forEach(function(sel) {
            sel.addEventListener('change', function () {
              form.submit();
            });
          });
        });
      </script>

      <?php if ($instructorSubjectId && $termId && !$canRecord): ?>
        <div class="alert alert-warning mt-3 mb-0">
          <i class="bi bi-lock-fill me-2"></i>
          <?php if ($academicYearStatus && !empty($academicYearStatus['is_locked'])): ?>
            This semester is locked; students can view records but instructors cannot edit.
          <?php elseif ($academicYearStatus && $termId >= 1 && $termId <= 3 && !empty($academicYearStatus['lock_term_' . $termId])): ?>
            The <?= htmlspecialchars($terms[$termId] ?? 'Term') ?> period is locked by MIS. You can view records but not edit for this term.
          <?php else: ?>
            Recording disabled. Contact MIS for academic year setup.
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($instructorSubjectId && $termId): ?>
        <?php $sectionQuery = $sectionId ? '&section_id=' . (int)$sectionId : ''; ?>
        <div class="d-flex gap-2 mt-3">
          <a href="<?= $baseUrl ?>/download_record.php?instructor_subject_id=<?= $instructorSubjectId ?>&term_id=<?= $termId ?><?= $sectionQuery ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Download this term</a>
          <a href="<?= $baseUrl ?>/download_term_below_76.php?instructor_subject_id=<?= $instructorSubjectId ?>&term_id=<?= $termId ?><?= $sectionQuery ?>" class="btn btn-outline-danger btn-sm" title="Students with term grade below 76%"><i class="bi bi-download me-1"></i>Below 76% (this term)</a>
          <a href="<?= $baseUrl ?>/excel_upload.php?instructor_subject_id=<?= $instructorSubjectId ?>&term_id=<?= $termId ?><?= $sectionQuery ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-upload me-1"></i>Upload Excel</a>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($subjectInfo && $termId): ?>
    <div class="card">
      <div class="card-header bg-white">
        <strong><?= htmlspecialchars($subjectInfo['code'] . ' - ' . $subjectInfo['description']) ?></strong>
        — <?= htmlspecialchars($terms[$termId] ?? '') ?>
        — <?= $recordType === 'attendance' ? 'Attendance' : 'Assessment' ?>
        <?php if ($currentSectionLabel): ?>
          — <?= htmlspecialchars($currentSectionLabel) ?>
        <?php elseif ($sectionId === null && !empty($sections)): ?>
          — All handled sections
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($recordType === 'attendance'): ?>
          <?php include __DIR__ . '/records_attendance.php'; ?>
        <?php else: ?>
          <?php include __DIR__ . '/records_assessment.php'; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php elseif ($instructorSubjectId || $termId): ?>
    <p class="text-muted">Select both subject and term.</p>
  <?php endif; ?>

<?php endif; ?>

<div class="modal fade" id="allTermsSignatoriesModal" tabindex="-1" aria-labelledby="allTermsSignatoriesModalLabel" aria-hidden="true">
  <div class="modal-dialog" style="margin-top: 5rem;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="allTermsSignatoriesModalLabel">Signatory Names (All Terms Download)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="allTermsInstructorSubjectId" value="">
        <input type="hidden" id="allTermsSectionId" value="0">

        <div class="mb-3">
          <label for="allTermsExecutiveDeanName" class="form-label">Executive Dean</label>
          <input type="text" class="form-control" id="allTermsExecutiveDeanName" value="DR. LYLANI S. CLARO">
        </div>

        <div class="mb-3">
          <label for="allTermsVpAcademicAffairsName" class="form-label">Vice President for Academic Affairs</label>
          <input type="text" class="form-control" id="allTermsVpAcademicAffairsName" value="DR. RAQUEL T. STA. INES.">
        </div>

        <div class="mb-1">
          <label for="allTermsProgramChairpersonName" class="form-label">Program Chairperson</label>
          <input type="text" class="form-control" id="allTermsProgramChairpersonName" value="MR. JOE MARIEL MEJIA">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="allTermsSaveSignatoriesBtn">Save Names</button>
        <button type="button" class="btn btn-success" id="allTermsDownloadBtn">
          <i class="bi bi-download me-1"></i>Download
        </button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var modalEl = document.getElementById('allTermsSignatoriesModal');
  if (!modalEl) return;
  var modal = new bootstrap.Modal(modalEl);

  var defaultExecDean = 'DR. LYLANI S. CLARO';
  var defaultVpAcademic = 'DR. RAQUEL T. STA. INES.';
  var defaultProgramChair = 'MR. JOE MARIEL MEJIA';

  var keyExecDean = 'instructor_all_terms_exec_dean';
  var keyVpAcademic = 'instructor_all_terms_vp_academic_affairs';
  var keyProgramChair = 'instructor_all_terms_program_chairperson';

  var execDeanInput = document.getElementById('allTermsExecutiveDeanName');
  var vpAcademicInput = document.getElementById('allTermsVpAcademicAffairsName');
  var programChairInput = document.getElementById('allTermsProgramChairpersonName');
  var instructorSubjectIdInput = document.getElementById('allTermsInstructorSubjectId');
  var sectionIdInput = document.getElementById('allTermsSectionId');
  var saveBtn = document.getElementById('allTermsSaveSignatoriesBtn');
  var downloadBtn = document.getElementById('allTermsDownloadBtn');

  function loadSavedNames() {
    var savedExec = localStorage.getItem(keyExecDean);
    var savedVp = localStorage.getItem(keyVpAcademic);
    var savedChair = localStorage.getItem(keyProgramChair);

    execDeanInput.value = (savedExec && savedExec.trim() !== '') ? savedExec : defaultExecDean;
    vpAcademicInput.value = (savedVp && savedVp.trim() !== '') ? savedVp : defaultVpAcademic;
    programChairInput.value = (savedChair && savedChair.trim() !== '') ? savedChair : defaultProgramChair;
  }

  function saveNamesToStorage() {
    var execDean = (execDeanInput.value || '').trim();
    var vpAcademic = (vpAcademicInput.value || '').trim();
    var programChair = (programChairInput.value || '').trim();

    localStorage.setItem(keyExecDean, execDean !== '' ? execDean : defaultExecDean);
    localStorage.setItem(keyVpAcademic, vpAcademic !== '' ? vpAcademic : defaultVpAcademic);
    localStorage.setItem(keyProgramChair, programChair !== '' ? programChair : defaultProgramChair);
  }

  document.querySelectorAll('.btn-download-all-terms-grades').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      instructorSubjectIdInput.value = this.dataset.instructorSubjectId || '';
      sectionIdInput.value = this.dataset.sectionId || '0';
      loadSavedNames();
      modal.show();
    });
  });

  saveBtn && saveBtn.addEventListener('click', function () {
    saveNamesToStorage();
  });

  downloadBtn && downloadBtn.addEventListener('click', function () {
    saveNamesToStorage();

    var instructorSubjectId = (instructorSubjectIdInput.value || '').trim();
    var sectionId = (sectionIdInput.value || '0').trim();

    if (!instructorSubjectId) return;

    var url = "<?= $baseUrl ?>/download_all_terms_grades.php?instructor_subject_id=" + encodeURIComponent(instructorSubjectId);
    if (sectionId && parseInt(sectionId, 10) > 0) {
      url += "&section_id=" + encodeURIComponent(sectionId);
    }

    url += "&executive_dean=" + encodeURIComponent((execDeanInput.value || '').trim());
    url += "&vp_academic_affairs=" + encodeURIComponent((vpAcademicInput.value || '').trim());
    url += "&program_chairperson=" + encodeURIComponent((programChairInput.value || '').trim());

    modal.hide();
    window.location.href = url;
  });
});
</script>

<?php
$content = ob_get_clean();
$currentSectionJs = ($sectionId ?? null) ? (int)$sectionId : 'null';
$footerScripts = '<script>window.canRecord = ' . ($canRecord ? 'true' : 'false') . ';window.currentSectionId = ' . $currentSectionJs . ';</script><script src="assets/js/records.js"></script>';
require __DIR__ . '/includes/layout.php';
