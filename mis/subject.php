<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/logger.php';

misRequireLogin();

$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($action === 'create_subject') {
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $yearLevel = (int) ($_POST['year_level'] ?? 1);
        $semester = (int) ($_POST['semester'] ?? 1);
        $units = isset($_POST['units']) && $_POST['units'] !== '' ? (float) $_POST['units'] : 3.00;

        if ($code === '' || $title === '') {
            $error = 'Subject code and title are required.';
        } else {
            try {
                $stmt = $db->prepare('INSERT INTO subjects (code, title, description, department) VALUES (?, ?, ?, ?)');
                $stmt->execute([
                    $code,
                    $title,
                    $description !== '' ? $description : null,
                    $department !== '' ? $department : null,
                ]);
                $subjectId = (int) $db->lastInsertId();

                // Optional: link to prospectus for year-level management
                try {
                    if ($yearLevel >= 1 && $yearLevel <= 4) {
                        $stmt = $db->prepare('INSERT INTO prospectus (subject_id, year_level, semester, units, sort_order) VALUES (?, ?, ?, ?, 0)');
                        $stmt->execute([$subjectId, $yearLevel, $semester ?: 1, $units]);
                    }
                } catch (Throwable $e) {
                    // Prospectus table may not exist; ignore quietly
                }

                logActivity('mis', currentMisUserId(), 'create', 'subject', $subjectId, $code);
                $message = 'Subject created.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Subject code already exists.';
                } else {
                    $error = $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update_subject' && $id) {
        $code = trim($_POST['code'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $yearLevel = (int) ($_POST['year_level'] ?? 0);
        $semester = (int) ($_POST['semester'] ?? 0);
        $units = isset($_POST['units']) && $_POST['units'] !== '' ? (float) $_POST['units'] : 3.00;

        if ($code === '' || $title === '') {
            $error = 'Subject code and title are required.';
        } else {
            try {
                $stmt = $db->prepare('UPDATE subjects SET code = ?, title = ?, description = ?, department = ? WHERE id = ?');
                $stmt->execute([
                    $code,
                    $title,
                    $description !== '' ? $description : null,
                    $department !== '' ? $department : null,
                    $id,
                ]);

                // Update or create a prospectus row for year-level mapping
                try {
                    if ($yearLevel >= 1 && $yearLevel <= 4) {
                        $stmt = $db->prepare('SELECT id FROM prospectus WHERE subject_id = ? LIMIT 1');
                        $stmt->execute([$id]);
                        $p = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($p) {
                            $stmt = $db->prepare('UPDATE prospectus SET year_level = ?, semester = ?, units = ? WHERE id = ?');
                            $stmt->execute([$yearLevel, $semester ?: 1, $units, $p['id']]);
                        } else {
                            $stmt = $db->prepare('INSERT INTO prospectus (subject_id, year_level, semester, units, sort_order) VALUES (?, ?, ?, ?, 0)');
                            $stmt->execute([$id, $yearLevel, $semester ?: 1, $units]);
                        }
                    }
                } catch (Throwable $e) {
                    // Ignore failures related to prospectus
                }

                logActivity('mis', currentMisUserId(), 'update', 'subject', $id, $code);
                $message = 'Subject updated.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'Subject code already exists.';
                } else {
                    $error = $e->getMessage();
                }
            }
        }
    } elseif ($action === 'update_prospectus_inline' && $id) {
        // Inline update for year_level, semester, or units from table
        // Only allow specific editable fields from the table
        $yearLevel = isset($_POST['year_level']) ? (int)$_POST['year_level'] : null;
        $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : null;
        $units = isset($_POST['units']) && $_POST['units'] !== '' ? (float)$_POST['units'] : null;

        try {
            // If prospectus exists, update; else create
            $stmt = $db->prepare('SELECT id FROM prospectus WHERE subject_id = ? LIMIT 1');
            $stmt->execute([$id]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($p) {
                $stmt = $db->prepare('UPDATE prospectus SET year_level = ?, semester = ?, units = ? WHERE id = ?');
                $stmt->execute([
                    $yearLevel,
                    $semester,
                    $units,
                    $p['id'],
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO prospectus (subject_id, year_level, semester, units, sort_order) VALUES (?, ?, ?, ?, 0)'
                );
                $stmt->execute([
                    $id,
                    $yearLevel,
                    $semester,
                    $units,
                ]);
            }
            logActivity('mis', currentMisUserId(), 'update', 'prospectus', $id, "Table Inline Edit");
            $message = 'Year level, semester, and units updated.';
        } catch (PDOException $e) {
            $error = 'Failed to update year level/semester/units: ' . $e->getMessage();
        }
    } elseif ($action === 'delete_subject' && $id) {
        try {
            $stmt = $db->prepare('SELECT code FROM subjects WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $db->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$id]);

            if ($stmt->rowCount()) {
                logActivity('mis', currentMisUserId(), 'delete', 'subject', $id, $row ? $row['code'] : null);
                $message = 'Subject deleted.';
            } else {
                $error = 'Subject not found or cannot be deleted.';
            }
        } catch (PDOException $e) {
            $error = 'Cannot delete subject. It may be in use in schedules, enrollments, or records.';
        }
    }
}

// Add search subject, **get from GET not from JS**
$searchTerm = trim($_GET['search'] ?? '');

// Filters
$selectedDepartment = trim($_GET['department'] ?? '');
$selectedYearLevel = isset($_GET['year_level']) && $_GET['year_level'] !== '' ? (int) $_GET['year_level'] : null;

// Get departments for filter
$departments = [];
try {
    $stmt = $db->query('SELECT DISTINCT department FROM subjects WHERE department IS NOT NULL AND department <> \'\' ORDER BY department');
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $departments = [];
}

// Get subjects with year-level/semester mapping from prospectus
$subjects = [];
try {
    $sql = "
      SELECT
        s.id,
        s.code,
        s.title,
        s.description,
        s.department,
        MIN(p.year_level) AS year_level,
        MIN(p.semester) AS semester,
        MIN(p.units) AS units
      FROM subjects s
      LEFT JOIN prospectus p ON p.subject_id = s.id
    ";
    $where = [];
    $params = [];

    if ($selectedDepartment !== '') {
        $where[] = 's.department = ?';
        $params[] = $selectedDepartment;
    }
    if ($selectedYearLevel) {
        $where[] = 'p.year_level = ?';
        $params[] = $selectedYearLevel;
    }
    if ($searchTerm !== '') {
        $where[] = '(s.code LIKE ? OR s.title LIKE ? OR s.department LIKE ?)';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
        $params[] = '%' . $searchTerm . '%';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= '
      GROUP BY s.id, s.code, s.title, s.description, s.department
      ORDER BY s.department IS NULL, s.department, year_level IS NULL, year_level, s.code
    ';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Fallback if prospectus table does not exist
    $fallbackSql = 'SELECT id, code, title, description, department FROM subjects';
    $fallbackParams = [];
    $fallbackWheres = [];
    if ($selectedDepartment !== '') {
        $fallbackWheres[] = 'department = ?';
        $fallbackParams[] = $selectedDepartment;
    }
    if ($searchTerm !== '') {
        $fallbackWheres[] = '(code LIKE ? OR title LIKE ? OR department LIKE ?)';
        $fallbackParams[] = '%' . $searchTerm . '%';
        $fallbackParams[] = '%' . $searchTerm . '%';
        $fallbackParams[] = '%' . $searchTerm . '%';
    }
    if ($fallbackWheres) {
        $fallbackSql .= ' WHERE ' . implode(' AND ', $fallbackWheres);
    }
    $fallbackSql .= ' ORDER BY department IS NULL, department, code';
    $stmt = $db->prepare($fallbackSql);
    $stmt->execute($fallbackParams);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Subjects';
$currentPage = 'subjects';

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Subject Management</h4>
  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createSubjectModal">
    <i class="bi bi-plus-lg me-1"></i>Add Subject
  </button>
</div>

<?php if ($message): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Filter Subjects container removed as per instruction -->

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
    <div>
      <strong><i class="bi bi-book me-2"></i>Subjects</strong>
      <span class="text-muted small"><?= count($subjects) ?> subject(s)</span>
    </div>
    <div class="ms-auto mt-2 mt-md-0" style="max-width: 350px; width:100%;">
      <form method="get" class="input-group input-group-sm" id="subject-search-form" autocomplete="off">
        <input
          type="text"
          id="subject-search"
          name="search"
          class="form-control"
          placeholder="Search code, title, or department..."
          value="<?= htmlspecialchars($searchTerm) ?>"
        >
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
      </form>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($subjects)): ?>
      <p class="text-muted p-3 mb-0">No subjects found. Add one to get started.</p>
    <?php else: ?>
      <style>
      /* Apply 0F3468 as table header background and white text color for visibility */
      #subjects-table thead th {
        background-color:rgb(38, 77, 132) !important;
        color: #fff !important;
        /* ensure the color overrides .table-light */
        vertical-align: middle;
      }
      </style>
      <div class="table-responsive">
        <form method="post" id="inline-prospectus-form">
        <input type="hidden" name="action" value="update_prospectus_inline">
        <table class="table table-hover mb-0 align-middle" id="subjects-table">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Title</th>
              <th>Department</th>
              <th>Year Level</th>
              <th>Semester</th>
              <th>Units</th>
              <th style="width: 140px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subjects as $s): ?>
              <tr>
                <td class="filter-code"><strong><?= htmlspecialchars($s['code']) ?></strong></td>
                <td class="filter-title"><?= htmlspecialchars($s['description']) ?>
                  <!-- <?php if (!empty($s['description'])): ?>
                    <br><span class="small text-muted"><?= htmlspecialchars($s['description']) ?></span>
                  <?php endif; ?> -->
                </td>
                <td class="filter-department"><?= htmlspecialchars($s['department'] ?? '-') ?></td>
                <td>
                  <form method="post" class="d-inline update-prospectus-form" style="margin-bottom:0;">
                    <input type="hidden" name="action" value="update_prospectus_inline">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <select name="year_level" class="form-select form-select-sm inline-edit-select" style="width: 115px;display:inline-block;">
                      <option value="0"<?= empty($s['year_level']) ? ' selected' : '' ?>>Not set</option>
                      <?php for ($y = 1; $y <= 4; $y++): ?>
                        <option value="<?= $y ?>"<?= isset($s['year_level']) && (int)$s['year_level'] === $y ? ' selected' : '' ?>>
                          <?= $y ?><?= $y === 1 ? 'st' : ($y === 2 ? 'nd' : ($y === 3 ? 'rd' : 'th')) ?> Year
                        </option>
                      <?php endfor; ?>
                    </select>
                  </form>
                </td>
                <td>
                  <form method="post" class="d-inline update-prospectus-form" style="margin-bottom:0;">
                    <input type="hidden" name="action" value="update_prospectus_inline">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <select name="semester" class="form-select form-select-sm inline-edit-select" style="width:115px;display:inline-block;">
                      <option value="1"<?= (isset($s['semester']) && $s['semester'] == 1) ? ' selected' : '' ?>>1st Sem</option>
                      <option value="2"<?= (isset($s['semester']) && $s['semester'] == 2) ? ' selected' : '' ?>>2nd Sem</option>
                    </select>
                  </form>
                </td>
                <td>
                  <form method="post" class="d-inline update-prospectus-form" style="margin-bottom:0;">
                    <input type="hidden" name="action" value="update_prospectus_inline">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <input type="number" name="units" step="0.25" min="0" class="form-control form-control-sm inline-edit-units" style="width:80px;display:inline-block;" value="<?= isset($s['units']) && $s['units'] !== null ? htmlspecialchars((float)$s['units']) : '' ?>" placeholder="—">
                  </form>
                </td>
                <td>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary me-1"
                    data-bs-toggle="modal"
                    data-bs-target="#editSubjectModal"
                    data-id="<?= (int) $s['id'] ?>"
                    data-code="<?= htmlspecialchars($s['code'], ENT_QUOTES) ?>"
                    data-title="<?= htmlspecialchars($s['title'], ENT_QUOTES) ?>"
                    data-department="<?= htmlspecialchars($s['department'] ?? '', ENT_QUOTES) ?>"
                    data-description="<?= htmlspecialchars($s['description'] ?? '', ENT_QUOTES) ?>"
                    data-year_level="<?= (int) ($s['year_level'] ?? 0) ?>"
                    data-semester="<?= (int) ($s['semester'] ?? 1) ?>"
                    data-units="<?= htmlspecialchars($s['units'] !== null ? $s['units'] : '', ENT_QUOTES) ?>"
                  >
                    Edit
                  </button>
                  <form method="post" class="d-inline" onsubmit="return confirm('Delete this subject? This may affect schedules and records.');">
                    <input type="hidden" name="action" value="delete_subject">
                    <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Create Subject Modal -->
<div class="modal fade" id="createSubjectModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Add Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="create_subject">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Subject Code</label>
              <input type="text" name="code" class="form-control" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Department</label>
              <input type="text" name="department" class="form-control" placeholder="e.g. BSCS, BSEdEng">
            </div>
            <div class="col-md-8">
              <label class="form-label">Description</label>
              <textarea name="description" class="form-control" rows="2" placeholder="Optional subject description"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Year Level</label>
              <select name="year_level" class="form-select">
                <?php for ($y = 1; $y <= 4; $y++): ?>
                  <option value="<?= $y ?>">
                    <?= $y ?><?= $y === 1 ? 'st' : ($y === 2 ? 'nd' : ($y === 3 ? 'rd' : 'th')) ?> Year
                  </option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Semester</label>
              <select name="semester" class="form-select">
                <option value="1">1st Semester</option>
                <option value="2">2nd Semester</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Units</label>
              <input type="number" name="units" step="0.25" min="0" class="form-control" value="3.00">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Subject</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Subject Modal -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Edit Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update_subject">
          <input type="hidden" name="id" id="edit-subject-id">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Subject Code</label>
              <input type="text" name="code" id="edit-subject-code" class="form-control" required>
            </div>
            <div class="col-md-8">
              <label class="form-label">Title</label>
              <input type="text" name="title" id="edit-subject-title" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Department</label>
              <input type="text" name="department" id="edit-subject-department" class="form-control">
            </div>
            <div class="col-md-8">
              <label class="form-label">Description</label>
              <textarea name="description" id="edit-subject-description" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Year Level</label>
              <select name="year_level" id="edit-subject-year-level" class="form-select">
                <option value="0">Not set</option>
                <?php for ($y = 1; $y <= 4; $y++): ?>
                  <option value="<?= $y ?>">
                    <?= $y ?><?= $y === 1 ? 'st' : ($y === 2 ? 'nd' : ($y === 3 ? 'rd' : 'th')) ?> Year
                  </option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Semester</label>
              <select name="semester" id="edit-subject-semester" class="form-select">
                <option value="1">1st Semester</option>
                <option value="2">2nd Semester</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Units</label>
              <input type="number" name="units" id="edit-subject-units" step="0.25" min="0" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$footerScripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
  var editModal = document.getElementById('editSubjectModal');
  if (!editModal) return;

  editModal.addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) return;

    var id = button.getAttribute('data-id');
    var code = button.getAttribute('data-code') || '';
    var title = button.getAttribute('data-title') || '';
    var department = button.getAttribute('data-department') || '';
    var description = button.getAttribute('data-description') || '';
    var yearLevel = button.getAttribute('data-year_level') || '0';
    var semester = button.getAttribute('data-semester') || '1';
    var units = button.getAttribute('data-units') || '';

    document.getElementById('edit-subject-id').value = id;
    document.getElementById('edit-subject-code').value = code;
    document.getElementById('edit-subject-title').value = title;
    document.getElementById('edit-subject-department').value = department;
    document.getElementById('edit-subject-description').value = description;
    document.getElementById('edit-subject-year-level').value = yearLevel;
    document.getElementById('edit-subject-semester').value = semester;
    document.getElementById('edit-subject-units').value = units;
  });

  // NO real-time search for table. Only search on form submit.

  // Inline update for year_level, semester, units
  // Each select or input triggers submission of its parent form.
  var inlineEditSelects = document.querySelectorAll('.inline-edit-select');
  inlineEditSelects.forEach(function(select) {
    select.addEventListener('change', function() {
      this.form.submit();
    });
  });
  var inlineEditUnits = document.querySelectorAll('.inline-edit-units');
  inlineEditUnits.forEach(function(input) {
    input.addEventListener('blur', function() {
      if (this.value !== '' && !isNaN(this.value)) {
        this.form.submit();
      }
    });
    // Optionally, submit on Enter
    input.addEventListener('keydown', function(ev) {
      if (ev.key === 'Enter') {
        ev.preventDefault();
        this.blur(); // will submit on blur
      }
    });
  });
});
</script>
HTML;

$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
