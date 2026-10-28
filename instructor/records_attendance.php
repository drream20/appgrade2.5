<?php
// Included by records.php - uses $enrollments, $termId, $instructorSubjectId, $attendanceRecords, $baseUrl

// Group attendance by date+time for unique slots (allowing multiple per day if different time)

$attendanceSlots = [];
// We'll collect slots indexed by date, then by time, so we avoid issues with overwrites
foreach ($attendanceRecords as $eid => $records) {
    foreach ($records as $rec) {
        $date = $rec['attendance_date'];
        $time = isset($rec['attendance_time']) && $rec['attendance_time'] !== null && $rec['attendance_time'] !== '' ? $rec['attendance_time'] : null;
        $key = $date;
        if ($time !== null) $key .= '_' . $time;

        if (!isset($attendanceSlots[$key])) {
            $attendanceSlots[$key] = [
                'date' => $date,
                'time' => $time,
                'records' => []
            ];
        }
        // Always merge, don't skip other students' records with same date+time
        $attendanceSlots[$key]['records'][$eid] = $rec;
    }
}
// Sort by date and time descending
uksort($attendanceSlots, function($a, $b) { return strcmp($b, $a); });

$statusLetters = ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'excused' => 'E'];
?>

<div class="p-3">
  <p class="text-muted small mb-3">
    Click Add or Edit to record attendance. Use one Save button to save all records.<br>
    <b>You can record for today or past dates only; future dates are not allowed.</b>
  </p>
  <div class="d-flex justify-content-end align-items-center mb-3">
    <button type="button" class="btn btn-primary btn-sm" id="btn-add-attendance-slot"
      data-instructor-subject-id="<?= (int)$instructorSubjectId ?>"
      data-term-id="<?= (int)$termId ?>">
      + Add Attendance
    </button>
  </div>

  <!-- Attendance list: Date, Time, Edit, Delete (no inline student list) -->
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Time</th>
          <th>Students</th>
          <th class="attendance-actions-col" style="width:200px;">Actions</th>
        </tr>
      </thead>
      <style>
        @media (max-width: 576px) {
          /* On mobile, make the Actions column narrower and font smaller */
          .attendance-actions-col {
            width: 90px !important;
            min-width: 70px;
            font-size: 0.96em;
            padding-left: 0.3rem;
            padding-right: 0.3rem;
          }
        }
      </style>
      
      <style>
