<?php
// CORS & Preflight Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Content-Range, X-Upload-Url, Authorization");
header("Access-Control-Expose-Headers: Range, Content-Range, Content-Length");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

header('Content-Type: application/json');

$allHeaders = function_exists('getallheaders') ? getallheaders() : [];

$uploadUrl = $_GET['upload_url'] 
    ?? ($_SERVER['HTTP_X_UPLOAD_URL'] ?? ($allHeaders['X-Upload-Url'] ?? ($allHeaders['x-upload-url'] ?? '')));

$contentRange = $_SERVER['HTTP_CONTENT_RANGE'] 
    ?? ($_SERVER['CONTENT_RANGE'] 
    ?? ($_SERVER['HTTP_X_CONTENT_RANGE'] 
    ?? ($allHeaders['Content-Range'] 
    ?? ($allHeaders['content-range'] 
    ?? ($allHeaders['X-Content-Range'] 
    ?? ($allHeaders['x-content-range'] 
    ?? ($_GET['range'] ?? '')))))));

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

$resHeaders = [];
$ch = curl_init($uploadUrl);
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PUT',
    CURLOPT_POSTFIELDS => $chunkData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADERFUNCTION => function($c, $h) use (&$resHeaders) {
        $len = strlen($h);
        $parts = explode(':', $h, 2);
        if (count($parts) === 2) {
            $resHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    },
    CURLOPT_HTTPHEADER => [
        'Content-Range: ' . $contentRange,
        'Content-Length: ' . $chunkLength,
    ],
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (isset($resHeaders['range'])) {
    header('Range: ' . $resHeaders['range']);
}

http_response_code($httpCode ?: 200);
echo $response;
exit;
