<?php
/**
 * One-time migration: bcrypt(plaintext) -> bcrypt(SHA256_hex(plaintext)) for rows
 * where the plaintext matches known defaults (legacy "password"/"PASSWORD" and current APPGRADE_DEFAULT_PASSWORD).
 *
 * Run with MySQL up: php tools/migrate_passwords_sha256_first.php
 */
declare(strict_types=1);

require __DIR__ . '/../instructor/config/database.php';
require_once __DIR__ . '/../includes/password_payload.php';

$db = getDb();
/** Known plaintext defaults that may exist as legacy bcrypt(plaintext) in the DB. */
$knownPlains = ['password', 'PASSWORD', APPGRADE_DEFAULT_PASSWORD];

$tables = [
    'instructors',
    'mis_users',
    'registrars',
    'deans',
    'students',
];

$updated = 0;

foreach ($tables as $table) {
    $stmt = $db->query("SELECT DISTINCT `password_hash` FROM `$table` WHERE `password_hash` IS NOT NULL AND `password_hash` != ''");
    $hashes = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($hashes as $h) {
        $h = (string) $h;
        if ($h === '') {
            continue;
        }
        foreach ($knownPlains as $plain) {
            if (!password_verify($plain, $h)) {
                continue;
            }
            $newHash = hash_password_for_storage($plain);
            $upd = $db->prepare("UPDATE `$table` SET `password_hash` = ? WHERE `password_hash` = ?");
            $upd->execute([$newHash, $h]);
            $updated += $upd->rowCount();
            break;
        }
    }
}

echo "Updated rows (matched known default plaintexts): {$updated}\n";
