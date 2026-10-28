<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="enrollment_upload_template.csv"');
$fp = fopen('php://output', 'w');
fprintf($fp, "\xEF\xBB\xBF"); // UTF-8 BOM
fputcsv($fp, ['Student #', 'Last Name', 'Given Name', 'Middle Name', 'Gender', 'Birthday', 'Phone', 'Course', 'Subject ID', 'Section']);
fputcsv($fp, ['37982022', 'PAMINTUAN', 'M-jay', 'Garcia', 'M', '7/16/2003', '9707838564', 'BEED', 'BEED-GEC102', '1BSCRIM-A']);
fputcsv($fp, ['37982022', 'PAMINTUAN', 'M-jay', 'Garcia', 'M', '7/16/2003', '9707838564', 'BEED', 'BEED-GEC103', '1BSCRIM-A']);
fclose($fp);
exit;
