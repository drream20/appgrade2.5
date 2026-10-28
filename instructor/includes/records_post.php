<?php
declare(strict_types=1);

/**
 * AJAX POST handler for instructor records (assessment + attendance).
 * Expects: $db (PDO), $instructorId (int), $selectedAY (array|null) from caller.
 */
require_once __DIR__ . '/record_date_guard.php';
require_once __DIR__ . '/grades.php';
require_once __DIR__ . '/academic_year.php';
require_once __DIR__ . '/subject_code_lab.php';

if (!isset($db) || !isset($instructorId)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Invalid request context.']);
    exit;
}
if (!isset($selectedAY)) {
    $selectedAY = null;
}

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$out = ['ok' => false, 'message' => ''];

$checkRecordingAllowed = function () use ($db, $instructorId, $selectedAY, &$out): bool {
    $isubId = null;
    if (!empty($_POST['instructor_subject_id'])) {
        $isubId = (int) $_POST['instructor_subject_id'];
    } elseif (!empty($_POST['enrollment_id'])) {
        $isubId = getInstructorSubjectIdFromEnrollment($db, (int) $_POST['enrollment_id']);
    }
    if (!$selectedAY) {
        $out['message'] = 'No active semester. Contact MIS to activate an academic year.';
        return false;
    }
    if ($isubId) {
        $stmt = $db->prepare('SELECT instructor_id, academic_year_id, school_year, semester FROM instructor_subjects WHERE id = ? LIMIT 1');
        $stmt->execute([$isubId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row || (int) $row['instructor_id'] !== (int) $instructorId) {
            $out['message'] = 'Unauthorized class record.';
            return false;
        }
        if (instructor_isub_row_is_lab($db, $isubId)) {
            $out['message'] = 'Class records are not available for laboratory subjects (codes ending in L). Use the lecture subject.';
            return false;
        }
        $isActiveTerm =
            ((int) ($row['academic_year_id'] ?? 0) === (int) $selectedAY['id']) ||
            (empty($row['academic_year_id']) && ($row['school_year'] ?? null) === ($selectedAY['school_year'] ?? null) && ($row['semester'] ?? null) === ($selectedAY['semester'] ?? null));
        if (!$isActiveTerm) {
            $out['message'] = 'Recording is only allowed for the active semester.';
            return false;
        }
        $termIdPost = isset($_POST['term_id']) ? (int) $_POST['term_id'] : 0;
        if ($termIdPost > 0) {
            if (!canRecordTermForInstructorSubject($db, $isubId, $termIdPost)) {
                $out['message'] = 'Recording is disabled for this term (locked by MIS) or the semester is locked.';
                return false;
            }
        } elseif (!canRecordForInstructorSubject($db, $isubId)) {
            $out['message'] = 'Recording is disabled. This semester is locked.';
            return false;
        }
    }
    return true;
};

$getSectionId = static function (): ?int {
    $raw = $_POST['class_section_id'] ?? '';
    if ($raw === '' || $raw === null) {
        return null;
    }
    $n = (int) $raw;
    return $n > 0 ? $n : null;
};

$getEnrollmentIds = function (int $instructorSubjectId, ?int $sectionId) use ($db): array {
    $params = [$instructorSubjectId, $instructorSubjectId];
    $sectionFilterSql = '';
    if ($sectionId) {
        $sectionFilterSql = ' AND e.class_section_id = ? ';
        $params[] = $sectionId;
    }
    $sql = "
        SELECT e.id
        FROM enrollments e
        WHERE (e.instructor_subject_id = ?
           OR e.class_section_id IN (SELECT id FROM class_sections WHERE instructor_subject_id = ?))
          AND e.status = 'enrolled'
        $sectionFilterSql
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0));
};

$normalizeTime = static function (?string $t): ?string {
    $t = trim((string) $t);
    if ($t === '') {
        return null;
    }
    if (strlen($t) === 5) {
        return $t . ':00';
    }
    return $t;
};

