<?php
/**
 * Ensures welcome_modal_ack exists on students and instructors (first-time welcome modal).
 * Safe to call every request; ignores duplicate-column errors.
 */
function welcomeModalEnsureColumns(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $stmts = [
        'ALTER TABLE students ADD COLUMN welcome_modal_ack TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE instructors ADD COLUMN welcome_modal_ack TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE deans ADD COLUMN welcome_modal_ack TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE registrars ADD COLUMN welcome_modal_ack TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE mis_users ADD COLUMN welcome_modal_ack TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($stmts as $sql) {
        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (
                strpos($msg, 'Duplicate column') === false
                && strpos($msg, '1060') === false
                && strpos($msg, '42S21') === false
            ) {
                throw $e;
            }
        }
    }
}
