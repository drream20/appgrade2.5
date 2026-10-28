<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';

studentRequireLogin();
studentRequireProfileComplete();
$baseUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

$db = getDb();
$studentId = currentStudentId();

// 1. Get all academic years/semesters where student has a section
$academicYears = [];
$stmt = $db->prepare("
    SELECT DISTINCT ay.id AS academic_year_id, ay.school_year, ay.semester
    FROM enrollments e
    JOIN class_sections cs ON cs.id = e.class_section_id
    JOIN academic_years ay ON ay.id = cs.academic_year_id
    WHERE e.student_id = ?
    ORDER BY ay.school_year DESC, ay.semester DESC
");
$stmt->execute([$studentId]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $academicYears[] = $row;
}

// 2. Select academic_year_id for dropdown/selection
$selectedAYId = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : null;
if (!$selectedAYId && !empty($academicYears)) {
    $selectedAYId = $academicYears[0]['academic_year_id'];
}
$selectedAY = null;
foreach ($academicYears as $ay) {
    if ((int)$ay['academic_year_id'] === (int)$selectedAYId) {
        $selectedAY = $ay;
        break;
    }
}

// 3. Get the schedule for the student's enrolled subjects for the selected academic year
$rows = [];
if ($selectedAY) {
    $stmt = $db->prepare("
      SELECT DISTINCT
          s.code,
          s.title,
          sch.day_of_week,
          sch.start_time,
          sch.end_time,
          sch.room,
          sch.section_name,
          cs.section AS cs_section,
          ay.school_year,
          ay.semester,
          i.full_name AS instructor_name
      FROM enrollments e
      JOIN class_sections cs ON cs.id = e.class_section_id
      JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
      JOIN schedule sch ON sch.instructor_subject_id = isub.id AND sch.section_name = cs.section
      JOIN academic_years ay ON ay.id = cs.academic_year_id
      LEFT JOIN subjects s ON s.id = isub.subject_id
      LEFT JOIN instructors i ON i.id = isub.instructor_id
      WHERE e.student_id = ?
        AND cs.academic_year_id = ?
      ORDER BY sch.day_of_week, sch.start_time
    ");
    $stmt->execute([$studentId, $selectedAYId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 5. Organize schedule by day
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$byDay = [];
foreach ($rows as $r) {
    $d = $r['day_of_week'];
    if (!isset($byDay[$d])) $byDay[$d] = [];
    $byDay[$d][] = $r;
}
$todayDayNum = (int)date('N');

$pageTitle = 'Schedule';
$currentPage = 'schedule';
ob_start();
?>
<style>
.schedule-table-responsive {
  width: 100%;
  overflow-x: auto;
  position: relative;
}
.schedule-table {
  min-width: 700px;
  border-collapse: separate;
  border-spacing: 0;
}
.schedule-table th,
.schedule-table td {
  white-space: nowrap;
  padding: 0.5rem 0.75rem;
  vertical-align: top;
}
.schedule-table th {
  background: #0F3468 !important;
  color: #fff !important;
  border-bottom: 2px solid #e5e7eb;
  font-weight: 600;
  text-align: center;
}
.schedule-table th.day-today {
  color:rgb(231, 252, 0) !important;
}
.schedule-table td {
  border-bottom: 1px solid #efefef;
}
.schedule-day-col {
  vertical-align: top;
  min-width: 220px;
  width: 100%;
}
@media (max-width: 767.98px) {
  .schedule-table { min-width: 600px; }
}
</style>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">My Schedule</h4>
  <?php if (!empty($academicYears)): ?>
  <form method="get" class="d-inline-block" style="margin-left:auto;">
    <div class="input-group">
      <select name="academic_year_id" class="form-select" onchange="this.form.submit()">
        <?php foreach ($academicYears as $ay): ?>
          <option
            value="<?= (int)$ay['academic_year_id'] ?>"
            <?= ((int)$selectedAYId === (int)$ay['academic_year_id']) ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($ay['school_year']) ?> — <?= htmlspecialchars($ay['semester']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary" type="submit">Go</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <p class="text-muted p-4 mb-0">No schedule entries. Your classes will appear here when assigned.</p>
    <?php else: ?>
      <div class="schedule-table-responsive">
        <table class="table schedule-table mb-0">
          <thead>
            <tr>
              <?php foreach ($days as $dayNum => $dayName): ?>
                <th<?= ($dayNum === $todayDayNum ? ' class="day-today"' : '') ?>><?= $dayName ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <tr>
              <?php foreach ($days as $dayNum => $dayName): ?>
                <td class="schedule-day-col align-top">
                  <?php if (!empty($byDay[$dayNum])): ?>
                    <div class="d-flex flex-column gap-2 h-100 justify-content-start align-items-stretch">
                      <?php foreach ($byDay[$dayNum] as $slot): ?>
                        <div class="d-flex flex-column p-2 rounded bg-light mb-2 h-100 justify-content-start align-items-stretch">
                          <div>
                            <strong><?= htmlspecialchars($slot['code']) ?></strong>
                          </div>
                          <div class="small text-muted mb-1">
                            Section: <?= htmlspecialchars($slot['section_name'] ?: '') ?>
                          </div>
                          <?php if (!empty($slot['room'])): ?>
                            <div class="small mb-1">Room: <?= htmlspecialchars($slot['room']) ?></div>
                          <?php endif; ?>
                          <div class="text-nowrap small text-muted mb-2">
                            <?= date('g:i A', strtotime($slot['start_time'])) ?> - <?= date('g:i A', strtotime($slot['end_time'])) ?>
                          </div>
                          <?php if (!empty($slot['instructor_name'])): ?>
                            <div class="small text-secondary">Instructor: <?= htmlspecialchars($slot['instructor_name']) ?></div>
                          <?php else: ?>
                            <div class="small text-danger">Instructor: Not yet assigned</div>
                          <?php endif; ?>
                          <?php if (!empty($slot['school_year'])): ?>
                            <div class="small mb-1" style="color:rgb(173, 173, 173);">
                              Year: <?= htmlspecialchars($slot['school_year']) ?>, Semester: <?= htmlspecialchars($slot['semester']) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-muted small">No class</span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
