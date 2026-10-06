<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/google.php';

$pdo = getDBConnection();

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = trim($_GET['token'] ?? '');
$download = !empty($_GET['download']);

$file = null;

if (!empty($token)) {
    // Access by share token
    $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.share_token = ?");
    $stmt->execute([$token]);
    $file = $stmt->fetch();
} elseif ($fileId > 0 && isset($_SESSION['user_id'])) {
    // Access by logged in user
    $userId = (int)$_SESSION['user_id'];
    $role = $_SESSION['role'] ?? 'user';

    if ($role === 'admin') {
        $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ?");
        $stmt->execute([$fileId]);
    } else {
        $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ? AND f.user_id = ?");
        $stmt->execute([$fileId, $userId]);
    }
    $file = $stmt->fetch();
}

if (!$file) {
    http_response_code(404);
    die('File not found or access denied.');
}

// Get valid Google access token
$accessToken = GoogleDriveManager::getValidAccessToken($file, $pdo);
if (!$accessToken) {
    http_response_code(502);
    die('Failed to authorize with Google Drive.');
}

$googleFileId = $file['google_file_id'];
$fileName = $file['name'];
$fileSize = (int)$file['size_bytes'];
$mimeType = $file['mime_type'] ?: 'application/octet-stream';

// Prepare headers for client
header('Accept-Ranges: bytes');
header("Content-Type: $mimeType");

$disposition = $download ? 'attachment' : 'inline';
header("Content-Disposition: $disposition; filename=\"" . rawurlencode($fileName) . "\"");

// Check HTTP Range header for video seeking
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
$headers = [
    'Authorization: Bearer ' . $accessToken,
];

if ($rangeHeader) {
    $headers[] = 'Range: ' . $rangeHeader;
    http_response_code(206); // Partial content
}

$url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}?alt=media";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => false, // stream directly to output
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
        echo $chunk;
        flush();
        return strlen($chunk);
    },
    CURLOPT_BUFFERSIZE => 256 * 1024, // 256 KB buffer for fast streaming
    CURLOPT_TIMEOUT => 300,
]);

// If Range header was present, pass along Content-Range
if ($rangeHeader && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $matches)) {
    $start = (int)$matches[1];
    $end = !empty($matches[2]) ? (int)$matches[2] : ($fileSize - 1);
    $length = $end - $start + 1;
    header("Content-Range: bytes $start-$end/$fileSize");
    header("Content-Length: $length");
} else {
    header("Content-Length: $fileSize");
}

curl_exec($ch);
curl_close($ch);
exit;
