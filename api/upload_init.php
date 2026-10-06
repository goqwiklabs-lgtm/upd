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

// 2. Create Google Drive Resumable Upload Session
$uploadUrl = GoogleDriveManager::createResumableUploadSession($account, $pdo, $fileName, $mimeType, $fileSize);

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
