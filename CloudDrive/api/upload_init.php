<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$pdo = getDBConnection();

$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your IP address has been blocked by administrator.']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$fileName = trim($data['name'] ?? '');
$fileSize = (int)($data['size'] ?? 0);
$mimeType = trim($data['mimeType'] ?? 'application/octet-stream');
$folderId = !empty($data['folder_id']) ? (int)$data['folder_id'] : null;

if (empty($fileName) || $fileSize <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file name or size.']);
    exit;
}

// Check user status and per-user quota
$userStmt = $pdo->prepare("SELECT id, is_blocked, storage_limit_bytes FROM users WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$currUser = $userStmt->fetch();

if (!$currUser || !empty($currUser['is_blocked'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Your account has been blocked or suspended by administrator.']);
    exit;
}

if ($currUser['storage_limit_bytes'] !== null && $currUser['storage_limit_bytes'] > 0) {
    $usedStmt = $pdo->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE user_id = ?");
    $usedStmt->execute([$currUser['id']]);
    $usedBytes = (int)$usedStmt->fetchColumn();

    if (($usedBytes + $fileSize) > $currUser['storage_limit_bytes']) {
        http_response_code(403);
        $limitMb = round($currUser['storage_limit_bytes'] / (1024 * 1024), 1);
        $usedMb = round($usedBytes / (1024 * 1024), 1);
        echo json_encode([
            'success' => false,
            'error' => "Storage limit reached! Your limit is {$limitMb} MB (currently used: {$usedMb} MB). Contact admin to increase your storage quota."
        ]);
        exit;
    }
}

// 1. Storage Pool: Find active Google account with available space under 13 GB
$account = GoogleDriveManager::getAvailableStorageAccount($pdo, $fileSize);

if (!$account) {
    // Check if any accounts exist at all
    $totalAccounts = $pdo->query("SELECT COUNT(*) FROM google_accounts WHERE is_active = 1")->fetchColumn();
    if ($totalAccounts == 0) {
        http_response_code(503);
        echo json_encode([
            'success' => false, 
            'error' => 'No active Google Drive storage account configured. Please ask the administrator to connect a Gmail account in the Admin Panel.'
        ]);
        exit;
    }

    http_response_code(507); // Insufficient Storage
    echo json_encode([
        'success' => false, 
        'error' => 'All connected Gmail accounts have reached their 13 GB limit. The administrator needs to connect an additional Gmail account.'
    ]);
    exit;
}

// 2. Extract Client Origin for Google Drive CORS authorization
$clientOrigin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ($data['origin'] ?? '');
if ($clientOrigin) {
    $parsed = parse_url($clientOrigin);
    if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
        $clientOrigin = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
    }
}

// 3. Create Google Drive Resumable Upload Session
$uploadUrl = GoogleDriveManager::createResumableUploadSession($account, $pdo, $fileName, $mimeType, $fileSize, $clientOrigin);

if (!$uploadUrl) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Failed to initialize Google Drive upload session. Please check API credentials.']);
    exit;
}

// 3. Generate unique share token
$shareToken = bin2hex(random_bytes(16));

echo json_encode([
    'success' => true,
    'upload_url' => $uploadUrl,
    'google_account_id' => $account['id'],
    'account_email' => $account['account_email'],
    'share_token' => $shareToken,
]);
