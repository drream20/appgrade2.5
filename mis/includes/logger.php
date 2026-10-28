<?php
/**
 * Activity logger for system-wide user actions
 */
if (!function_exists('getDb')) {
    require __DIR__ . '/../../instructor/config/database.php';
}

function logActivity(string $userType, int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
    try {
        $db = getDb();
        $stmt = $db->prepare('INSERT INTO activity_logs (user_type, user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $stmt->execute([$userType, $userId, $action, $entityType, $entityId, $details, $ip]);
    } catch (Throwable $e) {
        error_log('Activity log failed: ' . $e->getMessage());
    }
}
