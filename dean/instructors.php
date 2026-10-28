<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

$db = getDb();
// Program context

$programDepartment = deanSelectedProgramDepartment();
$programDepartmentCode = deanDepartmentCodeForName($programDepartment);

// Build WHERE clause
$where = [];
$params = [];
$where[] = '1=1';

// Filter by department if selected
if ($programDepartment) {
    $where[] = "(
        UPPER(TRIM(COALESCE(department, ''))) = :deptCode
        OR LOWER(TRIM(REPLACE(REPLACE(REPLACE(COALESCE(department, ''), '  ', ' '), '  ', ' '), '  ', ' '))) = :deptNorm
    )";
    $params[':deptCode'] = strtoupper((string)($programDepartmentCode ?? ''));
    $params[':deptNorm'] = deanNormalizeDepartmentName($programDepartment);
}

// Query all instructor info for the department
$sql = "
    SELECT
      id AS instructor_id,
      email,
      phone,
      address,
      full_name,
      employee_id,
      department,
      avatar_path
    FROM instructors
    WHERE " . implode(' AND ', $where) . "
    ORDER BY full_name, email
";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Instructors';
$currentPage = 'instructors';
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">Instructors by Department</h4>
  <?php if ($programDepartment): ?>
    <span class="badge bg-light text-dark border">Department: <?= htmlspecialchars($programDepartment) ?></span>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-body">
    <p class="text-muted small mb-0">
      Listing all instructors for the selected department.
    </p>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <strong><i class="bi bi-person-lines-fill me-2"></i>Instructors</strong>
    <span class="text-muted small"><?= count($instructors) ?> instructor(s)</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($instructors)): ?>
      <p class="text-muted p-3 mb-0">No instructors found for the selected department.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th>Avatar</th>
              <th>Full Name</th>
              <th>Email</th>
              <th>Employee ID</th>
              <th>Phone</th>
              <th>Address</th>
              <th>Department</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($instructors as $i): ?>
              <tr>
                <td>
                  <?php if (!empty($i['avatar_path'])): ?>
                    <img src="<?= htmlspecialchars($i['avatar_path']) ?>" alt="Avatar" class="rounded-circle" style="width:40px; height:40px; object-fit:cover;">
                  <?php else: ?>
                    <span class="fa fa-user-circle text-secondary" style="font-size: 2rem;"></span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($i['full_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($i['email'] ?? '-') ?></td>
                <td><?= htmlspecialchars($i['employee_id'] ?? '-') ?></td>
                <td><?= htmlspecialchars($i['phone'] ?? '-') ?></td>
                <td><?= htmlspecialchars($i['address'] ?? '-') ?></td>
                <td><?= htmlspecialchars($i['department'] ?? '-') ?></td>
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
