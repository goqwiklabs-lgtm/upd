<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';

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

// 1. Insert file into database
$stmt = $pdo->prepare("
    INSERT INTO files (user_id, folder_id, google_account_id, google_file_id, name, size_bytes, mime_type, share_token, is_public)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
");
$stmt->execute([
    $userId,
    $folderId,
    $googleAccountId,
    $googleFileId,
    $name,
    $size,
    $mimeType,
    $shareToken
]);
$fileId = $pdo->lastInsertId();

// 2. Increment used_storage_bytes for this Google storage account
$updateAcc = $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = used_storage_bytes + ? WHERE id = ?");
$updateAcc->execute([$size, $googleAccountId]);

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
        'is_public' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]
]);
