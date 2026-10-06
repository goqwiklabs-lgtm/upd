<?php
// CORS & Preflight Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Content-Range, X-Upload-Url, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json');

$uploadUrl = $_GET['upload_url'] ?? ($_SERVER['HTTP_X_UPLOAD_URL'] ?? '');
$contentRange = $_SERVER['HTTP_CONTENT_RANGE'] ?? ($_GET['range'] ?? '');

if (empty($uploadUrl) || empty($contentRange)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing upload parameters.']);
    exit;
}

// Security: strictly verify destination is Google Drive API
if (strpos($uploadUrl, 'https://www.googleapis.com/upload/drive/v3/') !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized upload destination.']);
    exit;
}

// Read raw chunk stream
$chunkData = file_get_contents('php://input');
$chunkLength = strlen($chunkData);

$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $chunkData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Range: ' . $contentRange,
        'Content-Length: ' . $chunkLength,
    ],
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($httpCode ?: 200);
echo $response;
exit;
