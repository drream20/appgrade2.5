<?php
/**
 * Academic year checks for instructor recording
 * Recording allowed when: Academic Year is Unlocked (active or inactive).
 * When locked: instructors cannot edit; students can view that semester's records.
 */

function getInstructorSubjectIdFromEnrollment(PDO $db, int $enrollmentId): ?int {
    $stmt = $db->prepare("SELECT instructor_subject_id FROM enrollments WHERE id = ?");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['instructor_subject_id']) return (int)$row['instructor_subject_id'];
    $stmt = $db->prepare("SELECT cs.instructor_subject_id FROM enrollments e JOIN class_sections cs ON cs.id = e.class_section_id WHERE e.id = ?");
    $stmt->execute([$enrollmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['instructor_subject_id'] ? (int)$row['instructor_subject_id'] : null;
}
function canRecordForInstructorSubject(PDO $db, int $instructorSubjectId): bool {
    try {
        $stmt = $db->prepare("
          SELECT ay.is_active, ay.is_locked
          FROM instructor_subjects isub
          LEFT JOIN academic_years ay ON ay.id = isub.academic_year_id
          WHERE isub.id = ?
        ");
        $stmt->execute([$instructorSubjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['is_active'] === null) {
            $stmt = $db->prepare("
              SELECT ay.is_active, ay.is_locked
              FROM instructor_subjects isub
              JOIN academic_years ay ON ay.school_year = isub.school_year AND ay.semester = isub.semester
              WHERE isub.id = ?
              LIMIT 1
            ");
            $stmt->execute([$instructorSubjectId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$row || !isset($row['is_active'])) {
            return true; // No link - allow for backward compatibility
        }
        // Allow edit when unlocked (previous semester can be edited if MIS unlocks it)
        return (int)($row['is_locked'] ?? 1) === 0;
    } catch (Throwable $e) {
        return true; // Table may not exist yet
    }
}

/**
 * Recording allowed for one grading period when: semester is not locked (`is_locked`),
 * and that term is not locked (`lock_term_*`). Locking the semester in MIS sets all
 * three term flags and blocks every term.
 * term_id: 1=Prelim, 2=Midterm, 3=Final (matches `terms` table when present).
 */
function canRecordTermForInstructorSubject(PDO $db, int $instructorSubjectId, int $termId): bool
{
    if (!canRecordForInstructorSubject($db, $instructorSubjectId)) {
        return false;
    }
    $ay = getAcademicYearRowForInstructorSubject($db, $instructorSubjectId);
    if (!$ay) {
        return true;
    }
    if ((int) ($ay['is_locked'] ?? 0) === 1) {
        return false;
    }
    $map = [1 => 'lock_term_1', 2 => 'lock_term_2', 3 => 'lock_term_3'];
    $col = $map[$termId] ?? null;
    if ($col === null) {
        return true;
    }
    if (!array_key_exists($col, $ay)) {
        return true;
    }

    return (int) ($ay[$col] ?? 0) === 0;
}

/** @return array<string,mixed>|null */
function getAcademicYearRowForInstructorSubject(PDO $db, int $instructorSubjectId): ?array
{
    $withLocks = static function (PDO $db, int $instructorSubjectId, bool $includeLockCols): ?array {
        $cols = $includeLockCols
            ? 'ay.id, ay.school_year, ay.semester, ay.is_active, ay.is_locked, ay.lock_term_1, ay.lock_term_2, ay.lock_term_3'
            : 'ay.id, ay.school_year, ay.semester, ay.is_active, ay.is_locked';
        $stmt = $db->prepare("
          SELECT $cols
          FROM instructor_subjects isub
          LEFT JOIN academic_years ay ON ay.id = isub.academic_year_id
          WHERE isub.id = ?
        ");
        $stmt->execute([$instructorSubjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['id'] !== null) {
            return $row;
        }
        $stmt = $db->prepare("
          SELECT $cols
          FROM instructor_subjects isub
          JOIN academic_years ay ON ay.school_year = isub.school_year AND ay.semester = isub.semester
          WHERE isub.id = ?
          LIMIT 1
        ");
        $stmt->execute([$instructorSubjectId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    try {
        $row = $withLocks($db, $instructorSubjectId, true);
    } catch (Throwable $e) {
        $row = $withLocks($db, $instructorSubjectId, false);
        if ($row) {
            $row['lock_term_1'] = 0;
            $row['lock_term_2'] = 0;
            $row['lock_term_3'] = 0;
        }
    }

    return $row ?: null;
}

function getAcademicYearStatusForInstructorSubject(PDO $db, int $instructorSubjectId): ?array
{
    return getAcademicYearRowForInstructorSubject($db, $instructorSubjectId);
}