try {
    switch ($action) {
        case 'save_assessment_all':
            if (!$checkRecordingAllowed()) {
                break;
            }
            $isub = (int) ($_POST['instructor_subject_id'] ?? 0);
            $termId = (int) ($_POST['term_id'] ?? 0);
            $activityId = (int) ($_POST['activity_id'] ?? $_POST['formActivityId'] ?? 0);
            $activityDate = substr(trim((string) ($_POST['activity_date'] ?? '')), 0, 10);
            $activityTime = $normalizeTime($_POST['activity_time'] ?? null);
            $recordType = trim((string) ($_POST['record_type'] ?? 'quiz'));
            $title = trim((string) ($_POST['title'] ?? ''));
            $maxScore = (float) ($_POST['max_score'] ?? 0);
            $scores = json_decode((string) ($_POST['scores'] ?? '{}'), true);
            if (!is_array($scores)) {
                $scores = [];
            }
            $sectionId = $getSectionId();

            if ($isub <= 0 || $termId <= 0 || $title === '' || $maxScore <= 0) {
                $out['message'] = 'Missing assessment fields.';
                break;
            }
            if (!record_date_is_not_future($activityDate)) {
                $out['message'] = 'You cannot record assessments for a future date. Use today or a past date.';
                break;
            }

            $stmt = $db->prepare('SELECT instructor_id FROM instructor_subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$isub]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$owner || (int) $owner['instructor_id'] !== (int) $instructorId) {
                $out['message'] = 'Unauthorized.';
                break;
            }

            if (in_array($recordType, ['exam', 'lab_exam', 'values'], true)) {
                $sql = 'SELECT id FROM assessment_activities WHERE instructor_subject_id = ? AND term_id = ? AND record_type = ?';
                $params = [$isub, $termId, $recordType];
                if ($activityId > 0) {
                    $sql .= ' AND id != ?';
                    $params[] = $activityId;
                }
                $sql .= ' LIMIT 1';
                $chk = $db->prepare($sql);
                $chk->execute($params);
                if ($chk->fetchColumn()) {
                    if ($recordType === 'values') {
                        $out['message'] = 'A Values record already exists for this term.';
                    } elseif ($recordType === 'lab_exam') {
                        $out['message'] = 'A Lab exam record already exists for this term.';
                    } else {
                        $out['message'] = 'An Exam record already exists for this term.';
                    }
                    break;
                }
            }

            $enrollmentIds = $getEnrollmentIds($isub, $sectionId);
            if ($activityId <= 0) {
                $insA = $db->prepare('INSERT INTO assessment_activities (instructor_subject_id, term_id, record_type, title, max_score, activity_date, activity_time) VALUES (?,?,?,?,?,?,?)');
                $insA->execute([$isub, $termId, $recordType, $title, $maxScore, $activityDate, $activityTime]);
                $activityId = (int) $db->lastInsertId();
            } else {
                $own = $db->prepare('SELECT id FROM assessment_activities WHERE id = ? AND instructor_subject_id = ? LIMIT 1');
                $own->execute([$activityId, $isub]);
                if (!$own->fetchColumn()) {
                    $out['message'] = 'Assessment not found.';
                    break;
                }
                $upd = $db->prepare('UPDATE assessment_activities SET record_type = ?, title = ?, max_score = ?, activity_date = ?, activity_time = ? WHERE id = ? AND instructor_subject_id = ?');
                $upd->execute([$recordType, $title, $maxScore, $activityDate, $activityTime, $activityId, $isub]);
            }

            $recordedAt = date('Y-m-d');
            if (!empty($enrollmentIds)) {
                $ph = implode(',', array_fill(0, count($enrollmentIds), '?'));
                $del = $db->prepare("DELETE FROM assessment_records WHERE activity_id = ? AND enrollment_id IN ($ph)");
                $del->execute(array_merge([$activityId], $enrollmentIds));
            }

            $insR = $db->prepare('INSERT INTO assessment_records (enrollment_id, term_id, activity_id, record_type, score, max_score, title, recorded_at) VALUES (?,?,?,?,?,?,?,?)');
            $updatedEids = [];
            foreach ($enrollmentIds as $eid) {
                $raw = $scores[(string) $eid] ?? $scores[$eid] ?? '';
                if ($raw === '' || $raw === null) {
                    continue;
                }
                $scoreVal = (float) $raw;
                if ($scoreVal > $maxScore) {
                    $out['message'] = 'A score cannot exceed the max score.';
                    break 2;
                }
                $insR->execute([$eid, $termId, $activityId, $recordType, $scoreVal, $maxScore, $title, $recordedAt]);
                $updatedEids[$eid] = true;
            }
            foreach (array_keys($updatedEids) as $eid) {
                recomputeTermGrade($db, (int) $eid, $termId);
            }
            $out['ok'] = true;
            $out['message'] = 'Saved.';
            break;

        case 'add_attendance_slot':
        case 'save_attendance_all':
            if (!$checkRecordingAllowed()) {
                break;
            }
            $isub = (int) ($_POST['instructor_subject_id'] ?? 0);
            $termId = (int) ($_POST['term_id'] ?? 0);
            $datePart = substr(trim((string) ($_POST['attendance_date'] ?? '')), 0, 10);
            $timeRaw = $normalizeTime($_POST['attendance_time'] ?? null);
            $statuses = json_decode((string) ($_POST['statuses'] ?? '{}'), true);
            if (!is_array($statuses)) {
                $statuses = [];
            }
            $sectionId = $getSectionId();

            if ($isub <= 0 || $termId <= 0) {
                $out['message'] = 'Missing class or term.';
                break;
            }
            if (!record_date_is_not_future($datePart)) {
                $out['message'] = 'You cannot record attendance for a future date. Use today or a past date.';
                break;
            }

            $stmt = $db->prepare('SELECT instructor_id FROM instructor_subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$isub]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$owner || (int) $owner['instructor_id'] !== (int) $instructorId) {
                $out['message'] = 'Unauthorized.';
                break;
            }

            $timePart = $timeRaw ?? '00:00:00';
            $enrollmentIds = $getEnrollmentIds($isub, $sectionId);

            $mapStatus = static function (string $s): string {
                $s = strtolower(trim($s));
                return in_array($s, ['present', 'absent', 'late', 'excused'], true) ? $s : 'present';
            };

            $ins = $db->prepare('INSERT INTO attendance_records (enrollment_id, term_id, attendance_date, attendance_time, status) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), attendance_time = VALUES(attendance_time)');
            $updatedEids = [];
            foreach ($enrollmentIds as $eid) {
                $st = $mapStatus((string) ($statuses[(string) $eid] ?? $statuses[$eid] ?? 'present'));
                $ins->execute([$eid, $termId, $datePart, $timePart, $st]);
                $updatedEids[$eid] = true;
            }
            foreach (array_keys($updatedEids) as $eid) {
                recomputeTermGrade($db, (int) $eid, $termId);
            }
            $out['ok'] = true;
            $out['message'] = 'Saved.';
            break;

        case 'delete_attendance_slot':
            if (!$checkRecordingAllowed()) {
                break;
            }
            $isub = (int) ($_POST['instructor_subject_id'] ?? 0);
            $termId = (int) ($_POST['term_id'] ?? 0);
            $datePart = substr(trim((string) ($_POST['attendance_date'] ?? '')), 0, 10);
            $timeRaw = $normalizeTime($_POST['attendance_time'] ?? null);
            $sectionId = $getSectionId();
            if ($isub <= 0 || $termId <= 0 || $datePart === '') {
                $out['message'] = 'Missing parameters.';
                break;
            }
            $stmt = $db->prepare('SELECT instructor_id FROM instructor_subjects WHERE id = ? LIMIT 1');
            $stmt->execute([$isub]);
            $owner = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$owner || (int) $owner['instructor_id'] !== (int) $instructorId) {
                $out['message'] = 'Unauthorized.';
                break;
            }
            $timePart = $timeRaw ?? '00:00:00';
            $enrollmentIds = $getEnrollmentIds($isub, $sectionId);
            if (empty($enrollmentIds)) {
                $out['ok'] = true;
                break;
            }
            $placeholders = implode(',', array_fill(0, count($enrollmentIds), '?'));
            $params = array_merge($enrollmentIds, [$termId, $datePart]);
            if ($timePart === '00:00:00') {
                $sql = "DELETE FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? AND attendance_date = ? AND (attendance_time = ? OR attendance_time IS NULL)";
                $params[] = $timePart;
                $params[] = $timePart;
            } else {
                $sql = "DELETE FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? AND attendance_date = ? AND attendance_time = ?";
                $params[] = $timePart;
            }
            $del = $db->prepare($sql);
            $del->execute($params);
            foreach ($enrollmentIds as $eid) {
                recomputeTermGrade($db, (int) $eid, $termId);
            }
            $out['ok'] = true;
            break;

        case 'delete_activity':
            $aid = (int) ($_POST['activity_id'] ?? 0);
            if ($aid <= 0) {
                $out['message'] = 'Missing activity.';
                break;
            }
            $stmt = $db->prepare('
                SELECT aa.id, aa.instructor_subject_id, aa.term_id,
                       isub.academic_year_id, isub.school_year, isub.semester
                FROM assessment_activities aa
                JOIN instructor_subjects isub ON isub.id = aa.instructor_subject_id
                WHERE aa.id = ? AND isub.instructor_id = ?
                LIMIT 1
            ');
            $stmt->execute([$aid, $instructorId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $out['message'] = 'Not found.';
                break;
            }
            $termId = (int) $row['term_id'];
            $isubDel = (int) $row['instructor_subject_id'];
            if (!$selectedAY) {
                $out['message'] = 'No active semester. Contact MIS to activate an academic year.';
                break;
            }
            $isActiveTerm =
                ((int) ($row['academic_year_id'] ?? 0) === (int) $selectedAY['id']) ||
                (empty($row['academic_year_id']) && ($row['school_year'] ?? null) === ($selectedAY['school_year'] ?? null) && ($row['semester'] ?? null) === ($selectedAY['semester'] ?? null));
            if (!$isActiveTerm) {
                $out['message'] = 'Recording is only allowed for the active semester.';
                break;
            }
            if (!canRecordTermForInstructorSubject($db, $isubDel, $termId)) {
                $out['message'] = 'Recording is disabled for this term (locked by MIS) or the semester is locked.';
                break;
            }
            if (instructor_isub_row_is_lab($db, $isubDel)) {
                $out['message'] = 'Class records are not available for laboratory subjects (codes ending in L). Use the lecture subject.';
                break;
            }
            $aff = $db->prepare('SELECT DISTINCT enrollment_id FROM assessment_records WHERE activity_id = ?');
            $aff->execute([$aid]);
            $affectedEids = array_map('intval', $aff->fetchAll(PDO::FETCH_COLUMN, 0));
            $db->prepare('DELETE FROM assessment_records WHERE activity_id = ?')->execute([$aid]);
            $db->prepare('DELETE FROM assessment_activities WHERE id = ?')->execute([$aid]);
            foreach ($affectedEids as $eid) {
                recomputeTermGrade($db, $eid, $termId);
            }
            $out['ok'] = true;
            break;

        default:
            $out['message'] = 'Unknown action.';
    }
} catch (Throwable $e) {
    $out['ok'] = false;
    $out['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($out);
exit;
