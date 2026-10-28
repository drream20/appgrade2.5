<?php
declare(strict_types=1);

/**
 * Login password encoding: client sends SHA-256 (hex) of UTF-8 password;
 * DB stores bcrypt(SHA256_hex) so the HTTP payload never contains plaintext.
 *
 * Profile/API flows may still POST plaintext; verify_password_payload() supports both.
 */

if (!defined('APPGRADE_DEFAULT_PASSWORD')) {
    /** Default password for new accounts, MIS uploads, and resets (stored as bcrypt(SHA256_hex)). */
    define('APPGRADE_DEFAULT_PASSWORD', 'appgrade@2026');
}

/** SHA-256 of password as lowercase hex (must match login form JS). */
function password_login_sha256_hex(string $plaintext): string
{
    return hash('sha256', $plaintext);
}

/** Store bcrypt(SHA256_hex) — use when creating or resetting passwords. */
function hash_password_for_storage(string $plaintext): string
{
    return password_hash(password_login_sha256_hex($plaintext), PASSWORD_DEFAULT);
}

/**
 * Verify login or profile password.
 * Accepts plaintext (legacy / forms) or 64-char hex (hashed client login).
 */
function verify_password_payload(string $payload, string $storedHash): bool
{
    $payload = trim($payload);
    if ($payload === '' || $storedHash === '') {
        return false;
    }
    return password_verify($payload, $storedHash);
}

/** Plaintext or 64-char hex from a form → SHA-256 hex for comparison with stored bcrypt. */
function normalize_password_input(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (strlen($input) === 64 && ctype_xdigit($input)) {
        return strtolower($input);
    }
    return password_login_sha256_hex($input);
}

/**
 * If the stored bcrypt hash matches legacy default plaintext passwords,
 * return the upgraded bcrypt(SHA256_hex) hash. Otherwise return null.
 *
 * This lets uploads auto-migrate users that still use the old hashing format,
 * but it cannot convert custom passwords (no way to derive the original plaintext).
 */
function maybe_upgrade_legacy_default_password(string $storedHash): ?string
{
    $storedHash = trim($storedHash);
    if ($storedHash === '') {
        return null;
    }

    $legacyPlains = ['password', 'PASSWORD', APPGRADE_DEFAULT_PASSWORD];
    foreach ($legacyPlains as $plain) {
        if (password_verify($plain, $storedHash)) {
            return hash_password_for_storage($plain);
        }
    }

    return null;
}
