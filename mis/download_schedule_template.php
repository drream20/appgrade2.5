<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/auth.php';
misRequireLogin();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="schedule_upload_template.csv"');
$fp = fopen('php://output', 'w');
fprintf($fp, "\xEF\xBB\xBF");

// New schedule CSV header and sample rows to match your format:
// DAY, START, FINISH, RM, CODE, DESCRIPTION, FACULTY, CYS, TOTAL HOURS, COURSE TYPE, SCHOOL, FACULTY DEPT, FULL NAME
fputcsv($fp, [
    'DAY',
    'START',
    'FINISH',
    'RM',
    'CODE',
    'DESCRIPTION',
    'FACULTY',
    'CYS',
    'TOTAL HOURS',
    'COURSE TYPE',
    'SCHOOL',
    'FACULTY DEPT',
    'FULL NAME',
]);

// Sample rows (you can edit values after download)
fputcsv($fp, [
    'S',
    '8:00',
    '10:00',
    'L2205',
    'PATHF1A',
    'Sports, Martial Arts, Outdoor and Adventure Activities',
    'instructor@example.com',
    '2BEED-A',
    '27',
    'Major',
    'STE',
    'SBAA',
    'ADRIANO, ALLEN C.',
]);

fputcsv($fp, [
    'M',
    '14:00',
    '16:00',
    'L3005',
    'PATHF1Z',
    'Exercise Based Fitness Activities',
    'instructor@example.com',
    '1BSEDSMF-A',
    '27',
    'Major',
    'STE',
    'SBAA',
    'ADRIANO, ALLEN C.',
]);

fclose($fp);
exit;
