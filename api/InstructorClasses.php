<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/../config/database.php";

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_GET['InstructorID'])) {
    echo json_encode(["status" => "error", "message" => "Missing InstructorID"]);
    exit;
}

$InstructorID = (int)$_GET['InstructorID'];

$sql = "
SELECT
    isub.id as instructor_subject_id,
    s.code,
    s.title,
    s.description,
    s.id,
    cs.section,
    COUNT(CASE WHEN e.status = 'enrolled' THEN 1 END) AS enrolled
FROM instructor_subjects isub
JOIN subjects s 
    ON s.id = isub.subject_id
JOIN class_sections cs 
    ON cs.instructor_subject_id = isub.id
JOIN academic_years ay ON ay.id = cs.academic_year_id
LEFT JOIN enrollments e 
    ON e.class_section_id = cs.id
WHERE isub.instructor_id = :id
AND ay.is_active = 1
GROUP BY s.code, s.title, cs.section
ORDER BY s.code, cs.section
";

$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $InstructorID]);

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    "status" => "success",
    "data" => $data
]);
