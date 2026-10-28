<?php
require __DIR__ . '/../instructor/config/database.php';
require __DIR__ . '/includes/auth.php';
studentRequireLogin();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}
$db = getDb();
require_once __DIR__ . '/../includes/welcome_modal_db.php';
welcomeModalEnsureColumns($db);
$stmt = $db->prepare('UPDATE students SET welcome_modal_ack = 1 WHERE id = ?');
$stmt->execute([currentStudentId()]);
echo json_encode(['ok' => true]);
