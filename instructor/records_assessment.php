<?php
// Included by records.php - uses $enrollments, $termId, $instructorSubjectId, $assessmentActivities, $assessmentRecords, $baseUrl
$typeLabels = [
    'class_participation' => 'Class Participation',
    'quiz' => 'Quiz',
    'project' => 'Project',
    'exam' => 'Exam',
    'lab_exam' => 'Lab exam',
    'graded_activity' => 'Graded Activity',
    'assignment' => 'Assignment',
    'exercise' => 'Exercise',
    'values' => 'Values',
];

// Define color classes for each assessment type (Bootstrap or custom)
$typeColors = [
    'class_participation' => 'bg-info text-dark',
    'quiz'                => 'bg-warning text-dark',
    'project'             => 'bg-success',
    'exam'                => 'bg-danger',
    'lab_exam'            => 'bg-dark',
    'graded_activity'     => 'bg-primary',
    'assignment'          => 'bg-purple text-light', // bg-purple is non-Bootstrap, define below
    'exercise'            => 'bg-teal text-light',   // bg-teal is non-Bootstrap, define below
    'values'              => 'bg-secondary',
];
$recordsByActivity = [];
foreach ($assessmentRecords as $eid => $list) {
    foreach ($list as $r) {
        $aid = $r['activity_id'] ?? 0;
        if ($aid) {
            if (!isset($recordsByActivity[$aid])) $recordsByActivity[$aid] = [];
            $recordsByActivity[$aid][$eid] = $r;
        }
    }
}
?>
<style>
@media (max-width: 576px) {
  #btn-add-activity {
    padding: 0 !important;
    width: 42px !important;
    height: 42px !important;
    border-radius: 50% !important;
    font-size: 20px !important;
    display: flex !important;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    min-height: 42px;
  }
  #btn-add-activity .add-label {
    display: none !important;
  }
  #btn-add-activity .add-icon {
    display: inline !important;
  }
}
@media (min-width: 577px) {
  #btn-add-activity .add-label {
    display: inline !important;
  }
  #btn-add-activity .add-icon {
    display: none !important;
  }
}

