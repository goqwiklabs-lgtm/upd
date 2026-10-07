<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$pdo = getDBConnection();
$userId = (int)$_SESSION['user_id'];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$googleAccountId = (int)($data['google_account_id'] ?? 0);
$googleFileId = trim($data['google_file_id'] ?? '');
$name = trim($data['name'] ?? '');
$size = (int)($data['size'] ?? 0);
$mimeType = trim($data['mime_type'] ?? 'application/octet-stream');
$folderId = !empty($data['folder_id']) ? (int)$data['folder_id'] : null;
$shareToken = trim($data['share_token'] ?? '');

if (!$googleAccountId || empty($googleFileId) || empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing upload completion parameters.']);
    exit;
}

if (empty($shareToken)) {
    $shareToken = bin2hex(random_bytes(16));
}

// Determine if parent folder is public
$isFilePublic = 0;
if ($folderId) {
    $fCheck = $pdo->prepare("SELECT is_public FROM folders WHERE id = ?");
    $fCheck->execute([$folderId]);
    $folderRow = $fCheck->fetch();
    if ($folderRow && !empty($folderRow['is_public'])) {
        $isFilePublic = 1;
    }
}

// 1. Insert file into database
$stmt = $pdo->prepare("
    INSERT INTO files (user_id, folder_id, google_account_id, google_file_id, name, size_bytes, mime_type, share_token, is_public)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $userId,
    $folderId,
    $googleAccountId,
    $googleFileId,
    $name,
    $size,
    $mimeType,
    $shareToken,
    $isFilePublic
]);
$fileId = $pdo->lastInsertId();

// 2. Increment used_storage_bytes for this Google storage account
$updateAcc = $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = used_storage_bytes + ? WHERE id = ?");
$updateAcc->execute([$size, $googleAccountId]);

// 3. Unlock Google Edge CDN & Adaptive Video Streaming for instant load
$accStmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
$accStmt->execute([$googleAccountId]);
$accRow = $accStmt->fetch();
if ($accRow) {
    try {
        GoogleDriveManager::setGoogleFilePublic($accRow, $pdo, $googleFileId, true);
    } catch (Exception $e) {
        error_log("Failed to set file reader permission: " . $e->getMessage());
    }
}

// 4. If uploaded file is a video, trigger background multi-resolution transcoding
$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];
if (in_array($ext, $videoExts) || strpos($mimeType, 'video/') === 0) {
    $workerScript = escapeshellarg(__DIR__ . '/../workers/transcode_worker.php');
    @exec("php {$workerScript} " . (int)$fileId . " > /dev/null 2>&1 &");
}

// 5. Automatic Content Analyzer scan
try {
    require_once __DIR__ . '/../services/ContentAnalyzer.php';
    ContentAnalyzer::analyzeFile($pdo, (int)$fileId);
} catch (Exception $e) {
    error_log("ContentAnalyzer error on upload: " . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'file' => [
        'id' => (int)$fileId,
        'user_id' => $userId,
        'folder_id' => $folderId,
        'name' => $name,
        'size_bytes' => $size,
        'mime_type' => $mimeType,
        'share_token' => $shareToken,
        'is_public' => $isFilePublic,
        'created_at' => date('Y-m-d H:i:s'),
    ]
]);
