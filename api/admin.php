<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden. Admin privileges required.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// 1. STATS OVERVIEW
if ($action === 'stats') {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $totalFiles = (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $totalAccounts = (int)$pdo->query("SELECT COUNT(*) FROM google_accounts WHERE is_active = 1")->fetchColumn();
    
    $storageRow = $pdo->query("SELECT COALESCE(SUM(used_storage_bytes), 0) as used, COALESCE(SUM(storage_limit_bytes), 0) as total FROM google_accounts WHERE is_active = 1")->fetch();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_users' => $totalUsers,
            'total_files' => $totalFiles,
            'active_google_accounts' => $totalAccounts,
            'pool_used_bytes' => (int)($storageRow['used'] ?? 0),
            'pool_total_bytes' => (int)($storageRow['total'] ?? 0),
        ]
    ]);
    exit;
}

// 2. LIST GOOGLE ACCOUNTS
if ($action === 'accounts_list') {
    $stmt = $pdo->query("
        SELECT g.id, g.account_email, g.client_id, g.used_storage_bytes, g.storage_limit_bytes, g.is_active, g.created_at,
               COUNT(f.id) as files_count
        FROM google_accounts g
        LEFT JOIN files f ON f.google_account_id = g.id
        GROUP BY g.id
        ORDER BY g.id ASC
    ");
    $accounts = $stmt->fetchAll();
    echo json_encode(['success' => true, 'accounts' => $accounts]);
    exit;
}

// 3. ADD GOOGLE ACCOUNT
if ($action === 'account_add') {
    $email = trim(strtolower($data['account_email'] ?? ''));
    $clientId = trim($data['client_id'] ?? '');
    $clientSecret = trim($data['client_secret'] ?? '');
    $refreshToken = trim($data['refresh_token'] ?? '');

    if (empty($email) || empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide Account Email, Client ID, Client Secret, and Refresh Token.']);
        exit;
    }

    // Test credentials by requesting a fresh access token
    $tempAccount = [
        'id' => 0,
        'account_email' => $email,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ];
    $accessToken = GoogleDriveManager::refreshAccessToken($tempAccount, $pdo);

    if (!$accessToken) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'error' => 'Could not connect to Google Drive with these credentials. Please check your Client ID, Secret, and Refresh Token.'
        ]);
        exit;
    }

    // Default limit: 13 GB (13958643712 bytes)
    $limitBytes = 13 * 1024 * 1024 * 1024;

    $stmt = $pdo->prepare("
        INSERT INTO google_accounts (account_email, client_id, client_secret, refresh_token, access_token, token_expires_at, used_storage_bytes, storage_limit_bytes, is_active)
        VALUES (?, ?, ?, ?, ?, ?, 0, ?, 1)
    ");
    $stmt->execute([
        $email,
        $clientId,
        $clientSecret,
        $refreshToken,
        $accessToken,
        time() + 3500,
        $limitBytes
    ]);
    $newId = $pdo->lastInsertId();

    // Sync actual live usage from Google Drive
    $tempAccount['id'] = $newId;
    $tempAccount['access_token'] = $accessToken;
    $tempAccount['token_expires_at'] = time() + 3500;
    GoogleDriveManager::syncGoogleDriveQuota($tempAccount, $pdo);

    echo json_encode(['success' => true, 'account_id' => $newId]);
    exit;
}

// 4. TOGGLE ACCOUNT ACTIVE/INACTIVE
if ($action === 'account_toggle') {
    $id = (int)($data['id'] ?? 0);
    $active = !empty($data['is_active']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE google_accounts SET is_active = ? WHERE id = ?");
    $stmt->execute([$active, $id]);

    echo json_encode(['success' => true]);
    exit;
}

// 5. SYNC QUOTA WITH GOOGLE DRIVE LIVE
if ($action === 'account_sync') {
    $id = (int)($data['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
    $stmt->execute([$id]);
    $acc = $stmt->fetch();

    if (!$acc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Account not found.']);
        exit;
    }

    $usage = GoogleDriveManager::syncGoogleDriveQuota($acc, $pdo);
    echo json_encode(['success' => true, 'used_storage_bytes' => $usage]);
    exit;
}

// 6. DELETE GOOGLE ACCOUNT
if ($action === 'account_delete') {
    $id = (int)($data['id'] ?? 0);
    
    // Check if files exist on this account
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE google_account_id = ?");
    $stmt->execute([$id]);
    $fileCount = (int)$stmt->fetchColumn();

    if ($fileCount > 0 && empty($data['force'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "This account currently stores $fileCount files. Disconnect or delete those files first, or pass force=true."
        ]);
        exit;
    }

    $pdo->prepare("DELETE FROM google_accounts WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// 7. LIST ALL USERS
if ($action === 'all_users') {
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, u.role, u.created_at,
               COUNT(f.id) as files_count,
               COALESCE(SUM(f.size_bytes), 0) as total_bytes
        FROM users u
        LEFT JOIN files f ON f.user_id = u.id
        GROUP BY u.id
        ORDER BY u.id ASC
    ");
    $users = $stmt->fetchAll();
    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// 8. LIST ALL FILES ACROSS THE ENTIRE PLATFORM
if ($action === 'all_files') {
    $stmt = $pdo->query("
        SELECT f.*, u.username, u.email as user_email, g.account_email as google_email
        FROM files f
        JOIN users u ON f.user_id = u.id
        JOIN google_accounts g ON f.google_account_id = g.id
        ORDER BY f.created_at DESC
        LIMIT 200
    ");
    $files = $stmt->fetchAll();
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Invalid admin action.']);
