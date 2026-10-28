<?php
/**
 * One-time: set EVERY user's password_hash to the current APPGRADE_DEFAULT_PASSWORD
 * (stored as bcrypt(SHA256_hex)). Run from CLI: php tools/set_all_passwords_to_default.php
 *
 * WARNING: This overwrites all passwords. Remove or protect this file after use.
 */
declare(strict_types=1);

require __DIR__ . '/../instructor/config/database.php';
require_once __DIR__ . '/../includes/password_payload.php';

$db = getDb();
$hash = hash_password_for_storage(APPGRADE_DEFAULT_PASSWORD);

$tables = [
    'instructors',
    'mis_users',
    'registrars',
    'deans',
    'students',
];

$total = 0;
foreach ($tables as $table) {
    $stmt = $db->prepare("UPDATE `$table` SET `password_hash` = ?");
    $stmt->execute([$hash]);
    $total += $stmt->rowCount();
}

echo "Updated password_hash in all rows (total row updates reported): {$total}\n";
echo 'Default password plaintext is: ' . APPGRADE_DEFAULT_PASSWORD . "\n";
