<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/../config/database.php";
include_once __DIR__ . '/../config/database.php';
$conn = getDb();

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

// Check if JSON decode failed
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON: " . json_last_error_msg(),
        "received" => $rawInput
    ]);
    exit;
}

$subject_ID = $data['subject_ID'] ?? null;
$term = $data['term'] ?? null;
$date = $data['date'] ?? null;
$attendanceId = $data['attendance_id'] ?? ($data['id'] ?? null);

if (!$subject_ID || !$term || (!$date && !$attendanceId)) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Missing required fields: subject_ID, term, and date or attendance_id are required"
    ]);
    exit;
}

try {
    // Resolve date/time from attendance_id if provided
    $attendanceTime = null;
    if ($attendanceId !== null) {
        $stmt = $conn->prepare("SELECT attendance_date, attendance_time FROM attendance_records WHERE id = ?");
        $stmt->execute([(int)$attendanceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(["status"=>"success","message"=>"No attendance records found to delete","deleted_rows"=>0]);
            exit;
        }
        $date = $row['attendance_date'];
        $attendanceTime = $row['attendance_time'];
    }

    // Convert date to proper format if needed (YYYY-MM-DD)
    $attendanceDate = date('Y-m-d', strtotime($date));
    
    // Validate date conversion
    if ($attendanceDate === false || $attendanceDate === '1970-01-01') {
        throw new Exception("Invalid date format: $date");
    }
    
    $conn->beginTransaction();
    
    // Delete all attendance records for this class, term, and date
    // Using Subject_ID (capital S) to match table structure
        // Find enrollments for this subject/section and delete matching attendance_records
        $enrollStmt = $conn->prepare("SELECT e.id FROM enrollments e
            WHERE e.instructor_subject_id IN (SELECT id FROM instructor_subjects WHERE subject_id = :sub)
              OR e.class_section_id IN (SELECT id FROM class_sections WHERE subject_id = :sub AND section = :section)");
        $enrollStmt->execute([':sub' => $subject_ID, ':section' => $data['section'] ?? null]);
        $enrollRows = $enrollStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($enrollRows)) {
            echo json_encode(["status"=>"success","message"=>"No attendance records found to delete","deleted_rows"=>0]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($enrollRows), '?'));
        if ($attendanceId !== null) {
            $timePart = $attendanceTime ?? '00:00:00';
            $params = array_merge($enrollRows, [(int)$term, $attendanceDate, $timePart, $timePart]);
            $stmt = $conn->prepare("DELETE FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? AND attendance_date = ? AND (attendance_time = ? OR (? = '00:00:00' AND attendance_time IS NULL))");
            $stmt->execute($params);
        } else {
            $params = array_merge($enrollRows, [(int)$term, $attendanceDate]);
            $stmt = $conn->prepare("DELETE FROM attendance_records WHERE enrollment_id IN ($placeholders) AND term_id = ? AND attendance_date = ?");
            $stmt->execute($params);
        }
        $deletedRows = $stmt->rowCount();
    
    $conn->commit();

    if ($deletedRows > 0) {
        echo json_encode([
            "status" => "success",
            "message" => "Attendance deleted successfully",
            "deleted_rows" => $deletedRows
        ]);
    } else {
        // Return success even if no rows deleted, as the record might not exist
        echo json_encode([
            "status" => "success",
            "message" => "No attendance records found to delete (may have already been deleted)",
            "deleted_rows" => 0
        ]);
    }

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
