<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

// Set default limit to 20
$userType = $_GET['user_type'] ?? '';
$limit = isset($_GET['limit']) ? min(500, max(20, (int)$_GET['limit'])) : 20;

$where = '1=1';
$params = [];
if ($userType && in_array($userType, ['mis','instructor','registrar','dean'])) {
    $where = 'user_type = ?';
    $params[] = $userType;
}
$stmt = $db->prepare("SELECT * FROM activity_logs WHERE $where ORDER BY created_at DESC LIMIT $limit");
$stmt->execute($userType ? [$userType] : []);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'System Logs';
$currentPage = 'logs';
ob_start();

/**
 * User type label mapping: display uppercase
 */
function userTypeDisplay($userType) {
    switch ($userType) {
        case 'mis':
            return 'MIS';
        case 'instructor':
            return 'INSTRUCTOR';
        case 'registrar':
            return 'REGISTRAR';
        case 'dean':
            return 'DEAN';
        default:
            return strtoupper($userType);
    }
}

/**
 * Color mapping for user_type
 * MIS: blue, Instructor: green, Registrar: orange, Dean: purple
 */
function userTypeColorClass($userType) {
    switch ($userType) {
        case 'mis':
            return 'bg-primary'; // blue
        case 'instructor':
            return 'bg-success'; // green
        case 'registrar':
            return 'bg-warning text-dark'; // orange/yellow
        case 'dean':
            return 'bg-purple-custom'; // custom purple class
        default:
            return 'bg-secondary';
    }
}
?>
<style>
/* Custom purple for dean */
.bg-purple-custom {
  background-color: #6f42c1 !important; /* Bootstrap's purple */
  color: #fff !important;
}
/* Blue background for thead th */
.table-blue-header thead th {
  background-color:rgb(38, 77, 132) !important;  color: #fff !important;
}
</style>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">System Logs</h4>
  <form method="get" class="d-flex gap-2 align-items-center">
    <select name="user_type" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="">All users</option>
      <option value="mis" <?= $userType === 'mis' ? 'selected' : '' ?>>MIS</option>
      <option value="instructor" <?= $userType === 'instructor' ? 'selected' : '' ?>>INSTRUCTOR</option>
      <option value="registrar" <?= $userType === 'registrar' ? 'selected' : '' ?>>REGISTRAR</option>
      <option value="dean" <?= $userType === 'dean' ? 'selected' : '' ?>>DEAN</option>
    </select>
    <select name="limit" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
      <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
      <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
      <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200</option>
      <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
    </select>
  </form>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-sm mb-0 table-blue-header">
        <thead>
          <tr>
            <th>Time</th>
            <th>User Type</th>
            <th>User ID</th>
            <th>Action</th>
            <th>Entity</th>
            <th>Details</th>
            <th>IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['created_at']))) ?></td>
            <td>
              <span class="badge <?= userTypeColorClass($log['user_type']) ?>">
                <?= htmlspecialchars(userTypeDisplay($log['user_type'])) ?>
              </span>
            </td>
            <td><?= $log['user_id'] ?></td>
            <td><?= htmlspecialchars($log['action']) ?></td>
            <td><?= htmlspecialchars($log['entity_type'] ?? '-') ?><?= $log['entity_id'] ? ' #' . $log['entity_id'] : '' ?></td>
            <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
            <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (empty($logs)): ?><p class="text-muted p-3 mb-0">No logs found.</p><?php endif; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';