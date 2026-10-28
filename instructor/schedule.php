<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../includes/active_academic_year.php';
requireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$db = getDb();
$instructorId = currentInstructorId();

// --- Fetch all available academic years for filtering ---
$allAYs = [];
$stmt = $db->query("SELECT ay.id AS ay_id, ay.school_year, ay.semester 
                    FROM academic_years ay 
                    ORDER BY ay.school_year DESC, 
                        CASE ay.semester
                            WHEN '1st' THEN 1
                            WHEN '2nd' THEN 2
                            WHEN 'Summer' THEN 3
                            ELSE 99 END");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $allAYs[] = [
        'ay_id' => (int)$row['ay_id'],
        'school_year' => $row['school_year'],
        'semester' => $row['semester'],
        'display' => $row['school_year'] . ' — ' . $row['semester'],
    ];
}

// --- Determine selected academic year for viewing (GET param or fallback to active) ---
if (isset($_GET['academic_year_id']) && is_numeric($_GET['academic_year_id'])) {
    $selectedAyId = (int)$_GET['academic_year_id'];
    $selectedAY = null;
    foreach ($allAYs as $ay) {
        if ($ay['ay_id'] === $selectedAyId) {
            $selectedAY = [
                'id' => $ay['ay_id'],
                'school_year' => $ay['school_year'],
                'semester' => $ay['semester'],
            ];
            break;
        }
    }
} else {
    // Default to active AY if available, else first
    $active = getActiveAcademicYear($db);
    $selectedAY = null;
    $selectedAyId = null;
    if ($active) {
        foreach ($allAYs as $ay) {
            if ($ay['school_year'] == $active['school_year'] && $ay['semester'] == $active['semester']) {
                $selectedAY = [
                    'id' => $ay['ay_id'],
                    'school_year' => $ay['school_year'],
                    'semester' => $ay['semester'],
                ];
                $selectedAyId = $ay['ay_id'];
                break;
            }
        }
    }
    if (!$selectedAY && count($allAYs) > 0) {
        $ay = $allAYs[0];
        $selectedAyId = $ay['ay_id'];
        $selectedAY = [
            'id' => $ay['ay_id'],
            'school_year' => $ay['school_year'],
            'semester' => $ay['semester'],
        ];
    }
}

