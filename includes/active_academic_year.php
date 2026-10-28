<?php
declare(strict_types=1);

/**
 * Active Academic Year helpers.
 *
 * The system's current academic term is the row in `academic_years` where `is_active = 1`.
 * Exactly one row should be active at a time.
 *
 * `is_locked` controls whether grade recording/editing is allowed for that term.
 */

function getActiveAcademicYear(PDO $db): ?array {
    try {
        $stmt = $db->query("SELECT id, school_year, semester, is_active, is_locked FROM academic_years WHERE is_active = 1 LIMIT 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Returns a SQL snippet + params to match rows belonging to the active term.
 *
 * Backward compatible: if the row has NULL academic_year_id, it falls back to matching school_year+semester.
 *
 * Example:
 *   [$where, $params] = activeAcademicYearMatch('isub.academic_year_id', 'isub.school_year', 'isub.semester', $activeAy);
 */
function activeAcademicYearMatch(string $academicYearIdExpr, string $schoolYearExpr, string $semesterExpr, array $activeAy): array {
    $sql = "(" . $academicYearIdExpr . " = ? OR (" . $academicYearIdExpr . " IS NULL AND " . $schoolYearExpr . " = ? AND " . $semesterExpr . " = ?))";
    return [
        $sql,
        [
            (int)($activeAy['id'] ?? 0),
            (string)($activeAy['school_year'] ?? ''),
            (string)($activeAy['semester'] ?? ''),
        ],
    ];
}

