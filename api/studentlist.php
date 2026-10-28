<?php
require_once __DIR__ . "/../config/database.php";
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Prefer instructor_subject_id when provided (most accurate),
// else fall back to subject code + section (required)
$instructorSubjectId = isset($_GET['instructor_subject_id']) ? (int) $_GET['instructor_subject_id'] : 0;
$subjectCode = $_GET['subjectID'] ?? '';
$section = $_GET['section'] ?? ''; // e.g., "A", "B", etc.

try {
    if ($instructorSubjectId > 0) {
        if (empty($section)) {
            echo json_encode([
                "status" => "error",
                "message" => "Missing section for instructor_subject_id"
            ]);
            exit;
        }
        // Strict: only students in this instructor_subject_id AND section
        $stmt = $conn->prepare("
            SELECT 
                e.id            AS enrollment_id,
                e.status        AS enrollment_status,
                st.student_id   AS student_number,
                st.full_name    AS full_name,
                st.gender       AS gender
            FROM enrollments e
            JOIN students st ON st.id = e.student_id
            JOIN class_sections cs ON cs.id = e.class_section_id
            WHERE cs.instructor_subject_id = :isub
              AND cs.section = :section
            ORDER BY st.full_name
        ");
        $stmt->execute([
            ':isub' => $instructorSubjectId,
            ':section' => $section
        ]);
    } elseif ($subjectCode !== '') {
        if (empty($section)) {
            echo json_encode([
                "status" => "error",
                "message" => "Missing section for subjectID"
            ]);
            exit;
        }
        // Strict: only students in this subject code AND section
        $stmt = $conn->prepare("
            SELECT 
                e.id            AS enrollment_id,
                e.status        AS enrollment_status,
                st.student_id   AS student_number,
                st.full_name    AS full_name,
                st.gender       AS gender
            FROM enrollments e
            JOIN students st ON st.id = e.student_id
            JOIN class_sections cs ON cs.id = e.class_section_id
            JOIN instructor_subjects isub ON isub.id = cs.instructor_subject_id
            JOIN subjects s ON s.id = isub.subject_id
            WHERE s.code = :code
              AND cs.section = :section
            ORDER BY st.full_name
        ");
        $stmt->execute([
            ':code' => $subjectCode,
            ':section' => $section
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Missing instructor_subject_id or subjectID"
        ]);
        exit;
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $students = [];
    $seenStudentNumbers = [];

    foreach ($rows as $row) {
        $studentNumber = $row['student_number'];

        // Only add if not already added
        if (!in_array($studentNumber, $seenStudentNumbers, true)) {
            $status = strtolower(trim((string)($row['enrollment_status'] ?? 'enrolled')));
            if ($status !== 'enrolled' && $status !== 'dropped') {
                $status = 'enrolled';
            }
            $students[] = [
                "enrollment_id" => (int)$row['enrollment_id'],
                "studentNumber" => $studentNumber,
                "fullname"      => $row['full_name'],
                "gender"        => $row['gender'],
                "status"        => ucfirst($status),
                "enrollment_status" => ucfirst($status),
                "enrolled"      => $status === 'enrolled',
            ];
            $seenStudentNumbers[] = $studentNumber;
        }
    }

    echo json_encode([
        "status" => empty($students) ? "empty" : "success",
        "data"   => $students
    ]);

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
