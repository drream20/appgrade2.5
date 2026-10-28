<?php
declare(strict_types=1);

/**
 * Laboratory subjects use a subject code ending in "L" (e.g. CSA104L).
 * They appear on the instructor schedule but are omitted from class records and related tools.
 */
function instructor_subject_code_is_lab_suffix(string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }

    return strtoupper(substr($code, -1)) === 'L';
}

/** True if this instructor_subject row's subject code is a lab (ends with L). */
function instructor_isub_row_is_lab(PDO $db, int $instructorSubjectId): bool
{
    $stmt = $db->prepare('
        SELECT s.code
        FROM instructor_subjects isub
        INNER JOIN subjects s ON s.id = isub.subject_id
        WHERE isub.id = ?
        LIMIT 1
    ');
    $stmt->execute([$instructorSubjectId]);
    $code = $stmt->fetchColumn();

    return $code ? instructor_subject_code_is_lab_suffix((string) $code) : false;
}
