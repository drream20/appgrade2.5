<?php
/**
 * Enrollment-level grades view (read-only), same data model as Dean Student Management.
 * Registrar sees all programs for the active academic year.
 */
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/../instructor/includes/grades.php';
registrarRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();

$activeAyId = null;
$activeAyLabel = null;
try {
    $stmt = $db->query("SELECT id, school_year, semester FROM academic_years WHERE is_active = 1 LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activeAyId = (int) $row['id'];
        $activeAyLabel = trim(($row['school_year'] ?? '') . ' ' . ($row['semester'] ?? ''));
    }
} catch (Throwable $e) {
    $activeAyId = null;
    $activeAyLabel = null;
}

$yearLevel = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int) $_GET['year_level'] : null;
$section = isset($_GET['section']) && $_GET['section'] !== '' ? trim($_GET['section']) : null;
$subjectId = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int) $_GET['subject_id'] : null;
$search = trim($_GET['search'] ?? '');

$sections = [];
$subjects = [];

if ($activeAyId) {
    $stmt = $db->prepare("
        SELECT DISTINCT cs.year_level, cs.section
        FROM class_sections cs
        JOIN enrollments e ON e.class_section_id = cs.id
        WHERE cs.academic_year_id = ?
        ORDER BY cs.year_level, cs.section
    ");
    $stmt->execute([$activeAyId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT DISTINCT s.id, s.code, s.title
        FROM class_sections cs
        JOIN enrollments e ON e.class_section_id = cs.id
        JOIN subjects s ON s.id = cs.subject_id
        WHERE cs.academic_year_id = ?
        ORDER BY s.code
    ");
    $stmt->execute([$activeAyId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$params = [];
$where = ['1=1'];

if ($activeAyId) {
    $where[] = 'cs.academic_year_id = :ayId';
    $params[':ayId'] = $activeAyId;
} else {
    $where[] = '0=1';
}

if ($yearLevel) {
    $where[] = 'cs.year_level = :yearLevel';
    $params[':yearLevel'] = $yearLevel;
}
if ($section !== null && $section !== '') {
    $where[] = 'cs.section = :section';
    $params[':section'] = $section;
}
if ($subjectId) {
    $where[] = 's.id = :subjectId';
    $params[':subjectId'] = $subjectId;
}
if ($search !== '') {
    $where[] = '('
        . 'st.student_id LIKE :search OR '
        . 'st.full_name LIKE :search OR '
        . 'st.email LIKE :search OR '
        . 'CAST(cs.year_level AS CHAR) LIKE :search OR '
        . 'cs.section LIKE :search OR '
        . 's.code LIKE :search OR '
        . 's.title LIKE :search'
        . ')';
    $params[':search'] = '%' . $search . '%';
}

$sql = "
    SELECT
      e.id AS enrollment_id,
      st.student_id,
      st.full_name,
      st.email,
      cs.year_level,
      cs.section,
      s.code AS subject_code,
      s.title AS subject_title,
      tg1.term_grade_computed AS prelim,
      tg2.term_grade_computed AS midterm,
      tg3.term_grade_computed AS final,
      COALESCE(absent_counts.absent_count, 0) AS absences
    FROM enrollments e
    JOIN students st ON st.id = e.student_id
    LEFT JOIN class_sections cs ON cs.id = e.class_section_id
    LEFT JOIN instructor_subjects isub ON isub.id = COALESCE(e.instructor_subject_id, cs.instructor_subject_id)
    LEFT JOIN subjects s ON s.id = isub.subject_id
    LEFT JOIN term_grades tg1 ON tg1.enrollment_id = e.id AND tg1.term_id = 1
    LEFT JOIN term_grades tg2 ON tg2.enrollment_id = e.id AND tg2.term_id = 2
    LEFT JOIN term_grades tg3 ON tg3.enrollment_id = e.id AND tg3.term_id = 3
    LEFT JOIN (
        SELECT enrollment_id, COUNT(*) AS absent_count
        FROM attendance_records
        WHERE status = 'absent'
        GROUP BY enrollment_id
    ) absent_counts ON absent_counts.enrollment_id = e.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cs.year_level, cs.section, st.full_name, s.code
    LIMIT 200
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$r) {
    $p = is_numeric($r['prelim'] ?? null) ? (float) $r['prelim'] : null;
    $m = is_numeric($r['midterm'] ?? null) ? (float) $r['midterm'] : null;
    $f = is_numeric($r['final'] ?? null) ? (float) $r['final'] : null;
    $r['overall'] = ($p !== null && $m !== null && $f !== null) ? computeFinalGrade($p, $m, $f) : null;
}
unset($r);

$pageTitle = 'Student Management';
$currentPage = 'student_management';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Student Management (View Only)</h4>
  <?php if (!empty($activeAyLabel)): ?>
    <span class="badge bg-light text-dark border">Active AY: <?= htmlspecialchars($activeAyLabel) ?></span>
  <?php endif; ?>
</div>

<?php if (!$activeAyId): ?>
  <div class="alert alert-warning">No active academic year. Ask MIS to activate a semester to load enrollment grades.</div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-2">
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-select">
          <option value="">All Year</option>
          <?php for ($yl = 1; $yl <= 4; $yl++): ?>
            <option value="<?= $yl ?>" <?= $yearLevel === $yl ? 'selected' : '' ?>><?= $yl ?><?= $yl === 1 ? 'st' : ($yl === 2 ? 'nd' : ($yl === 3 ? 'rd' : 'th')) ?> Year</option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Section</label>
        <select name="section" class="form-select">
          <option value="">All Section</option>
          <?php foreach ($sections as $sec): ?>
            <?php $label = ($sec['year_level'] ?? '') . '-' . ($sec['section'] ?? ''); ?>
            <option value="<?= htmlspecialchars($sec['section']) ?>" <?= $section === ($sec['section'] ?? '') ? 'selected' : '' ?>>
              <?= htmlspecialchars($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Subject</label>
        <select name="subject_id" class="form-select">
          <option value="">All Subject</option>
          <?php foreach ($subjects as $sub): ?>
            <option value="<?= (int)$sub['id'] ?>" <?= $subjectId === (int)$sub['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($sub['code']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label">Search</label>
        <div class="input-group">
          <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="ID, name, section, subject code…">
          <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
      </div>
    </form>
    <p class="text-muted small mt-2 mb-0">
      Showing up to 200 enrollment rows for the active semester (all programs). Read-only.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-people me-2"></i>Enrolled Students &amp; Grades</strong>
    <span class="text-muted small"><?= count($rows) ?> record(s)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($rows)): ?>
      <p class="text-muted p-3 mb-0">No records found for the selected filters.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Student ID</th>
              <th>Full Name</th>
              <th>Year &amp; Section</th>
              <th>Subject</th>
              <th>Email</th>
              <th>Prelim</th>
              <th>Midterm</th>
              <th>Final</th>
              <th>Overall (30/30/40)</th>
              <th>Absences</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['student_id']) ?></td>
                <td><?= htmlspecialchars($r['full_name']) ?></td>
                <td>
                  <?php if ($r['year_level']): ?>
                    <?= (int)$r['year_level'] ?><?= (int)$r['year_level'] === 1 ? 'st' : ((int)$r['year_level'] === 2 ? 'nd' : ((int)$r['year_level'] === 3 ? 'rd' : 'th')) ?> Yr
                    <?= $r['section'] ? ' - ' . htmlspecialchars($r['section']) : '' ?>
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($r['subject_code']): ?>
                    <strong><?= htmlspecialchars($r['subject_code']) ?></strong>
                  <?php else: ?>
                    <span class="text-muted">N/A</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['email'] ?? '-') ?></td>
                <td><?= $r['prelim'] !== null ? number_format((float)$r['prelim'], 2) : '—' ?></td>
                <td><?= $r['midterm'] !== null ? number_format((float)$r['midterm'], 2) : '—' ?></td>
                <td><?= $r['final'] !== null ? number_format((float)$r['final'], 2) : '—' ?></td>
                <td><strong><?= $r['overall'] !== null ? number_format((float)$r['overall'], 2) : '—' ?></strong></td>
                <td class="<?= (int)$r['absences'] >= 3 ? 'text-danger fw-semibold' : '' ?>">
                  <?= (int)$r['absences'] ?>
                </td>
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
