<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$db = getDb();

$userType = $_GET['user_type'] ?? '';
$limit = min(500, max(50, (int)($_GET['limit'] ?? 100)));

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
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0">System Logs</h4>
  <form method="get" class="d-flex gap-2 align-items-center">
    <select name="user_type" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="">All users</option>
      <option value="mis" <?= $userType === 'mis' ? 'selected' : '' ?>>MIS</option>
      <option value="instructor" <?= $userType === 'instructor' ? 'selected' : '' ?>>Instructors</option>
      <option value="registrar" <?= $userType === 'registrar' ? 'selected' : '' ?>>Registrars</option>
      <option value="dean" <?= $userType === 'dean' ? 'selected' : '' ?>>Deans</option>
    </select>
    <select name="limit" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
      <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
      <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200</option>
      <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
    </select>
  </form>
</div>
add c