.attendance-status-btn { min-width: 36px; }
.attendance-status-btn.status-present { background-color: #198754; color: #fff; border-color: #198754; }
.attendance-status-btn.status-absent { background-color: #dc3545; color: #fff; border-color: #dc3545; }
.attendance-status-btn.status-late { background-color:rgb(53, 187, 220); color: #fff; border-color: rgb(53, 187, 220); }
.attendance-status-btn.status-excused { background-color:rgb(255, 123, 0); color: #fff; border-color: rgb(255, 123, 0); }

/* Responsive adjustments for attendance bulk status buttons in modal */
.attendance-bulk-btn-group .attendance-bulk-btn {
  min-width: 44px;
  padding-left: 1.1em;
  padding-right: 1.1em;
}
@media (max-width: 576px) {
  .attendance-bulk-btn-group {
    flex-direction: row !important;
    flex-wrap: wrap !important;
    gap: 0.5rem !important;
    justify-content: flex-end;
    width: 100%;
  }
  .attendance-bulk-btn-group .attendance-bulk-btn {
    min-width: 36px;
    padding-left: 0.6em;
    padding-right: 0.6em;
    font-size: 1.04em;
  }
  .attendance-bulk-btn-group .btn span.d-sm-inline { display: none !important; }
  .attendance-bulk-btn-group .btn span.d-sm-none { display: inline !important; }
}
</style>
      <tbody>
        <?php if (empty($attendanceSlots)): ?>
          <tr><td colspan="4" class="text-muted text-center py-3">No attendance records yet. Click + Add Attendance to create one.</td></tr>
        <?php else: ?>
          <?php foreach ($attendanceSlots as $key => $slot):
            $statuses = [];
            foreach ($enrollments as $e) {
              $rec = $slot['records'][$e['enrollment_id']] ?? null;
              $statuses[$e['enrollment_id']] = $rec ? $rec['status'] : 'present';
            }
          ?>
            <tr>
              <td><?= htmlspecialchars($slot['date']) ?></td>
              <td><?= $slot['time'] ? htmlspecialchars(date('H:i', strtotime($slot['time']))) : '-' ?></td>
              <td><?= count($slot['records']) ?> / <?= count($enrollments) ?></td>
              <td>
                <div class="d-flex flex-row gap-1 align-items-center justify-content-start">
                  <!-- Responsive buttons for mobile: Only show icon, keep color; desktop: show icon + label -->
                  <button type="button" class="btn btn-sm btn-outline-primary edit-attendance-slot-btn"
                    data-date="<?= htmlspecialchars($slot['date']) ?>"
                    data-time="<?= $slot['time'] ? date('H:i:s', strtotime($slot['time'])) : '' ?>"
                    data-statuses="<?= htmlspecialchars(json_encode($statuses)) ?>">
                    <span class="d-inline d-sm-none"><i class="bi bi-pencil"></i></span>
                    <span class="d-none d-sm-inline"><i class="bi bi-pencil"></i> Edit</span>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger delete-attendance-slot-btn"
                    data-date="<?= htmlspecialchars($slot['date']) ?>"
                    data-time="<?= $slot['time'] ? date('H:i:s', strtotime($slot['time'])) : '' ?>">
                    <span class="d-inline d-sm-none"><i class="bi bi-trash"></i></span>
                    <span class="d-none d-sm-inline"><i class="bi bi-trash"></i> Delete</span>
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (empty($enrollments)): ?>
  <p class="text-muted p-3 mb-0">No students enrolled in this class.</p>
<?php endif; ?>

<!-- Modal: Add/Edit Attendance - student P/A/L/E with one Save -->
<div class="modal fade" id="modalAttendance" tabindex="-1">
  <div class="modal-dialog modal-lg" style="margin-top: 4.5rem; ">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalAttendanceTitle">Add Attendance</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="attendanceMode" value="add">
        <div id="attendanceDateRow" class="row mb-3">
          <div class="col-md-6">
            <label class="form-label">Date</label>
            <input type="date" id="attendanceDate" class="form-control" max="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Time</label>
            <input type="time" id="attendanceTime" class="form-control" value="">
          </div>
        </div>
        <hr>
        <div class="mb-2 fw-bold d-flex align-items-center justify-content-between flex-wrap">
          <span>Student Status (P=Present, A=Absent, L=Late, E=Excused)</span>
          <!-- Bulk status assignment buttons -->
          <div class="attendance-bulk-btn-group d-flex flex-row flex-sm-row flex-wrap gap-1 gap-sm-2 mt-2 mt-sm-0">
            <button type="button" class="btn btn-outline-success btn-sm attendance-bulk-btn" id="attendance-set-all-present" title="Set all Present">
              <span class="d-none d-sm-inline">Present</span>
              <span class="d-inline d-sm-none">P</span>
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm attendance-bulk-btn" id="attendance-set-all-absent" title="Set all Absent">
              <span class="d-none d-sm-inline">Absent</span>
              <span class="d-inline d-sm-none">A</span>
            </button>
            <button type="button" class="btn btn-outline-info btn-sm attendance-bulk-btn" id="attendance-set-all-late" title="Set all Late">
              <span class="d-none d-sm-inline">Late</span>
              <span class="d-inline d-sm-none">L</span>
            </button>
            <button type="button" class="btn btn-outline-warning btn-sm attendance-bulk-btn" id="attendance-set-all-excused" title="Set all Excused">
              <span class="d-none d-sm-inline">Excused</span>
              <span class="d-inline d-sm-none">E</span>
            </button>
          </div>
        </div>
        <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
          <table class="table table-sm">
            <thead><tr><th>Student</th><th style="width:80px;">Status</th></tr></thead>
            <tbody id="attendanceStatusBody"></tbody>
          </table>
        </div>
        <div class="alert alert-info mt-2 d-none" id="attendanceTimeValidation"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnSaveAttendanceAll">Save All</button>
      </div>
    </div>
  </div>
</div>

<script>
window.attendanceEnrollments = <?= json_encode(array_map(function($e) { return ['enrollment_id'=>(int)$e['enrollment_id'],'full_name'=>$e['full_name'],'student_number'=>$e['student_number']]; }, $enrollments)) ?>;
window.attendanceTermId = <?= (int)$termId ?>;
window.attendanceInstructorSubjectId = <?= (int)$instructorSubjectId ?>;

window.attendanceExistingSlots = <?= json_encode(array_values(array_map(function($slot) {
    return [
      'date' => $slot['date'],
      'time' => $slot['time']
    ];
  }, $attendanceSlots)))
?>;

document.addEventListener('DOMContentLoaded', function() {
  // Bulk attendance status assignment
  function setAllAttendanceStatus(status) {
    // Find all status buttons in the modal and set to desired status
    var body = document.getElementById('attendanceStatusBody');
    if (!body) return;
    var buttons = body.querySelectorAll('.attendance-status-btn');
    buttons.forEach(function(btn){
      // Only trigger if not already the active status
      if (!btn.classList.contains('status-' + status)) {
        btn.click();
      }
    });
  }

  document.getElementById('attendance-set-all-present').addEventListener('click', function(){
    setAllAttendanceStatus('present');
  });
  document.getElementById('attendance-set-all-absent').addEventListener('click', function(){
    setAllAttendanceStatus('absent');
  });
  document.getElementById('attendance-set-all-late').addEventListener('click', function(){
    setAllAttendanceStatus('late');
  });
  document.getElementById('attendance-set-all-excused').addEventListener('click', function(){
    setAllAttendanceStatus('excused');
  });
});
</script>
