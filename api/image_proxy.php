<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$urlParam = $_GET['url'] ?? '';
$pathParam = $_GET['path'] ?? '';

if ($urlParam === '' && $pathParam === '') {
    http_response_code(400);
    echo "Missing url or path";
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($host === '') {
    http_response_code(500);
    echo "Host not found";
    exit;
}

if ($pathParam !== '') {
    $path = $pathParam;
    if ($path[0] !== '/') $path = '/' . $path;
    $url = $scheme . '://' . $host . $path;
} else {
    $url = $urlParam;
    $parts = parse_url($url);
    if (!$parts || !isset($parts['host']) || $parts['host'] !== $host) {
        http_response_code(400);
        echo "Invalid host";
        exit;
    }
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
$body = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($body === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(404);
    echo "Image not found";
    exit;
}

if ($contentType) {
    header("Content-Type: " . $contentType);
} else {
    header("Content-Type: image/png");
}

echo $body;
