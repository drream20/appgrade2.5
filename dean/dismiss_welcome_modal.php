<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
deanRequireLogin();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$db = getDb();
$stmt = $db->prepare('UPDATE deans SET welcome_modal_ack = 1 WHERE id = ?');
$stmt->execute([currentDeanId()]);
echo json_encode(['ok' => true]);