/* Custom badge background colors for assessments */
.bg-purple { background-color: #6f42c1 !important; color: #fff !important; }
.bg-teal   { background-color: #20c997 !important; color: #fff !important; }
</style>
<div class="d-flex justify-content-between align-items-center mb-3 p-2" style="margin-bottom: 1.5rem; padding: 1rem;">
  <h5 class="mb-0">Assessment Records (Class Standing)</h5>
  <button type="button" class="btn btn-primary btn-sm" id="btn-add-activity"
    data-instructor-subject-id="<?= (int)$instructorSubjectId ?>"
    data-term-id="<?= (int)$termId ?>">
    <span class="add-icon" style="display: none;"><i class="bi bi-plus-lg"></i></span>
    <span class="add-label">+ Add Assessment</span>
  </button>
</div>

<!-- Assessment list: Date & Time, Assessment type, Max score, Title, Edit, Delete (no inline student scores) -->
<div class="table-responsive mb-4 ">
  <table class="table table-hover mb-0 align-middle">
    <thead class="table-light">
      <tr>
        <th>Date &amp; Time</th>
        <th>Assessment Type</th>
        <th>Max Score</th>
        <th>Title</th>
        <th style="width:120px;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($assessmentActivities)): ?>
        <tr><td colspan="5" class="text-muted text-center py-3">No assessments yet. Click + Add Assessment to create one.</td></tr>
      <?php else: ?>
        <?php foreach ($assessmentActivities as $act):
          $actDate = $act['activity_date'] ?? '';
          $actTime = $act['activity_time'] ?? null;
          $dateTimeStr = $actDate;
          if ($actTime) $dateTimeStr .= ' ' . date('H:i', strtotime($actTime));
          $maxScore = $act['max_score'] ?? 100;

          // Enforce integer for display of max score
          $maxScore = (int)$maxScore;

          $recordType = strtolower(trim((string) ($act['record_type'] ?? 'quiz')));
          $scores = [];
          foreach ($enrollments as $e) {
            $rec = $recordsByActivity[$act['id']][$e['enrollment_id']] ?? null;
            // force integer display even if value is decimal-like
            $scoreVal = $rec ? $rec['score'] : '';
            if ($scoreVal !== '' && $scoreVal !== null && is_numeric($scoreVal))
              $scoreVal = (int)round($scoreVal);
            $scores[$e['enrollment_id']] = $scoreVal !== 0 && empty($scoreVal) ? "" : $scoreVal; // show zero, keep empty if truly empty
          }
          // When a specific section is selected, only show activities that
          // actually have scores for at least one student in that section.
          // This prevents activities for other sections of the same subject
          // from appearing in the current section's assessment list.
          // Exam, lab exam, and values are one per term for the whole class (same instructor_subject_id);
          // they must always show so another section view does not look "empty" while the row still exists.
          $hasAnyScore = false;
          foreach ($scores as $val) {
            if ($val !== '' && $val !== null) {
              $hasAnyScore = true;
              break;
            }
          }
          $termWideSingleton = in_array($recordType, ['exam', 'lab_exam', 'values'], true);
          if (!empty($sectionId) && !$hasAnyScore && !$termWideSingleton) {
            continue;
          }
          // Color coding for assessment type:
          $badgeClass = $typeColors[$recordType] ?? 'bg-secondary';
          $typeLabel = $typeLabels[$recordType] ?? $recordType;
        ?>
          <tr>
            <td><?= htmlspecialchars($dateTimeStr) ?></td>
            <td>
              <span class="badge <?= htmlspecialchars($badgeClass) ?>">
                <?= htmlspecialchars($typeLabel) ?>
              </span>
            </td>
            <td><?= htmlspecialchars($maxScore) ?></td>
            <td ><?= htmlspecialchars($act['title'] ?? '-') ?></td>
            <td>
              <div class="d-flex flex gap-1 py-1 align-items-center justify-content-start">
                <button type="button" class="btn btn-sm btn-outline-primary edit-activity-btn d-flex align-items-center"
                  data-activity-id="<?= $act['id'] ?>"
                  data-title="<?= htmlspecialchars($act['title'] ?? '') ?>"
                  data-record-type="<?= htmlspecialchars($recordType) ?>"
                  data-activity-date="<?= htmlspecialchars($actDate) ?>"
                  data-activity-time="<?= $actTime ? date('H:i', strtotime($actTime)) : '' ?>"
                  data-max-score="<?= (int)$maxScore ?>"
                  data-scores="<?= htmlspecialchars(json_encode($scores)) ?>">
                  <i class="bi bi-pencil"></i>
                  <span class="d-none d-sm-inline ms-1">Edit</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger delete-activity-btn d-flex align-items-center"
                  data-activity-id="<?= $act['id'] ?>">
                  <i class="bi bi-trash"></i>
                  <span class="d-none d-sm-inline ms-1">Delete</span>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (empty($enrollments)): ?>
  <p class="text-muted p-3 mb-0">No students enrolled in this class.</p>
<?php endif; ?>

<!-- Modal: Add/Edit Assessment with student scores - one Save button -->
<div class="modal fade" id="modalAssessmentActivity" tabindex="-1">
  <div class="modal-dialog modal-md" style="margin-top: 4.5rem;">
    <div class="modal-content ">
      <div class="modal-header">
        <h5 class="modal-title" id="modalAssessmentActivityTitle">Add Assessment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="formAssessmentActivity">
          <input type="hidden" id="formActivityAction" name="formActivityAction" value="add_activity">
          <input type="hidden" id="formActivityId" name="formActivityId" value="">
          <input type="hidden" id="formInstructorSubjectId" name="instructor_subject_id" value="<?= (int)$instructorSubjectId ?>">
          <input type="hidden" id="formTermId" name="term_id" value="<?= (int)$termId ?>">
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Date</label>
              <input type="date" id="formActivityDate" name="activity_date" class="form-control" required max="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Time</label>
              <input type="time" id="formActivityTime" name="activity_time" class="form-control">
            </div>
          </div>
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label">Assessment Type</label>
              <select id="formRecordType" name="record_type" class="form-select" required>
                <option value="class_participation">Class Participation</option>
                <option value="quiz">Quiz</option>
                <option value="project">Project</option>
                <option value="exam">Exam</option>
                <option value="lab_exam">Lab exam</option>
                <option value="graded_activity">Graded Activity</option>
                <option value="assignment">Assignment</option>
                <option value="exercise">Exercise</option>
                <option value="values">Values</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Max Score</label>
              <input type="number" id="formMaxScore" name="max_score" class="form-control" min="1" step="1" value="100" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Title <span style="color:red">*</span></label>
              <input type="text" id="formActivityTitle" name="title" class="form-control" required placeholder="" style="border: 2px solid red;">
            </div>
          </div>
          <hr>
          <div class="mb-2 fw-bold d-flex align-items-center">
            <span>Student Scores</span>
            <button type="button" id="btnAutoFillScores" class="btn btn-outline-secondary btn-sm ms-auto" style="margin-left:auto;">
              <i class="bi bi-arrow-down-square"></i> Auto-fill Max Score
            </button>
          </div>
          <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
            <table class="table table-sm">
              <thead><tr><th>Student</th><th style="width:100px;">Score</th></tr></thead>
              <tbody id="assessmentScoresBody"></tbody>
            </table>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSaveAssessmentAll">Save All</button>
      </div>
    </div>
  </div>
</div>
<script>
  // Supports auto-fill on first click, then undo on next, and toggle
  document.addEventListener('DOMContentLoaded', function() {
    var btnAutoFill = document.getElementById('btnAutoFillScores');
    var autofilled = false;
    var originalValues = [];

    function getScoreInputs() {
      return Array.from(document.querySelectorAll('#assessmentScoresBody input[type="number"]'));
    }

    btnAutoFill && btnAutoFill.addEventListener('click', function() {
      var scoreInputs = getScoreInputs();

      if (!autofilled) {
        // Store original values before autofilling
        originalValues = scoreInputs.map(function(input) { return input.value; });
        var max = parseInt(document.getElementById('formMaxScore').value, 10);
        scoreInputs.forEach(function(input) {
          input.value = !isNaN(max) ? max : '';
          input.dispatchEvent(new Event('input'));
        });
        autofilled = true;
        btnAutoFill.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Undo Auto-fill';
      } else {
        // Undo: Restore original values to the inputs
        scoreInputs.forEach(function(input, i) {
          input.value = (typeof originalValues[i] !== "undefined") ? originalValues[i] : '';
          input.dispatchEvent(new Event('input'));
        });
        autofilled = false;
        btnAutoFill.innerHTML = '<i class="bi bi-arrow-down-square"></i> Auto-fill Max Score';
      }
    });

    // Optional: Reset autofill/undo state when modal is closed or opened
    var modal = document.getElementById('modalAssessmentActivity');
    if (modal) {
      modal.addEventListener('show.bs.modal', function() {
        autofilled = false;
        btnAutoFill.innerHTML = '<i class="bi bi-arrow-down-square"></i> Auto-fill Max Score';
      });
      modal.addEventListener('hidden.bs.modal', function() {
        autofilled = false;
        btnAutoFill.innerHTML = '<i class="bi bi-arrow-down-square"></i> Auto-fill Max Score';
        originalValues = [];
      });
    }

    // Only allow integer input for scores
    function restrictScoreInput(e) {
      // Allow: Ctrl/Command keys, backspace, tab, left/right, delete
      var allowedKeys = [
        8,   // backspace
        9,   // tab
        13,  // enter
        27,  // escape
        35,  // end
        36,  // home
        37,  // left
        39,  // right
        46,  // delete
      ];
      if (
        allowedKeys.indexOf(e.keyCode) !== -1 ||
        // Allow: Ctrl/cmd+A, Ctrl/cmd+C, Ctrl/cmd+V, Ctrl/cmd+X, Ctrl/cmd+Z
        ((e.ctrlKey || e.metaKey) && ['a','c','v','x','z'].indexOf(e.key.toLowerCase()) !== -1)
      ) {
        return;
      }

      var char = e.key;
      // Only allow digits (no dot/decimal)
      if (!char.match(/[0-9]/)) {
        e.preventDefault();
        return;
      }
    }

    function handlePaste(e) {
      var paste = (e.clipboardData || window.clipboardData).getData('text');
      // Only digits allowed
      if (!/^\d+$/.test(paste)) {
        e.preventDefault();
      }
    }

    // Attach event listeners to newly created score inputs
    function attachInputRestrictions() {
      var scoreInputs = getScoreInputs();
      scoreInputs.forEach(function(input) {
        // Prevent multiple bindings
        if (input._scoreRestricted) return;
        input.addEventListener('keypress', restrictScoreInput);
        input.addEventListener('paste', handlePaste);
        input.addEventListener('input', function(e) {
          // Remove unwanted characters except digits
          var val = input.value;
          val = val.replace(/[^0-9]/g, '');
          // Remove leading zeros unless just '0'
          if (val.length > 1 && val[0] === '0') {
            val = val.replace(/^0+/, '');
            if (val === '') val = '0';
          }
          input.value = val;
        });

        // The placeholder handling: set default 0, clear to blank on focus/change, restore 0 on blur if left blank
        // At input creation, if value is "", set value to "0" and add a data-defaultzero attr
        if (!input._defaultZeroInitialized) {
          if (input.value === '' || input.value === null) {
            input.value = '0';
            input.setAttribute('data-defaultzero', '1');
          }
          input.addEventListener('focus', function(e) {
            if (input.value === '0' && input.getAttribute('data-defaultzero') === '1') {
              input.value = '';
            }
          });
          input.addEventListener('blur', function(e) {
            if (input.value === '' || input.value === null) {
              input.value = '0';
              input.setAttribute('data-defaultzero', '1');
            } else {
              input.removeAttribute('data-defaultzero');
            }
          });
          input.addEventListener('change', function(e) {
            if (input.value !== '' && input.value !== '0') {
              input.removeAttribute('data-defaultzero');
            }
          });
          input._defaultZeroInitialized = true;
        }

        input._scoreRestricted = true; // Mark as restricted
      });
    }

    // Run initially and whenever score inputs may be added
    attachInputRestrictions();
    var mutationObserver = new MutationObserver(attachInputRestrictions);
    var target = document.getElementById('assessmentScoresBody');
    if (target) {
      mutationObserver.observe(target, { childList: true, subtree: true });
    }

    // Populate the score rows (with default 0) -- for custom rendering
    function renderScoreRows(enrollments, scores) {
      var tbody = document.getElementById('assessmentScoresBody');
      if (!tbody) return;
      tbody.innerHTML = '';
      enrollments.forEach(function(e) {
        var score = typeof scores === 'object' && scores[e.enrollment_id] !== undefined
            ? scores[e.enrollment_id]
            : '';
        var val = (score === null || score === undefined || score === '') ? '0' : score;
        val = parseInt(val, 10).toString(); // Always force integer and string
        var tr = document.createElement('tr');
        var td1 = document.createElement('td');
        td1.textContent = e.full_name + ' (' + e.student_number + ')';
        var td2 = document.createElement('td');
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.step = '1';
        input.className = 'form-control form-control-sm';
        input.name = 'score_' + e.enrollment_id;
        input.value = val;
        td2.appendChild(input);
        tr.appendChild(td1);
        tr.appendChild(td2);
        tbody.appendChild(tr);
      });
      attachInputRestrictions();
    }

    // Hook to external render
    window.renderAssessmentScoreRows = renderScoreRows;
  });
</script>

<script>
window.assessmentEnrollments = <?= json_encode(array_map(function($e) { return ['enrollment_id'=>(int)$e['enrollment_id'],'full_name'=>$e['full_name'],'student_number'=>$e['student_number']]; }, $enrollments)) ?>;
window.assessmentTermId = <?= (int)$termId ?>;
</script>
