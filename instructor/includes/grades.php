<?php
/**
 * Grade computation - Instructor Recording System
 * Term: Class standing 35%, Attendance 5%, Performance task 20%, Term exam 40%
 * Final: Prelim 30%, Midterm 30%, Final term 40%
 *
 * Attendance %: excused = full credit; late = 2/3 credit per day (3 lates ≡ 1 absent); absent = 0.
 */

function computeTermGrade(float $classStanding, float $attendance, float $performanceTask, float $termExam): float {
    $cs = $classStanding * 0.35;
    $at = $attendance * 0.05;
    $pt = $performanceTask * 0.20;
    $te = $termExam * 0.40;
    return round($cs + $at + $pt + $te, 2);
}

function computeFinalGrade(float $prelim, float $midterm, float $final): float {
    return round($prelim * 0.30 + $midterm * 0.30 + $final * 0.40, 2);
}

/**
 * Attendance % for grading (0–100): excused counts as full present; each late counts as 2/3 present
 * (3 lates equivalent to 1 absent vs full present). Absent counts as 0.
 */
function attendance_weighted_percent(PDO $db, int $enrollmentId, int $termId): float
{
    $stmt = $db->prepare("
        SELECT COUNT(*) AS total,
               COALESCE(SUM(
                 CASE LOWER(TRIM(status))
                   WHEN 'present' THEN 1
                   WHEN 'excused' THEN 1
                   WHEN 'late' THEN 0.6666666667
                   ELSE 0
                 END
               ), 0) AS credit
        FROM attendance_records
        WHERE enrollment_id = ? AND term_id = ?
    ");
    $stmt->execute([(int) $enrollmentId, (int) $termId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = (int) ($row['total'] ?? 0);
    if ($total <= 0) {
        return 0.0;
    }
    $credit = (float) ($row['credit'] ?? 0);

    return round(($credit / $total) * 100, 2);
}

/**
 * Recompute and save term grade for an enrollment+term from raw records.
 */
function recomputeTermGrade(PDO $db, int $enrollmentId, int $termId): void {
    $enrollmentId = (int) $enrollmentId;
    $termId = (int) $termId;

    // record_types that count as performance task (not class standing)
    $ptTypes = ['values'];

    // Class standing: average of assessment_records excluding performance-task types and exam -> 0-100
    $stmt = $db->prepare("
        SELECT COALESCE(AVG(score / NULLIF(max_score, 0) * 100), 0) as avg_cs
        FROM assessment_records
        WHERE enrollment_id = ? AND term_id = ?
          AND LOWER(record_type) NOT IN ('values', 'exam', 'lab_exam')
    ");
    $stmt->execute([$enrollmentId, $termId]);
    $classStanding = (float) ($stmt->fetch()['avg_cs'] ?? 0);

    // Attendance: excused = present; 3 lates ≡ 1 absent (each late = 2/3 credit)
    $attendance = attendance_weighted_percent($db, $enrollmentId, $termId);

    // Performance task: average of performance_records + assessment_records with record_type 'values' -> 0-100
    $ptScores = [];
    $stmt = $db->prepare("
        SELECT score, max_score FROM performance_records
        WHERE enrollment_id = ? AND term_id = ? AND max_score > 0
    ");
    $stmt->execute([$enrollmentId, $termId]);
    while ($pr = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ptScores[] = (float)$pr['score'] / (float)$pr['max_score'] * 100;
    }

    $ptPlaceholders = implode(',', array_fill(0, count($ptTypes), '?'));
    $stmt = $db->prepare("
        SELECT score, max_score FROM assessment_records
        WHERE enrollment_id = ? AND term_id = ? AND max_score > 0
          AND LOWER(record_type) IN ($ptPlaceholders)
    ");
    $stmt->execute(array_merge([$enrollmentId, $termId], $ptTypes));
    while ($ar = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ptScores[] = (float)$ar['score'] / (float)$ar['max_score'] * 100;
    }

    $performanceTask = !empty($ptScores) ? array_sum($ptScores) / count($ptScores) : 0;

    // Term examination: written exam + optional lab exam (one activity each per term).
    // Combined: (exam_score + lab_exam_score) / (exam_max + lab_exam_max) * 100 when both exist;
    // otherwise single component as score/max*100. Legacy: term_exam_scores if no exam rows.
    $stmt = $db->prepare("
        SELECT score, max_score
        FROM assessment_records
        WHERE enrollment_id = ? AND term_id = ?
          AND LOWER(record_type) = 'exam'
          AND max_score > 0
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$enrollmentId, $termId]);
    $examRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("
        SELECT score, max_score
        FROM assessment_records
        WHERE enrollment_id = ? AND term_id = ?
          AND LOWER(record_type) = 'lab_exam'
          AND max_score > 0
        ORDER BY recorded_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$enrollmentId, $termId]);
    $labExamRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($examRow && $labExamRow) {
        $sumScore = (float) $examRow['score'] + (float) $labExamRow['score'];
        $sumMax = (float) $examRow['max_score'] + (float) $labExamRow['max_score'];
        $termExam = $sumMax > 0 ? ($sumScore / $sumMax) * 100 : 0;
    } elseif ($examRow) {
        $termExam = ((float) $examRow['score'] / (float) $examRow['max_score']) * 100;
    } elseif ($labExamRow) {
        $termExam = ((float) $labExamRow['score'] / (float) $labExamRow['max_score']) * 100;
    } else {
        $stmt = $db->prepare("
            SELECT score, max_score
            FROM term_exam_scores
            WHERE enrollment_id = ? AND term_id = ? AND max_score > 0
            LIMIT 1
        ");
        $stmt->execute([$enrollmentId, $termId]);
        $legacyRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $termExam = $legacyRow
            ? ((float) $legacyRow['score'] / (float) $legacyRow['max_score']) * 100
            : 0;
    }

    $computed = computeTermGrade($classStanding, $attendance, $performanceTask, $termExam);

    $stmt = $db->prepare("
        INSERT INTO term_grades (enrollment_id, term_id, class_standing, attendance, performance_task, term_examination, term_grade_computed)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          class_standing = VALUES(class_standing),
          attendance = VALUES(attendance),
          performance_task = VALUES(performance_task),
          term_examination = VALUES(term_examination),
          term_grade_computed = VALUES(term_grade_computed)
    ");
    $stmt->execute([$enrollmentId, $termId, $classStanding, $attendance, $performanceTask, $termExam, $computed]);
}
