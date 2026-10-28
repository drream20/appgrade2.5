<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$message = '';
$error = '';

// For School Year dropdown: give 8 years, current year +- 4
$currentYear = (int)date('Y');
$schoolYears = [];
for ($i = $currentYear + 5; $i >= $currentYear - 4; $i--) {
    $schoolYears[] = "{$i}-" . ($i+1);
}
// For Semester dropdown
$semesterOptions = [
    "1st Sem" => "1st Sem",
    "2nd Sem" => "2nd Sem",
];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'create' && !empty($_POST['school_year']) && !empty($_POST['semester'])) {
        $sy = trim($_POST['school_year']);
        $sem = trim($_POST['semester']);
        try {
            $db->prepare('INSERT INTO academic_years (school_year, semester, is_active, is_locked, lock_term_1, lock_term_2, lock_term_3) VALUES (?, ?, 0, 0, 0, 0, 0)')->execute([$sy, $sem]);
            logActivity('mis', currentMisUserId(), 'create', 'academic_year', (int)$db->lastInsertId(), "Created $sy $sem");
            $message = 'Academic year created.';
        } catch (PDOException $e) {
            $error = $e->getCode() == 23000 ? 'Academic year already exists.' : $e->getMessage();
        }
    } elseif ($action === 'activate' && $id) {
        $db->beginTransaction();
        try {
            // Get the currently active row so we can lock it after switching (previous semester stored, locked)
            $prev = $db->query('SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $prevId = $prev ? (int)$prev['id'] : null;

            $db->exec('UPDATE academic_years SET is_active = 0');
            $db->prepare('UPDATE academic_years SET is_active = 1 WHERE id = ?')->execute([$id]);

            // Lock the previous semester so instructors cannot edit; students can see its records
            if ($prevId && $prevId !== $id) {
                try {
                    $db->prepare('UPDATE academic_years SET is_locked = 1, lock_term_1 = 1, lock_term_2 = 1, lock_term_3 = 1 WHERE id = ?')->execute([$prevId]);
                } catch (Throwable $e) {
                    $db->prepare('UPDATE academic_years SET is_locked = 1 WHERE id = ?')->execute([$prevId]);
                }
            }

            $row = $db->prepare('SELECT school_year, semester FROM academic_years WHERE id = ?');
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            logActivity('mis', currentMisUserId(), 'activate', 'academic_year', $id, $r ? "Activated {$r['school_year']} {$r['semester']}" : null);
            $db->commit();
            $message = 'Academic year activated. Previous semester locked (instructors cannot edit; students can view).';
        } catch (Throwable $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    } elseif ($action === 'lock' && $id) {
        try {
            $stmt = $db->prepare('UPDATE academic_years SET is_locked = 1, lock_term_1 = 1, lock_term_2 = 1, lock_term_3 = 1 WHERE id = ?');
            $stmt->execute([$id]);
        } catch (Throwable $e) {
            $stmt = $db->prepare('UPDATE academic_years SET is_locked = 1 WHERE id = ?');
            $stmt->execute([$id]);
        }
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'lock', 'academic_year', $id);
            $message = 'Academic year locked. All grading periods (Prelim, Midterm, Final) are locked; instructors cannot edit; students can view records.';
        }
    } elseif ($action === 'unlock' && $id) {
        try {
            $stmt = $db->prepare('UPDATE academic_years SET is_locked = 0, lock_term_1 = 0, lock_term_2 = 0, lock_term_3 = 0 WHERE id = ?');
            $stmt->execute([$id]);
        } catch (Throwable $e) {
            $stmt = $db->prepare('UPDATE academic_years SET is_locked = 0 WHERE id = ?');
            $stmt->execute([$id]);
        }
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'unlock', 'academic_year', $id);
            $message = 'Academic year unlocked. All term locks cleared; you can lock individual terms again if needed.';
        }
    } elseif ($action === 'term_lock' && $id) {
        $term = (int) ($_POST['term'] ?? 0);
        $locked = !empty($_POST['locked']) ? 1 : 0;
        $termCols = [1 => 'lock_term_1', 2 => 'lock_term_2', 3 => 'lock_term_3'];
        $chk = $db->prepare('SELECT is_locked FROM academic_years WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $chkRow = $chk->fetch(PDO::FETCH_ASSOC);
        if ($chkRow && (int) ($chkRow['is_locked'] ?? 0) === 1) {
            $error = 'This semester is fully locked. Unlock the semester first; then you can adjust Prelim / Midterm / Final locks.';
        } elseif (!isset($termCols[$term])) {
            $error = 'Invalid grading period.';
        } else {
            $col = $termCols[$term];
            try {
                $stmt = $db->prepare("UPDATE academic_years SET `$col` = ? WHERE id = ?");
                $stmt->execute([$locked, $id]);
                if ($stmt->rowCount()) {
                    $label = $term === 1 ? 'Prelim' : ($term === 2 ? 'Midterm' : 'Final');
                    logActivity('mis', currentMisUserId(), $locked ? 'lock_term' : 'unlock_term', 'academic_year', $id, $label);
                    $message = $locked ? "$label locked for instructors (they can still view)." : "$label unlocked for recording.";
                }
            } catch (Throwable $e) {
                $error = 'Could not update term lock. Run database migration if lock_term_* columns are missing.';
            }
        }
    } elseif ($action === 'delete' && $id) {
        $stmt = $db->prepare('SELECT school_year, semester FROM academic_years WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $db->prepare('DELETE FROM academic_years WHERE id = ? AND is_active = 0');
        $stmt->execute([$id]);
        if ($stmt->rowCount()) {
            logActivity('mis', currentMisUserId(), 'delete', 'academic_year', $id, $r ? "Deleted {$r['school_year']} {$r['semester']}" : null);
            $message = 'Academic year deleted.';
        } else {
            $error = 'Cannot delete active academic year. Deactivate it first.';
        }
    }
}

$stmt = $db->query('SELECT * FROM academic_years ORDER BY school_year DESC, semester DESC');
$academicYears = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Academic Years';
$currentPage = 'academic_years';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Academic Year Management</h4>
  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal"><i class="bi bi-plus-lg me-1"></i>Add Academic Year</button>
</div>

<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
  <div class="card-header bg-white">
    <strong><i class="bi bi-info-circle me-2"></i>Rules</strong>
  </div>
  <div class="card-body small">
    <ul class="mb-0">
      <li><strong>Active</strong> = Current semester; data from previous semester is kept (stored temporarily).</li>
      <li><strong>Inactive</strong> = Previous semesters; lock/unlock applies to both active and inactive.</li>
      <li><strong>Unlocked</strong> = Instructors can edit records (grades, attendance) for that semester.</li>
      <li><strong>Locked</strong> = Instructors cannot edit; students can see that semester's records and grades. Locking the semester also sets Prelim, Midterm, and Final to locked.</li>
      <li><strong>Prelim / Midterm / Final</strong> = When the semester is <strong>unlocked</strong>, you can lock individual grading periods. (While the semester is locked, all terms are locked and these toggles are disabled.)</li>
    </ul>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr>
            <th>School Year</th>
            <th>Semester</th>
            <th>Status</th>
            <th>Semester lock</th>
            <th>Prelim</th>
            <th>Midterm</th>
            <th>Final</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($academicYears as $ay): ?>
          <tr>
            <td><?= htmlspecialchars($ay['school_year']) ?></td>
            <td><?= htmlspecialchars($ay['semester']) ?></td>
            <td>
              <?php if ($ay['is_active']): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($ay['is_locked']): ?>
                <span class="badge bg-danger">Locked</span>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="unlock">
                  <input type="hidden" name="id" value="<?= $ay['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-link p-0 ms-1">Unlock</button>
                </form>
              <?php else: ?>
                <span class="badge bg-primary">Unlocked</span>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="lock">
                  <input type="hidden" name="id" value="<?= $ay['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-link p-0 ms-1">Lock</button>
                </form>
              <?php endif; ?>
            </td>
            <?php foreach ([1 => 'Prelim', 2 => 'Mid', 3 => 'Fin'] as $tid => $tshort): ?>
            <td class="small">
              <?php
                $lk = isset($ay['lock_term_' . $tid]) ? (int) $ay['lock_term_' . $tid] : 0;
                $semLocked = !empty($ay['is_locked']);
                $showLocked = $semLocked || $lk;
              ?>
              <?php if ($semLocked): ?>
                <span class="badge bg-danger">Locked</span>
                <span class="text-muted d-block" style="font-size:0.75rem;">via semester</span>
              <?php elseif ($showLocked): ?>
                <span class="badge bg-warning text-dark">Locked</span>
                <form method="post" class="d-inline" onsubmit="return confirm('Open <?= htmlspecialchars($tshort) ?> for instructor recording?');">
                  <input type="hidden" name="action" value="term_lock">
                  <input type="hidden" name="id" value="<?= (int) $ay['id'] ?>">
                  <input type="hidden" name="term" value="<?= $tid ?>">
                  <input type="hidden" name="locked" value="0">
                  <button type="submit" class="btn btn-sm btn-link p-0">Open</button>
                </form>
              <?php else: ?>
                <span class="badge bg-secondary">Open</span>
                <form method="post" class="d-inline" onsubmit="return confirm('Lock <?= htmlspecialchars($tshort) ?>? Instructors will not be able to record for this term.');">
                  <input type="hidden" name="action" value="term_lock">
                  <input type="hidden" name="id" value="<?= (int) $ay['id'] ?>">
                  <input type="hidden" name="term" value="<?= $tid ?>">
                  <input type="hidden" name="locked" value="1">
                  <button type="submit" class="btn btn-sm btn-link p-0">Lock</button>
                </form>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td>
              <?php if (!$ay['is_active']): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Activate this academic year?');">
                  <input type="hidden" name="action" value="activate">
                  <input type="hidden" name="id" value="<?= $ay['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-success">Activate</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this academic year?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $ay['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($academicYears)): ?>
    <p class="text-muted p-3 mb-0">No academic years. Create one to get started.</p>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Add Academic Year</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label class="form-label">School Year</label>
            <select name="school_year" class="form-select" required>
              <option value="" disabled selected>Select school year</option>
              <?php foreach ($schoolYears as $sy): ?>
                <option value="<?= htmlspecialchars($sy) ?>"><?= htmlspecialchars($sy) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Semester</label>
            <select name="semester" class="form-select" required>
              <option value="" disabled selected>Select semester</option>
              <?php foreach ($semesterOptions as $value => $label): ?>
                <option value="<?= $value ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
