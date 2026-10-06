<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

$uploadUrl = $_SERVER['HTTP_X_UPLOAD_URL'] ?? ($_GET['upload_url'] ?? '');
$contentRange = $_SERVER['HTTP_CONTENT_RANGE'] ?? '';

if (empty($uploadUrl) || empty($contentRange)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing upload parameters.']);
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
