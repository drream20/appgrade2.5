<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/subject_code_lab.php';
requireLogin();

$db = getDb();
$instructorId = currentInstructorId();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

// Sections handled by this instructor (for filter)
$sections = [];
try {
    $stmt = $db->prepare("
        SELECT cs.id, cs.section, cs.year_level, cs.department, s.code AS subject_code
        FROM class_sections cs
        INNER JOIN instructor_subjects isub ON cs.instructor_subject_id = isub.id
        INNER JOIN subjects s ON isub.subject_id = s.id
        WHERE isub.instructor_id = ?
        ORDER BY s.code, cs.year_level, cs.section
    ");
    $stmt->execute([$instructorId]);
    $sections = array_values(array_filter(
        $stmt->fetchAll(PDO::FETCH_ASSOC),
        static function ($row) {
            return !instructor_subject_code_is_lab_suffix($row['subject_code'] ?? '');
        }
    ));
} catch (Throwable $e) {
    $sections = [];
}

// Terms (optional filter)
$terms = [];
try {
    $stmt = $db->query("SELECT id, name FROM terms ORDER BY sort_order");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $terms[(int)$r['id']] = $r['name'];
    }
} catch (Throwable $e) {
    $terms = [];
}

// Filters: by default, no date limit (user can optionally specify range)
$sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int)$_GET['section_id'] : null;
$termId = isset($_GET['term_id']) && $_GET['term_id'] !== '' ? (int)$_GET['term_id'] : null;
$dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : '';

if ($dateFrom && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$where = [
    "isub.instructor_id = :iid",
];
$params = [
    ':iid' => $instructorId,
];

if ($sectionId) {
    $where[] = "cs.id = :sectionId";
    $params[':sectionId'] = $sectionId;
}
if ($termId) {
    $where[] = "ar.term_id = :termId";
    $params[':termId'] = $termId;
}
if ($dateFrom) {
    $where[] = "ar.attendance_date >= :dateFrom";
    $params[':dateFrom'] = $dateFrom;
}
if ($dateTo) {
    $where[] = "ar.attendance_date <= :dateTo";
    $params[':dateTo'] = $dateTo;
}

$sql = "
    SELECT
      ar.attendance_date,
      ar.attendance_time,
      ar.status,
      ar.remarks,
      st.student_id,
      st.full_name,
      s.code AS subject_code,
      cs.section AS section_name,
      cs.year_level,
      cs.department,
      ar.term_id
    FROM attendance_records ar
      INNER JOIN enrollments e ON e.id = ar.enrollment_id
      INNER JOIN students st ON st.id = e.student_id
      LEFT JOIN class_sections cs ON e.class_section_id = cs.id
      LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
      LEFT JOIN subjects s ON isub.subject_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ar.attendance_date DESC, ar.attendance_time DESC, st.full_name
    LIMIT 1000
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Attendance History';
$currentPage = 'attendance_history';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap" style="gap:.5rem;">
  <h4 class="mb-0">Attendance History (All Classes)</h4>
  <span class="text-muted small">View attendance for all handled sections without date limits.</span>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Section</label>
        <select name="section_id" class="form-select form-select-sm">
          <option value="">All handled sections</option>
          <?php foreach ($sections as $sec): ?>
            <option value="<?= (int)$sec['id'] ?>" <?= $sectionId === (int)$sec['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(($sec['subject_code'] ? $sec['subject_code'] . ' - ' : '') . ($sec['year_level'] ? $sec['year_level'] . ' - ' : '') . $sec['section']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Term</label>
        <select name="term_id" class="form-select form-select-sm">
          <option value="">All terms</option>
          <?php foreach ($terms as $tid => $tname): ?>
            <option value="<?= (int)$tid ?>" <?= $termId === (int)$tid ? 'selected' : '' ?>>
              <?= htmlspecialchars($tname) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">From date</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-2">
        <label class="form-label">To date</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="form-control form-control-sm">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-funnel me-1"></i> Apply Filters
        </button>
      </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
      Leave the date fields empty to see attendance for all time. Use Section and Term filters to narrow down by class handle or grading period.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong>Attendance records</strong>
    <span class="text-muted small"><?= count($rows) ?> record(s) shown (max 1000)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <p class="text-muted p-3 mb-0">No attendance records found for the selected filters.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle table-sm">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Time</th>
              <th>Status</th>
              <th>Student</th>
              <th>Subject</th>
              <th>Section</th>
              <th>Term</th>
              <th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['attendance_date']) ?></td>
                <td><?= $r['attendance_time'] ? htmlspecialchars(substr($r['attendance_time'], 0, 5)) : '-' ?></td>
                <td>
                  <?php
                    $status = $r['status'] ?? 'present';
                    $badgeClass = $status === 'absent' ? 'danger' : ($status === 'late' ? 'warning' : ($status === 'excused' ? 'info' : 'success'));
                  ?>
                  <span class="badge bg-<?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($status)) ?></span>
                </td>
                <td class="text-start">
                  <?= htmlspecialchars($r['full_name']) ?><br>
                  <span class="text-muted small"><?= htmlspecialchars($r['student_id']) ?></span>
                </td>
                <td><?= htmlspecialchars($r['subject_code']) ?></td>
                <td>
                  <?= htmlspecialchars(($r['year_level'] ? $r['year_level'] . ' - ' : '') . ($r['section_name'] ?? '')) ?><br>
                  <span class="text-muted small"><?= htmlspecialchars($r['department'] ?? '') ?></span>
                </td>
                <td>
                  <?php
                    $tid = (int)($r['term_id'] ?? 0);
                    echo htmlspecialchars($terms[$tid] ?? '');
                  ?>
                </td>
                <td class="text-start"><?= htmlspecialchars($r['remarks'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
