<?php
// ========================================================
// Background Transcoding Worker (CLI)
// Multi-Resolution Video Generation (4K, 1440p, 1080p, 720p, 480p, 240p, 144p)
// ========================================================

if (php_sapi_name() !== 'cli') {
    die('CLI only.');
}

$fileId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($fileId <= 0) {
    die("Usage: php transcode_worker.php <file_id>\n");
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';
require_once __DIR__ . '/../services/VideoTranscoder.php';

$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    die("File ID {$fileId} not found.\n");
}

$mime = $file['mime_type'] ?: '';
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];

if (!in_array($ext, $videoExts) && strpos($mime, 'video/') !== 0) {
    die("File is not a video.\n");
}

echo "Starting transcoding for file ID {$fileId} ({$file['name']})...\n";

$origPath = VideoTranscoder::ensureOriginalDownloaded($file, $pdo);
if (!$origPath) {
    die("Failed to download or locate original source video.\n");
}

$probe = VideoTranscoder::probeVideo($origPath);
$height = $probe['height'] ?? 720;
$width = $probe['width'] ?? 1280;

$stmtH = $pdo->prepare("UPDATE files SET video_height = ? WHERE id = ?");
$stmtH->execute([$height, $fileId]);

echo "Detected video dimensions: {$width}x{$height}.\n";

$qualities = VideoTranscoder::getAvailableQualities($height);
echo "Target qualities: " . implode(', ', $qualities) . "\n";

foreach ($qualities as $q) {
    if ($q === 'original' || $q === 'auto') continue;
    echo "Processing {$q}...\n";
    $result = VideoTranscoder::transcode($origPath, $fileId, $q);
    if ($result) {
        echo "Ready: {$q} (" . round(filesize($result) / 1024) . " KB)\n";
    } else {
        echo "Skipped or error: {$q}\n";
    }
}

echo "Finished transcoding for file ID {$fileId}.\n";