$rows = [];
if ($selectedAY) {
    [$ayWhere, $ayParams] = activeAcademicYearMatch('isub.academic_year_id', 'isub.school_year', 'isub.semester', $selectedAY);
    $stmt = $db->prepare("
      SELECT s.code, s.description, sch.day_of_week, sch.start_time, sch.end_time, sch.room, sch.section_name
      FROM schedule sch
      JOIN instructor_subjects isub ON isub.id = sch.instructor_subject_id
      JOIN subjects s ON s.id = isub.subject_id
      WHERE isub.instructor_id = ?
        AND $ayWhere
      ORDER BY sch.day_of_week, sch.start_time
    ");
    $stmt->execute(array_merge([$instructorId], $ayParams));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$todayDow = (int)date('N'); // 1=Mon .. 7=Sun
$byDay = [];
foreach ($rows as $r) {
    $d = $r['day_of_week'];
    if (!isset($byDay[$d])) $byDay[$d] = [];
    $byDay[$d][] = $r;
}

$pageTitle = 'Schedule';
$currentPage = 'schedule';
ob_start();
?>
<style>
/* ... CSS unchanged ... */
.schedule-container-main {
  width: 100%;
  max-width: 900px;
  margin: 0 auto;
  padding: 0 1rem;
  box-sizing: border-box;
  overflow-x: auto;
}
@media (max-width: 1200px) {
  .schedule-container-main {
    max-width: 960px;
    padding: 0 10px;
  }
}
@media (max-width: 900px) {
  .schedule-container-main {
    max-width: 99vw;
    padding: 0 9px;
  }
}
@media (max-width: 700px) {
  .schedule-container-main {
    max-width: 100vw;
    padding: 0 2vw;
  }
}
@media (max-width: 480px) {
  .schedule-container-main {
    max-width: 100vw;
    padding: 0 4px;
  }
}
.schedule-horizontal {
  display: flex;
  flex-direction: row;
  border-bottom: 1px solid #e0e0e0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  gap: 0;
  max-width: 100vw;
  scrollbar-width: thin;
}
.schedule-horizontal::-webkit-scrollbar {
  height: 7px;
  background: #f0f0f0;
}
.schedule-horizontal::-webkit-scrollbar-thumb {
  background: #c4c4c4;
  border-radius: 5px;
}
.schedule-day-col {
  min-width: 190px;
  max-width: 230px;
  flex: 1 1 210px;
  border-right: 1px solid #e0e0e0;
  padding: 0;
  background: #fff;
  box-sizing: border-box;
}
.schedule-day-col:last-child {
  border-right: none;
}
.schedule-th-header {
  background: #0F3468;
  color: #fff;
  font-weight: 600;
  padding: 0.75rem 1rem 0.5rem 1rem;
  letter-spacing: .01em;
  border-bottom: 1px solid #0F3468;
  margin: 0;
}
.schedule-day-today .schedule-th-header {
  background: #e6ffed;
  color: #198754;
  border-bottom-color: #198754;
}
@media (max-width: 1100px) {
  .schedule-day-col {
    min-width: 145px;
    max-width: 175px;
    flex: 1 1 155px;
  }
}
@media (max-width: 900px) {
  .schedule-container-main {
    max-width: 100vw;
  }
  .schedule-day-col {
    min-width: 38vw;
    max-width: 42vw;
    flex: 0 0 38vw;
  }
}
@media (max-width: 700px) {
  .schedule-day-col {
    min-width: 300px;
    max-width: 95vw;
    flex: 0 0 95vw;
  }
  .schedule-horizontal {
    gap: 0.65rem;
    border-bottom: none;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .schedule-th-header {
    font-size: 1rem;
    border-radius: 0.25rem 0.25rem 0 0;
    border-bottom: none;
  }
  .schedule-horizontal::-webkit-scrollbar {
    height: 5px;
    background: #e5e5e5;
  }
  .schedule-horizontal::-webkit-scrollbar-thumb {
    background: #b8b8b8;
    border-radius: 5px;
  }
}
@media (max-width: 500px) {
  .schedule-container-main {
    padding: 0 2px;
  }
  .schedule-day-col {
    min-width: 94vw;
    max-width: 98vw;
    flex: 0 0 98vw;
    border-right: none;
    margin-right: 0.5rem;
  }
  .schedule-horizontal {
    gap: 0.5rem;
  }
}
</style>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap" style="gap: 0.5rem;">
  <h4 class="mb-0">My Schedule</h4>
  <?php if (count($allAYs) > 1): ?>
    <form method="get" class="d-inline-block" style="min-width:220px;">
      <select name="academic_year_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:205px;">
        <?php foreach ($allAYs as $ay): ?>
          <option value="<?= $ay['ay_id'] ?>"<?= $selectedAyId == $ay['ay_id'] ? ' selected' : '' ?>>
            <?= htmlspecialchars($ay['school_year'] . ' — ' . $ay['semester']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php elseif (count($allAYs) === 1): ?>
    <div class="text-muted small ms-2">(<?= htmlspecialchars($allAYs[0]['school_year'] . ' — ' . $allAYs[0]['semester']) ?>)</div>
  <?php endif; ?>
</div>

<div class="alert alert-info py-1 mb-3 small" style="font-size: 11px;">
  <i class="bi bi-info-circle"></i>
  <strong>Notice:</strong> If you have a conflict in your schedule, please go to the MIS office to request a reschedule of your class.
</div>

<?php if ($selectedAY): ?>
<p class="text-muted small mb-3">Showing schedule for <strong><?= htmlspecialchars($selectedAY['school_year'] . ' ' . $selectedAY['semester']) ?></strong>.</p>
<?php else: ?>
<div class="alert alert-warning mb-3">No academic years found. Please contact MIS to setup semesters.</div>
<?php endif; ?>

<div class="schedule-container-main">
  <div class="card">
    <div class="card-body p-0">
      <?php if (empty($rows)): ?>
        <p class="text-muted p-4 mb-0">No schedule entries for this academic year. Multiple subjects per day will appear here when assigned.</p>
      <?php else: ?>
        <div class="schedule-horizontal" tabindex="0">
          <?php foreach ($days as $dayNum => $dayName): ?>
            <div class="schedule-day-col border-bottom<?= $dayNum === $todayDow ? ' schedule-day-today' : '' ?>" data-day="<?= (int)$dayNum ?>">
              <div class="schedule-th-header">
                <?= $dayName ?>
                <?php if ($dayNum === $todayDow): ?>
                  <span class="badge bg-success ms-2">Today</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($byDay[$dayNum])): ?>
                <div class="px-3 pb-3">
                  <?php foreach ($byDay[$dayNum] as $slot): ?>
                    <div class="mb-1 mt-2">
                      <div class="p-2 rounded bg-light" style="font-family: inherit;">
                        <div class="text-sm">
                          <?= htmlspecialchars($slot['section_name'] ?: 'N/A') ?>
                        </div>
                        <div style="font-size: smaller;">
                          <?= date('g:i A', strtotime($slot['start_time'])) ?> - <?= date('g:i A', strtotime($slot['end_time'])) ?>
                        </div>
                        <div>
                          <strong style="font-family: inherit;"><?= htmlspecialchars($slot['room'] ?: 'N/A') ?></strong>
                        </div>
                        <div>
                          <strong style="font-family: inherit;"><?= htmlspecialchars($slot['code']) ?></strong>
                        </div>
                        <div>
                          <?= htmlspecialchars($slot['description']) ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="px-3 pb-3">
                  <p class="text-muted small mb-0">No class</p>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
          var horiz = document.querySelector('.schedule-horizontal');
          var todayCol = document.querySelector('.schedule-day-col.schedule-day-today');
          if (horiz && todayCol && window.innerWidth <= 768) {
            var offset = todayCol.offsetLeft - 16;
            if (offset < 0) offset = 0;
            horiz.scrollTo({ left: offset, behavior: 'smooth' });
          }
        });
        </script>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';