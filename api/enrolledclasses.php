<?php
require_once __DIR__ . "/../config/database.php";
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// 1. Check if studentID is provided
if (!isset($_GET['studentID'])) {
    echo json_encode(["status" => "error", "message" => "Missing studentID"]);
    exit;
}

$studentID = $_GET['studentID'];

// 2. Use Prepared Statements to prevent SQL Injection
$sql = "
    SELECT 
    s.code,
    s.title,
    cs.section,
    COUNT(CASE WHEN e.status = 'enrolled' THEN 1 END) AS enrolled
FROM instructor_subjects isub
JOIN subjects s 
    ON s.id = isub.subject_id
JOIN class_sections cs 
    ON cs.instructor_subject_id = isub.id
LEFT JOIN enrollments e 
    ON e.class_section_id = cs.id
WHERE isub.instructor_id = :id
GROUP BY s.code, s.title, cs.section
ORDER BY s.code, cs.section
";

$stmt = $conn->prepare($sql);
$stmt->bindParam(":studentID", $studentID, PDO::PARAM_INT);
$stmt->execute();
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

$classes = [];

// 3. Check if we have rows (Corrected logic)
if (count($result) > 0) {
    echo json_encode([
        "status" => "success",
        "data" => $result
    ]);
}
?>










