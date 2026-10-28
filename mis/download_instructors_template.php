<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="instructors_upload_template.csv"');

$fp = fopen('php://output', 'w');
fprintf($fp, "\xEF\xBB\xBF"); // UTF-8 BOM

// Header row must match expected order in instructors upload:
// employee_id, email, firstname, middlename, lastname, department, phone, address
fputcsv($fp, ['employee_id', 'email', 'firstname', 'middlename', 'lastname', 'department', 'phone', 'address']);

// Example rows
fputcsv($fp, ['12345', 'jane.doe@example.com', 'Jane', 'A.', 'Doe', 'BSCS', '09123456789', '123 Main St, City']);
fputcsv($fp, ['67890', 'mark.smith@example.com', 'Mark', '', 'Smith', 'BSEdEng', '', '']);

fclose($fp);
exit;

