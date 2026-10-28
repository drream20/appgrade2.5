<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['mis_user_id'])) {
    http_response_code(403);
    echo json_encode([
        "status" => "error",
        "message" => "Enrollment changes are restricted to MIS. Instructors cannot drop or enroll students via this API.",
    ]);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$instructorSubjectId = (int)($data['instructor_subject_id'] ?? 0);
$studentNumber = trim($data['student_number'] ?? $data['studentNumber'] ?? '');
$action = strtolower(trim($data['action'] ?? ''));
$section = trim($data['section'] ?? '');

if (!$instructorSubjectId || $studentNumber === '' || !in_array($action, ['drop', 'enroll'], true)) {
    echo json_encode([
        "status" => "error",
        "message" => "Required: instructor_subject_id, student_number, action (drop or enroll)"
    ]);
    exit;
}

try {
    $studentIdStmt = $conn->prepare("SELECT id FROM students WHERE student_id = ? LIMIT 1");
    $studentIdStmt->execute([$studentNumber]);
    $studentId = $studentIdStmt->fetchColumn();

    if (!$studentId) {
        echo json_encode(["status" => "error", "message" => "Student not found"]);
        exit;
    }

    // Resolve class_section_id if possible so enrollments are section-aware
    $classSectionId = null;
    if ($instructorSubjectId) {
        if ($section !== '') {
            $csStmt = $conn->prepare(
                "SELECT id FROM class_sections WHERE instructor_subject_id = ? AND section = ? LIMIT 1"
            );
            $csStmt->execute([$instructorSubjectId, $section]);
        } else {
            $csStmt = $conn->prepare(
                "SELECT id FROM class_sections WHERE instructor_subject_id = ? LIMIT 1"
            );
            $csStmt->execute([$instructorSubjectId]);
        }
        $classSectionId = $csStmt->fetchColumn();
        if ($classSectionId !== false) {
            $classSectionId = (int)$classSectionId;
        } else {
            $classSectionId = null;
        }
    }

    if ($action === 'drop') {
        if ($section === '') {
            echo json_encode([
                "status" => "error",
                "message" => "Section is required to drop a student"
            ]);
            exit;
        }

        // Resolve class_section_id strictly by subject+section
        if (!$classSectionId) {
            $subStmt = $conn->prepare(
                "SELECT subject_id FROM instructor_subjects WHERE id = ? LIMIT 1"
            );
            $subStmt->execute([$instructorSubjectId]);
            $subjectId = $subStmt->fetchColumn();
            if ($subjectId) {
                $csStmt = $conn->prepare(
                    "SELECT id FROM class_sections WHERE subject_id = ? AND section = ? LIMIT 1"
                );
                $csStmt->execute([(int)$subjectId, $section]);
                $classSectionId = $csStmt->fetchColumn();
                if ($classSectionId !== false) {
                    $classSectionId = (int)$classSectionId;
                } else {
                    $classSectionId = null;
                }
            }
        }

        if (!$classSectionId) {
            echo json_encode([
                "status" => "error",
                "message" => "Class section not found for this subject/section"
            ]);
            exit;
        }

        // Soft-drop: mark enrollment as dropped for this exact section
        $upd = $conn->prepare(
            "UPDATE enrollments SET status = 'dropped'
             WHERE class_section_id = ? AND student_id = ?"
        );
        $upd->execute([$classSectionId, $studentId]);
        $updated = $upd->rowCount();
        echo json_encode([
            "status" => "success",
            "message" => $updated > 0
                ? "Student dropped successfully"
                : "No enrollment found to drop",
            "enrolled" => false
        ]);
    } else {
        $updated = 0;
        if ($classSectionId) {
            $upd = $conn->prepare(
                "UPDATE enrollments SET status = 'enrolled', class_section_id = ?
                 WHERE class_section_id = ? AND student_id = ?"
            );
            $upd->execute([$classSectionId, $classSectionId, $studentId]);
            $updated = $upd->rowCount();
        }
        if ($updated === 0) {
            $upd = $conn->prepare(
                "UPDATE enrollments SET status = 'enrolled' WHERE instructor_subject_id = ? AND student_id = ?"
            );
            $upd->execute([$instructorSubjectId, $studentId]);
            $updated = $upd->rowCount();
        }
        if ($upd->rowCount() > 0) {
            echo json_encode([
                "status" => "success",
                "message" => "Student enrolled successfully",
                "enrolled" => true
            ]);
        } else {
            if ($classSectionId) {
                $ins = $conn->prepare(
                    "INSERT IGNORE INTO enrollments (instructor_subject_id, class_section_id, student_id, status)
                     VALUES (?, ?, ?, 'enrolled')"
                );
                $ins->execute([$instructorSubjectId, $classSectionId, $studentId]);
            } else {
                $ins = $conn->prepare(
                    "INSERT IGNORE INTO enrollments (instructor_subject_id, student_id, status)
                     VALUES (?, ?, 'enrolled')"
                );
                $ins->execute([$instructorSubjectId, $studentId]);
            }
            echo json_encode([
                "status" => "success",
                "message" => $ins->rowCount() > 0 ? "Student enrolled successfully" : "Student already enrolled",
                "enrolled" => true
            ]);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
