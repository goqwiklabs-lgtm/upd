<?php
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/google.php';
require_once __DIR__ . '/services/VideoTranscoder.php';

$pdo = getDBConnection();

$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    die('Access denied. Your IP has been blocked by administrator.');
}

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tempFileId = isset($_GET['temp_file_id']) ? (int)$_GET['temp_file_id'] : 0;
$token = trim($_GET['token'] ?? '');
$download = !empty($_GET['download']);
$quality = trim($_GET['quality'] ?? '');

$file = null;

if ($tempFileId > 0 && !empty($token)) {
    // Temporary upload access
    $stmt = $pdo->prepare("
        SELECT tf.*, ga.*, tu.expires_at, tu.share_token
        FROM temp_files tf
        JOIN temp_uploads tu ON tf.temp_upload_id = tu.id
        JOIN google_accounts ga ON tf.google_account_id = ga.id
        WHERE tf.id = ? AND tu.share_token = ?
    ");
    $stmt->execute([$tempFileId, $token]);
    $file = $stmt->fetch();

    if ($file && strtotime($file['expires_at']) <= time()) {
        http_response_code(410);
        die('This temporary link has expired and files have been permanently deleted.');
    }
} elseif (!empty($token)) {
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
} elseif ($fileId > 0) {
    // Check if public file
    $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ? AND f.is_public = 1");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
}

if (!$file) {
    http_response_code(404);
    die('File not found or access denied.');
}

// Quarantine / Block Visibility check: Only admin can view quarantined files
if (!empty($file['is_visibility_blocked'])) {
    $isAdmin = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
    if (!$isAdmin) {
        http_response_code(403);
        die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Access Denied</h2><p>This file has been quarantined by administrator for policy violation. You cannot view or download this file.</p></body></html>');
    }
}

// Release session lock immediately so parallel range requests never block
session_write_close();

$mimeType = $file['mime_type'] ?: 'application/octet-stream';
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];
$isVideo = in_array($ext, $videoExts) || strpos($mimeType, 'video/') === 0;

// Multi-Resolution Video Stream Handler
if ($isVideo && !$download && !empty($quality) && $quality !== 'original') {
    $targetFileId = (int)$file['id'];

    if (VideoTranscoder::hasTranscoded($targetFileId, $quality)) {
        VideoTranscoder::serveLocalFile(
            VideoTranscoder::getTranscodedPath($targetFileId, $quality),
            'video/mp4',
            false,
            $file['name']
        );
        exit;
    }

    // Generate on demand if requested quality is not ready yet
    $origPath = VideoTranscoder::ensureOriginalDownloaded($file, $pdo);
    if ($origPath) {
        $transPath = VideoTranscoder::transcode($origPath, $targetFileId, $quality);
        if ($transPath) {
            VideoTranscoder::serveLocalFile($transPath, 'video/mp4', false, $file['name']);
            exit;
        }
    }
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

// High-performance caching headers for browser and mobile
$etag = '"' . md5($googleFileId . '_' . $fileSize) . '"';
header('ETag: ' . $etag);
header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 604800));

// If client already cached the file, return 304 Not Modified immediately in 0ms!
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// Clear any active PHP output buffers to prevent buffering delays
while (ob_get_level()) {
    ob_end_clean();
}

// Prepare headers for client
header('Accept-Ranges: bytes');
header("Content-Type: $mimeType");

$disposition = $download ? 'attachment' : 'inline';
header("Content-Disposition: $disposition; filename=\"" . rawurlencode($fileName) . "\"");

// Check HTTP Range header for fast video/audio seeking
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
    CURLOPT_RETURNTRANSFER => false, // stream directly to client
    CURLOPT_WRITEFUNCTION => function($ch, $chunk) {
        if (connection_aborted()) {
            return 0; // Terminate Google download immediately if client closed or seeked
        }
        echo $chunk;
        flush();
        return strlen($chunk);
    },
    CURLOPT_BUFFERSIZE => 256 * 1024, // 256 KB buffer for instant initial chunk playback
    CURLOPT_TCP_NODELAY => 1,
    CURLOPT_TIMEOUT => 600,
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
