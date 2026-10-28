<?php
declare(strict_types=1);

/**
 * Student portal: profile must be complete and password must not be the default.
 */
require_once __DIR__ . '/../../includes/password_payload.php';

/** @return array<string, string> field => label */
function student_profile_required_fields(): array
{
    return [
        'full_name' => 'Full name',
        'phone' => 'Phone number',
        'address' => 'Address',
        'elementary' => 'Elementary',
        'secondary' => 'Secondary',
        'elem_year' => 'Elementary year',
        'sec_year' => 'Secondary year',
    ];
}

/**
 * @return list<string> Human-readable labels for empty required fields.
 */
function student_profile_missing_labels(array $row): array
{
    $missing = [];
    foreach (student_profile_required_fields() as $key => $label) {
        if (trim((string) ($row[$key] ?? '')) === '') {
            $missing[] = $label;
        }
    }
    return $missing;
}

/** True if password hash is still a known default (current or legacy). */
function student_uses_default_password(?string $passwordHash): bool
{
    if ($passwordHash === null || $passwordHash === '') {
        return true;
    }
    foreach ([APPGRADE_DEFAULT_PASSWORD, 'password'] as $plain) {
        if (verify_password_payload(password_login_sha256_hex($plain), $passwordHash)) {
            return true;
        }
    }
    return false;
}

/** @return array<string, mixed>|null */
function student_fetch_profile_row(PDO $db, int $studentId): ?array
{
    $stmt = $db->prepare('
        SELECT id, student_id, full_name, email, password_hash, phone, address,
               elementary, secondary, elem_year, sec_year
        FROM students
        WHERE id = ?
        LIMIT 1
    ');
    $stmt->execute([$studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Student may use the rest of the portal (profile complete + password changed). */
function student_portal_access_allowed(array $row): bool
{
    if (student_uses_default_password($row['password_hash'] ?? null)) {
        return false;
    }
    return student_profile_missing_labels($row) === [];
}
