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
require_once __DIR__ . '/../services/ContentAnalyzer.php';

$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? 'stats';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

/**
 * Helper: Permanently delete a single file (from Google Drive + DB + adjust quota)
 */
function adminDeleteSingleFile(PDO $pdo, int $fileId): bool {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    if (!$file) return false;

    // Retrieve Google Account
    $accStmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
    $accStmt->execute([$file['google_account_id']]);
    $account = $accStmt->fetch();

    if ($account && !empty($file['google_file_id'])) {
        try {
            GoogleDriveManager::deleteGoogleFile($account, $pdo, $file['google_file_id']);
        } catch (Exception $e) {
            error_log("Error deleting Google Drive file: " . $e->getMessage());
        }
        $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = MAX(0, used_storage_bytes - ?) WHERE id = ?")
            ->execute([(int)$file['size_bytes'], $account['id']]);
    }

    $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$fileId]);
    return true;
}

/**
 * Helper: Recursively delete a folder and all nested files and child folders
 */
function adminDeleteFolderRecursively(PDO $pdo, int $folderId): bool {
    // 1. Delete all direct files
    $files = $pdo->prepare("SELECT id FROM files WHERE folder_id = ?");
    $files->execute([$folderId]);
    foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $fId) {
        adminDeleteSingleFile($pdo, (int)$fId);
    }

    // 2. Find and recurse child folders
    $children = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ?");
    $children->execute([$folderId]);
    foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        adminDeleteFolderRecursively($pdo, (int)$childId);
    }

    // 3. Delete this folder
    $pdo->prepare("DELETE FROM folders WHERE id = ?")->execute([$folderId]);
    return true;
}

// ========================================================
// 1. STATS OVERVIEW
// ========================================================
if ($action === 'stats') {
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $blockedUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_blocked = 1")->fetchColumn();
    $activeUsers = max(0, $totalUsers - $blockedUsers);

    $totalFiles = (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $totalFolders = (int)$pdo->query("SELECT COUNT(*) FROM folders")->fetchColumn();
    $totalAccounts = (int)$pdo->query("SELECT COUNT(*) FROM google_accounts WHERE is_active = 1")->fetchColumn();

    // Shared links count
    $pubFiles = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_public = 1")->fetchColumn();
    $pubFolders = (int)$pdo->query("SELECT COUNT(*) FROM folders WHERE is_public = 1")->fetchColumn();
    $tempActive = (int)$pdo->query("SELECT COUNT(*) FROM temp_uploads WHERE expires_at > CURRENT_TIMESTAMP")->fetchColumn();
    $sharedLinksCount = $pubFiles + $pubFolders + $tempActive;

    $storageRow = $pdo->query("SELECT COALESCE(SUM(used_storage_bytes), 0) as used, COALESCE(SUM(storage_limit_bytes), 0) as total FROM google_accounts WHERE is_active = 1")->fetch();

    $blockedIpsCount = (int)$pdo->query("SELECT COUNT(*) FROM blocked_ips")->fetchColumn();

    // Live visitors online (last 2 minutes)
    $liveVisitorsCount = (int)$pdo->query("SELECT COUNT(*) FROM live_visitors WHERE last_active_at >= datetime('now', '-2 minutes')")->fetchColumn();
    
    // Quarantined / Blocked visibility files
    $quarantinedCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_visibility_blocked = 1")->fetchColumn();

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'blocked_users' => $blockedUsers,
            'total_files' => $totalFiles,
            'total_folders' => $totalFolders,
            'shared_links_count' => $sharedLinksCount,
            'active_google_accounts' => $totalAccounts,
            'pool_used_bytes' => (int)($storageRow['used'] ?? 0),
            'pool_total_bytes' => (int)($storageRow['total'] ?? 0),
            'blocked_ips_count' => $blockedIpsCount,
            'live_visitors_count' => $liveVisitorsCount,
            'quarantined_files_count' => $quarantinedCount,
        ]
    ]);
    exit;
}

// ========================================================
// 1B. ADVANCED ANALYTICS & INFOGRAPHICS STATS (FOR CHARTS)
// ========================================================
if ($action === 'analytics_stats') {
    // 1. Connected Gmail accounts breakdown
    $accounts = $pdo->query("
        SELECT id, account_email, used_storage_bytes, storage_limit_bytes, total_capacity_bytes, is_active
        FROM google_accounts
        ORDER BY id ASC
    ")->fetchAll();

    // 2. File types distribution
    $typeRows = $pdo->query("
        SELECT 
            CASE 
                WHEN mime_type LIKE 'image/%' THEN 'Images'
                WHEN mime_type LIKE 'video/%' THEN 'Videos'
                WHEN mime_type LIKE 'audio/%' THEN 'Audio'
                WHEN mime_type LIKE '%pdf%' OR mime_type LIKE '%document%' OR mime_type LIKE '%text%' OR mime_type LIKE '%sheet%' OR mime_type LIKE '%presentation%' OR mime_type LIKE '%msword%' THEN 'Documents'
                WHEN mime_type LIKE '%zip%' OR mime_type LIKE '%rar%' OR mime_type LIKE '%tar%' OR mime_type LIKE '%7z%' OR mime_type LIKE '%compressed%' THEN 'Archives'
                ELSE 'Other'
            END as category,
            COUNT(*) as count,
            COALESCE(SUM(size_bytes), 0) as total_bytes
        FROM files
        GROUP BY category
    ")->fetchAll();

    // 3. Upload activity over last 7 days
    $activityRows = $pdo->query("
        SELECT DATE(created_at) as upload_date, COUNT(*) as file_count, COALESCE(SUM(size_bytes), 0) as size_bytes
        FROM files
        WHERE created_at >= DATE('now', '-7 days')
        GROUP BY DATE(created_at)
        ORDER BY upload_date ASC
    ")->fetchAll();

    echo json_encode([
        'success' => true,
        'analytics' => [
            'accounts' => $accounts,
            'file_types' => $typeRows,
            'activity' => $activityRows,
        ]
    ]);
    exit;
}

// ========================================================
// 2. LIST GOOGLE ACCOUNTS
// ========================================================
if ($action === 'accounts_list') {
    $stmt = $pdo->query("
        SELECT g.id, g.account_email, g.client_id, g.used_storage_bytes, g.storage_limit_bytes, 
               g.total_capacity_bytes, g.initial_used_bytes, g.drive_folder_id, g.is_active, g.created_at,
               COUNT(f.id) as files_count
        FROM google_accounts g
        LEFT JOIN files f ON f.google_account_id = g.id
        GROUP BY g.id
        ORDER BY g.id ASC
    ");
    $accounts = $stmt->fetchAll();

    $settingsRows = $pdo->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $masterClientId = $settingsRows['google_client_id'] ?? '';
    $masterClientSecret = $settingsRows['google_client_secret'] ?? '';

    echo json_encode([
        'success' => true, 
        'accounts' => $accounts,
        'master_settings' => [
            'client_id' => $masterClientId,
            'has_client_secret' => !empty($masterClientSecret),
        ]
    ]);
    exit;
}

// ========================================================
// 2B. SAVE MASTER OAUTH CREDENTIALS
// ========================================================
if ($action === 'save_master_settings') {
    $clientId = trim($data['client_id'] ?? '');
    $clientSecret = trim($data['client_secret'] ?? '');

    if (!empty($clientId)) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key_name, key_value) VALUES ('google_client_id', ?)")->execute([$clientId]);
    }
    if (!empty($clientSecret)) {
        $pdo->prepare("INSERT OR REPLACE INTO settings (key_name, key_value) VALUES ('google_client_secret', ?)")->execute([$clientSecret]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ========================================================
// 3. SMART ONE-KEY ACCOUNT ADD
// ========================================================
if ($action === 'account_add_key') {
    $keyInput = trim($data['key'] ?? $data['key_data'] ?? '');
    if (empty($keyInput)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please paste a Google Drive Key, Refresh Token, or Credentials JSON.']);
        exit;
    }

    $settingsRows = $pdo->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $masterClientId = $settingsRows['google_client_id'] ?? '';
    $masterClientSecret = $settingsRows['google_client_secret'] ?? '';

    $email = '';
    $clientId = $masterClientId;
    $clientSecret = $masterClientSecret;
    $refreshToken = '';

    $decoded = json_decode($keyInput, true);
    if (is_array($decoded)) {
        if (!empty($decoded['web'])) {
            $clientId = $decoded['web']['client_id'] ?? $clientId;
            $clientSecret = $decoded['web']['client_secret'] ?? $clientSecret;
            $refreshToken = $decoded['web']['refresh_token'] ?? $refreshToken;
        } elseif (!empty($decoded['refresh_token'])) {
            $refreshToken = $decoded['refresh_token'];
            $clientId = $decoded['client_id'] ?? $clientId;
            $clientSecret = $decoded['client_secret'] ?? $clientSecret;
            $email = $decoded['email'] ?? $email;
        } elseif (!empty($decoded['type']) && $decoded['type'] === 'service_account') {
            $email = $decoded['client_email'] ?? '';
            $clientId = $decoded['client_id'] ?? 'service_account';
            $clientSecret = 'service_account';
            $refreshToken = $keyInput;
        }
    } else {
        if (strpos($keyInput, '|') !== false) {
            $parts = explode('|', $keyInput);
            if (count($parts) >= 4) {
                $email = trim($parts[0]);
                $clientId = trim($parts[1]);
                $clientSecret = trim($parts[2]);
                $refreshToken = trim($parts[3]);
            } elseif (count($parts) === 2) {
                $email = trim($parts[0]);
                $refreshToken = trim($parts[1]);
            }
        } else {
            $refreshToken = $keyInput;
        }
    }

    if (empty($clientId) || empty($clientSecret)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Google Client ID and Client Secret are not configured yet. Please enter them under Master Project Credentials or paste the credentials JSON.'
        ]);
        exit;
    }

    if (empty($refreshToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No valid Refresh Token found in the pasted key.']);
        exit;
    }

    $tempAccount = [
        'id' => 0,
        'account_email' => $email ?: 'detecting@gmail.com',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ];

    $accessToken = GoogleDriveManager::refreshAccessToken($tempAccount, $pdo);
    if (!$accessToken) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to connect with Google using this key. Please check your credentials.'
        ]);
        exit;
    }

    $ch = curl_init('https://www.googleapis.com/drive/v3/about?fields=user,storageQuota');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 15,
    ]);
    $aboutRes = curl_exec($ch);
    curl_close($ch);
    $aboutData = json_decode($aboutRes, true);

    $detectedEmail = $aboutData['user']['emailAddress'] ?? $email;
    if (empty($detectedEmail)) {
        $detectedEmail = 'storage_' . time() . '@gmail.com';
    }

    $totalCapacity = (int)($aboutData['storageQuota']['limit'] ?? (5 * 1024 * 1024 * 1024 * 1024));
    if ($totalCapacity <= 0) $totalCapacity = 15 * 1024 * 1024 * 1024;

    $currentUsed = (int)($aboutData['storageQuota']['usage'] ?? 0);
    $twoGb = 2 * 1024 * 1024 * 1024;
    $remaining = max(0, $totalCapacity - $currentUsed);
    $systemAllowedLimit = max(0, $remaining - $twoGb);

    $existing = $pdo->prepare("SELECT id, drive_folder_id FROM google_accounts WHERE account_email = ?");
    $existing->execute([$detectedEmail]);
    $accRow = $existing->fetch();

    if ($accRow) {
        $upd = $pdo->prepare("
            UPDATE google_accounts 
            SET client_id = ?, client_secret = ?, refresh_token = ?, access_token = ?, token_expires_at = ?,
                total_capacity_bytes = ?, initial_used_bytes = ?, storage_limit_bytes = ?, is_active = 1
            WHERE id = ?
        ");
        $upd->execute([$clientId, $clientSecret, $refreshToken, $accessToken, time() + 3500, $totalCapacity, $currentUsed, $systemAllowedLimit, $accRow['id']]);
        $newId = $accRow['id'];
        $tempAccount['id'] = $newId;
        $tempAccount['drive_folder_id'] = $accRow['drive_folder_id'];
    } else {
        $ins = $pdo->prepare("
            INSERT INTO google_accounts (account_email, client_id, client_secret, refresh_token, access_token, token_expires_at, used_storage_bytes, storage_limit_bytes, total_capacity_bytes, initial_used_bytes, is_active)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, 1)
        ");
        $ins->execute([
            $detectedEmail,
            $clientId,
            $clientSecret,
            $refreshToken,
            $accessToken,
            time() + 3500,
            $systemAllowedLimit,
            $totalCapacity,
            $currentUsed,
        ]);
        $newId = $pdo->lastInsertId();
        $tempAccount['id'] = $newId;
    }

    $folderId = GoogleDriveManager::getOrCreateCloudDriveFolder($tempAccount, $pdo);

    echo json_encode([
        'success' => true,
        'account_id' => $newId,
        'account_email' => $detectedEmail,
        'total_capacity_bytes' => $totalCapacity,
        'initial_used_bytes' => $currentUsed,
        'storage_limit_bytes' => $systemAllowedLimit,
        'drive_folder_id' => $folderId,
    ]);
    exit;
}

// ========================================================
// 4. TOGGLE / SYNC / DELETE GOOGLE ACCOUNT
// ========================================================
if ($action === 'account_toggle') {
    $id = (int)($data['id'] ?? 0);
    $active = !empty($data['is_active']) ? 1 : 0;
    $pdo->prepare("UPDATE google_accounts SET is_active = ? WHERE id = ?")->execute([$active, $id]);
    echo json_encode(['success' => true]);
    exit;
}

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
    $quota = GoogleDriveManager::syncGoogleDriveQuota($acc, $pdo);
    echo json_encode(['success' => true, 'quota' => $quota]);
    exit;
}

if ($action === 'account_delete') {
    $id = (int)($data['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE google_account_id = ?");
    $stmt->execute([$id]);
    $fileCount = (int)$stmt->fetchColumn();

    if ($fileCount > 0 && empty($data['force'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "This account currently stores $fileCount files. Disconnect or migrate those files first, or pass force=true."
        ]);
        exit;
    }

    $pdo->prepare("DELETE FROM google_accounts WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ========================================================
// 5. INSPECT FILES IN A SPECIFIC GOOGLE ACCOUNT
// ========================================================
if ($action === 'account_files') {
    $accountId = (int)($data['account_id'] ?? $_GET['account_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT f.*, u.username, u.email as user_email, g.account_email as google_email
        FROM files f
        JOIN users u ON f.user_id = u.id
        JOIN google_accounts g ON f.google_account_id = g.id
        WHERE f.google_account_id = ?
        ORDER BY f.created_at DESC
    ");
    $stmt->execute([$accountId]);
    $files = $stmt->fetchAll();
    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// ========================================================
// 6. CROSS-ACCOUNT FILE MOVE (MIGRATE FILE FROM GMAIL A TO GMAIL B)
// ========================================================
if ($action === 'move_file_account') {
    $fileId = (int)($data['file_id'] ?? 0);
    $targetAccountId = (int)($data['target_account_id'] ?? 0);

    if ($fileId <= 0 || $targetAccountId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File ID and target account ID are required.']);
        exit;
    }

    $fileStmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $fileStmt->execute([$fileId]);
    $file = $fileStmt->fetch();
    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found.']);
        exit;
    }

    if ($file['google_account_id'] == $targetAccountId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File is already on this Google account.']);
        exit;
    }

    $srcAcc = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
    $srcAcc->execute([$file['google_account_id']]);
    $sourceAccount = $srcAcc->fetch();

    $tgtAcc = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
    $tgtAcc->execute([$targetAccountId]);
    $targetAccount = $tgtAcc->fetch();

    if (!$sourceAccount || !$targetAccount) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Source or target Google account not found.']);
        exit;
    }

    $res = GoogleDriveManager::moveFileBetweenAccounts($sourceAccount, $targetAccount, $pdo, $fileId);
    if (!$res['success']) {
        http_response_code(500);
        echo json_encode($res);
        exit;
    }

    echo json_encode(['success' => true, 'message' => "File migrated successfully to {$targetAccount['account_email']}."]);
    exit;
}

// ========================================================
// 7. LIST ALL USERS (WITH ADVANCED FILTERS & SEARCH)
// ========================================================
if ($action === 'all_users') {
    $search = trim($_GET['search'] ?? $data['search'] ?? '');
    $status = trim($_GET['status'] ?? $data['status'] ?? 'all');
    $role = trim($_GET['role'] ?? $data['role'] ?? 'all');

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(u.username LIKE ? OR u.email LIKE ? OR u.registration_ip LIKE ? OR u.last_login_ip LIKE ?)";
        $sTerm = "%$search%";
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
        $params[] = $sTerm;
    }

    if ($status === 'active') {
        $where[] = "(u.is_blocked = 0 OR u.is_blocked IS NULL)";
    } elseif ($status === 'blocked') {
        $where[] = "u.is_blocked = 1";
    }

    if ($role === 'admin') {
        $where[] = "u.role = 'admin'";
    } elseif ($role === 'user') {
        $where[] = "u.role = 'user'";
    }

    $whereSql = implode(" AND ", $where);

    $sql = "
        SELECT u.id, u.username, u.email, u.role, u.is_blocked, u.storage_limit_bytes,
               u.registration_ip, u.last_login_ip, u.created_at,
               COUNT(f.id) as files_count,
               COALESCE(SUM(f.size_bytes), 0) as total_bytes
        FROM users u
        LEFT JOIN files f ON f.user_id = u.id
        WHERE $whereSql
        GROUP BY u.id
        ORDER BY u.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

// ========================================================
// 8. USER FILES DRILLDOWN (FILES UPLOADED BY PARTICULAR USER)
// ========================================================
if ($action === 'user_files') {
    $userId = (int)($data['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'User ID is required.']);
        exit;
    }

    // Get user details
    $uStmt = $pdo->prepare("SELECT id, username, email, is_blocked, storage_limit_bytes, registration_ip, last_login_ip, created_at FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    $filesStmt = $pdo->prepare("
        SELECT f.*, g.account_email as google_email, fd.name as folder_name
        FROM files f
        JOIN google_accounts g ON f.google_account_id = g.id
        LEFT JOIN folders fd ON f.folder_id = fd.id
        WHERE f.user_id = ?
        ORDER BY f.created_at DESC
    ");
    $filesStmt->execute([$userId]);
    $files = $filesStmt->fetchAll();

    echo json_encode([
        'success' => true,
        'user' => $user,
        'files' => $files,
    ]);
    exit;
}

// ========================================================
// 9. USER ACTIONS (BLOCK/UNBLOCK, STORAGE LIMIT, PASSWORD, DELETE, BLOCK IP)
// ========================================================
if ($action === 'user_action') {
    $subaction = $data['subaction'] ?? '';
    $userId = (int)($data['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid user ID.']);
        exit;
    }

    // Prevent modifying main admin ID 1 if harmful
    if ($userId === (int)$_SESSION['user_id'] && in_array($subaction, ['toggle_block', 'delete'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'You cannot block or delete your own active admin account.']);
        exit;
    }

    // A. Toggle Block
    if ($subaction === 'toggle_block') {
        $isBlocked = !empty($data['is_blocked']) ? 1 : 0;
        $pdo->prepare("UPDATE users SET is_blocked = ? WHERE id = ?")->execute([$isBlocked, $userId]);
        echo json_encode(['success' => true, 'is_blocked' => $isBlocked]);
        exit;
    }

    // B. Set Storage Limit (in bytes; null or 0 for unlimited)
    if ($subaction === 'set_storage_limit') {
        $limitBytes = isset($data['storage_limit_bytes']) && $data['storage_limit_bytes'] !== null && $data['storage_limit_bytes'] !== '' 
            ? (int)$data['storage_limit_bytes'] 
            : null;
        if ($limitBytes !== null && $limitBytes <= 0) {
            $limitBytes = null; // 0 means unlimited
        }

        $pdo->prepare("UPDATE users SET storage_limit_bytes = ? WHERE id = ?")->execute([$limitBytes, $userId]);
        echo json_encode(['success' => true, 'storage_limit_bytes' => $limitBytes]);
        exit;
    }

    // C. Set / Reset User Password
    if ($subaction === 'set_password') {
        $newPass = trim($data['password'] ?? '');
        if (strlen($newPass) < 6) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
            exit;
        }
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $userId]);
        echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
        exit;
    }

    // D. Block IP of User
    if ($subaction === 'block_ip') {
        $uStmt = $pdo->prepare("SELECT registration_ip, last_login_ip, username FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $u = $uStmt->fetch();
        $ipsBlocked = 0;
        foreach ([$u['registration_ip'], $u['last_login_ip']] as $ip) {
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $reason = "Blocked from user {$u['username']} (ID: $userId)";
                $pdo->prepare("INSERT OR IGNORE INTO blocked_ips (ip_address, reason) VALUES (?, ?)")->execute([$ip, $reason]);
                $ipsBlocked++;
            }
        }
        echo json_encode(['success' => true, 'blocked_count' => $ipsBlocked]);
        exit;
    }

    // E. Delete User
    if ($subaction === 'delete') {
        // Delete all user files from Google Drive and DB
        $files = $pdo->prepare("SELECT id FROM files WHERE user_id = ?");
        $files->execute([$userId]);
        foreach ($files->fetchAll(PDO::FETCH_COLUMN) as $fId) {
            adminDeleteSingleFile($pdo, (int)$fId);
        }

        // Delete user folders
        $pdo->prepare("DELETE FROM folders WHERE user_id = ?")->execute([$userId]);

        // Delete user
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        echo json_encode(['success' => true, 'message' => 'User and associated files deleted.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user subaction.']);
    exit;
}

// ========================================================
// 10. LIST ALL FILES (WITH SEARCH & MULTI-CRITERIA FILTERS)
// ========================================================
if ($action === 'all_files') {
    $search = trim($_GET['search'] ?? $data['search'] ?? '');
    $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : (!empty($data['user_id']) ? (int)$data['user_id'] : 0);
    $accountId = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : (!empty($data['account_id']) ? (int)$data['account_id'] : 0);
    $fileType = trim($_GET['file_type'] ?? $data['file_type'] ?? 'all');
    $sizeRange = trim($_GET['size_range'] ?? $data['size_range'] ?? 'all');
    $dateRange = trim($_GET['date_range'] ?? $data['date_range'] ?? 'all');
    $sort = trim($_GET['sort'] ?? $data['sort'] ?? 'newest');

    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "f.name LIKE ?";
        $params[] = "%$search%";
    }

    if ($userId > 0) {
        $where[] = "f.user_id = ?";
        $params[] = $userId;
    }

    if ($accountId > 0) {
        $where[] = "f.google_account_id = ?";
        $params[] = $accountId;
    }

    // File Type Filter
    if ($fileType === 'image') {
        $where[] = "f.mime_type LIKE 'image/%'";
    } elseif ($fileType === 'video') {
        $where[] = "f.mime_type LIKE 'video/%'";
    } elseif ($fileType === 'audio') {
        $where[] = "f.mime_type LIKE 'audio/%'";
    } elseif ($fileType === 'document') {
        $where[] = "(f.mime_type LIKE '%pdf%' OR f.mime_type LIKE '%document%' OR f.mime_type LIKE '%text%' OR f.mime_type LIKE '%sheet%' OR f.mime_type LIKE '%msword%' OR f.mime_type LIKE '%presentation%')";
    } elseif ($fileType === 'archive') {
        $where[] = "(f.mime_type LIKE '%zip%' OR f.mime_type LIKE '%rar%' OR f.mime_type LIKE '%tar%' OR f.mime_type LIKE '%7z%')";
    }

    // Size Range Filter
    if ($sizeRange === 'small') { // < 10 MB
        $where[] = "f.size_bytes < 10485760";
    } elseif ($sizeRange === 'medium') { // 10 MB - 100 MB
        $where[] = "f.size_bytes >= 10485760 AND f.size_bytes < 104857600";
    } elseif ($sizeRange === 'large') { // 100 MB - 1 GB
        $where[] = "f.size_bytes >= 104857600 AND f.size_bytes < 1073741824";
    } elseif ($sizeRange === 'huge') { // > 1 GB
        $where[] = "f.size_bytes >= 1073741824";
    }

    // Date Range Filter
    if ($dateRange === 'today') {
        $where[] = "DATE(f.created_at) = DATE('now')";
    } elseif ($dateRange === 'week') {
        $where[] = "f.created_at >= DATE('now', '-7 days')";
    } elseif ($dateRange === 'month') {
        $where[] = "f.created_at >= DATE('now', '-30 days')";
    }

    // Order By
    $orderSql = "f.created_at DESC";
    if ($sort === 'oldest') $orderSql = "f.created_at ASC";
    if ($sort === 'largest') $orderSql = "f.size_bytes DESC";
    if ($sort === 'smallest') $orderSql = "f.size_bytes ASC";
    if ($sort === 'name') $orderSql = "f.name ASC";

    $whereSql = implode(" AND ", $where);

    $sql = "
        SELECT f.*, u.username, u.email as user_email, g.account_email as google_email, fd.name as folder_name
        FROM files f
        JOIN users u ON f.user_id = u.id
        JOIN google_accounts g ON f.google_account_id = g.id
        LEFT JOIN folders fd ON f.folder_id = fd.id
        WHERE $whereSql
        ORDER BY $orderSql
        LIMIT 300
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $files = $stmt->fetchAll();

    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// ========================================================
// 11. FILE ACTIONS (PREVIEW, EDIT/RENAME, DELETE, STOP ACCESS)
// ========================================================
if ($action === 'file_action') {
    $subaction = $data['subaction'] ?? '';
    $fileId = (int)($data['file_id'] ?? 0);

    if ($fileId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file ID.']);
        exit;
    }

    $fileStmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $fileStmt->execute([$fileId]);
    $file = $fileStmt->fetch();
    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found.']);
        exit;
    }

    // A. Rename File
    if ($subaction === 'rename') {
        $newName = trim($data['name'] ?? '');
        if (empty($newName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'New file name cannot be empty.']);
            exit;
        }

        // Rename in Google Drive
        $accStmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
        $accStmt->execute([$file['google_account_id']]);
        $acc = $accStmt->fetch();
        if ($acc && !empty($file['google_file_id'])) {
            GoogleDriveManager::renameGoogleFile($acc, $pdo, $file['google_file_id'], $newName);
        }

        $pdo->prepare("UPDATE files SET name = ? WHERE id = ?")->execute([$newName, $fileId]);
        echo json_encode(['success' => true, 'name' => $newName]);
        exit;
    }

    // B. Stop Access (Revoke Public Share & Invalidate Links)
    if ($subaction === 'stop_access') {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE files SET is_public = 0, share_token = ? WHERE id = ?")->execute([$newToken, $fileId]);
        echo json_encode(['success' => true, 'is_public' => 0, 'message' => 'Access stopped. Previous share links are now permanently invalid.']);
        exit;
    }

    // C. Allow Access (Make Public)
    if ($subaction === 'allow_access') {
        $pdo->prepare("UPDATE files SET is_public = 1 WHERE id = ?")->execute([$fileId]);
        echo json_encode(['success' => true, 'is_public' => 1]);
        exit;
    }

    // D. Delete File
    if ($subaction === 'delete') {
        adminDeleteSingleFile($pdo, $fileId);
        echo json_encode(['success' => true, 'message' => 'File deleted permanently.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file subaction.']);
    exit;
}

// ========================================================
// 12. FOLDER ACTIONS (RENAME, DELETE, STOP ACCESS)
// ========================================================
if ($action === 'folder_action') {
    $subaction = $data['subaction'] ?? '';
    $folderId = (int)($data['folder_id'] ?? 0);

    if ($folderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid folder ID.']);
        exit;
    }

    $fStmt = $pdo->prepare("SELECT * FROM folders WHERE id = ?");
    $fStmt->execute([$folderId]);
    $folder = $fStmt->fetch();
    if (!$folder) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Folder not found.']);
        exit;
    }

    // A. Rename Folder
    if ($subaction === 'rename') {
        $newName = trim($data['name'] ?? '');
        if (empty($newName)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Folder name cannot be empty.']);
            exit;
        }
        $pdo->prepare("UPDATE folders SET name = ? WHERE id = ?")->execute([$newName, $folderId]);
        echo json_encode(['success' => true, 'name' => $newName]);
        exit;
    }

    // B. Stop Access
    if ($subaction === 'stop_access') {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE folders SET is_public = 0, share_token = ? WHERE id = ?")->execute([$newToken, $folderId]);
        echo json_encode(['success' => true, 'is_public' => 0, 'message' => 'Folder public access revoked.']);
        exit;
    }

    // C. Allow Access
    if ($subaction === 'allow_access') {
        $pdo->prepare("UPDATE folders SET is_public = 1 WHERE id = ?")->execute([$folderId]);
        echo json_encode(['success' => true, 'is_public' => 1]);
        exit;
    }

    // D. Delete Folder & Contents
    if ($subaction === 'delete') {
        adminDeleteFolderRecursively($pdo, $folderId);
        echo json_encode(['success' => true, 'message' => 'Folder and all contained files deleted permanently.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid folder subaction.']);
    exit;
}

// ========================================================
// 13. AUDIT ALL USER-CREATED SHARABLE LINKS
// ========================================================
if ($action === 'shared_links_list') {
    // 1. Shared Files
    $files = $pdo->query("
        SELECT f.id, 'file' as item_type, f.name, f.share_token, f.size_bytes, f.is_public, f.created_at,
               u.id as user_id, u.username, u.email as user_email, NULL as expires_at, NULL as download_count
        FROM files f
        JOIN users u ON f.user_id = u.id
        WHERE f.is_public = 1
        ORDER BY f.created_at DESC
    ")->fetchAll();

    // 2. Shared Folders
    $folders = $pdo->query("
        SELECT fd.id, 'folder' as item_type, fd.name, fd.share_token, 0 as size_bytes, fd.is_public, fd.created_at,
               u.id as user_id, u.username, u.email as user_email, NULL as expires_at, NULL as download_count
        FROM folders fd
        JOIN users u ON fd.user_id = u.id
        WHERE fd.is_public = 1
        ORDER BY fd.created_at DESC
    ")->fetchAll();

    // 3. Temp Uploads
    $temps = $pdo->query("
        SELECT t.id, 'temp' as item_type, t.title as name, t.share_token, t.total_size as size_bytes, 
               CASE WHEN t.expires_at > CURRENT_TIMESTAMP THEN 1 ELSE 0 END as is_public,
               t.created_at, COALESCE(u.id, 0) as user_id, COALESCE(u.username, 'Guest / Anonymous') as username, 
               COALESCE(u.email, 'N/A') as user_email, t.expires_at, t.download_count
        FROM temp_uploads t
        LEFT JOIN users u ON t.user_id = u.id
        ORDER BY t.created_at DESC
    ")->fetchAll();

    $merged = array_merge($files, $folders, $temps);

    // Sort by created_at DESC
    usort($merged, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    echo json_encode(['success' => true, 'links' => $merged]);
    exit;
}

// ========================================================
// 13B. REVOKE / STOP ACCESS OF ANY SHARED LINK
// ========================================================
if ($action === 'revoke_shared_link') {
    $type = $data['type'] ?? '';
    $id = (int)($data['id'] ?? 0);

    if ($id <= 0 || empty($type)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Link type and ID required.']);
        exit;
    }

    if ($type === 'file') {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE files SET is_public = 0, share_token = ? WHERE id = ?")->execute([$newToken, $id]);
    } elseif ($type === 'folder') {
        $newToken = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE folders SET is_public = 0, share_token = ? WHERE id = ?")->execute([$newToken, $id]);
    } elseif ($type === 'temp') {
        // Delete all files in this temp upload from Google Drive
        $files = $pdo->prepare("SELECT * FROM temp_files WHERE temp_upload_id = ?");
        $files->execute([$id]);
        $accCache = [];
        foreach ($files->fetchAll() as $tf) {
            $accId = (int)$tf['google_account_id'];
            if (!isset($accCache[$accId])) {
                $accStmt = $pdo->prepare("SELECT * FROM google_accounts WHERE id = ?");
                $accStmt->execute([$accId]);
                $accCache[$accId] = $accStmt->fetch();
            }
            if (!empty($accCache[$accId]) && !empty($tf['google_file_id'])) {
                GoogleDriveManager::deleteGoogleFile($accCache[$accId], $pdo, $tf['google_file_id']);
                $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = MAX(0, used_storage_bytes - ?) WHERE id = ?")
                    ->execute([(int)$tf['size_bytes'], $accId]);
            }
        }
        $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$id]);
    }

    echo json_encode(['success' => true, 'message' => 'Link access revoked successfully.']);
    exit;
}

// ========================================================
// 14. IP SECURITY & BLOCKING
// ========================================================
if ($action === 'ip_list') {
    $stmt = $pdo->query("SELECT * FROM blocked_ips ORDER BY blocked_at DESC");
    $ips = $stmt->fetchAll();
    echo json_encode(['success' => true, 'blocked_ips' => $ips]);
    exit;
}

if ($action === 'ip_action') {
    $subaction = $data['subaction'] ?? '';
    
    if ($subaction === 'block') {
        $ip = trim($data['ip_address'] ?? '');
        $reason = trim($data['reason'] ?? 'Blocked by Admin');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid IP address format.']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO blocked_ips (ip_address, reason, blocked_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$ip, $reason]);
        echo json_encode(['success' => true, 'message' => "IP $ip has been blocked."]);
        exit;
    }

    if ($subaction === 'unblock') {
        $ip = trim($data['ip_address'] ?? '');
        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM blocked_ips WHERE id = ?")->execute([$id]);
        } elseif (!empty($ip)) {
            $pdo->prepare("DELETE FROM blocked_ips WHERE ip_address = ?")->execute([$ip]);
        }
        echo json_encode(['success' => true, 'message' => 'IP unblocked.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid IP subaction.']);
    exit;
}

// ========================================================
// 15. LIVE VISITORS ON SITE (ADMIN ONLY MONITOR)
// ========================================================
if ($action === 'live_visitors') {
    // Purge older than 15 mins
    $pdo->exec("DELETE FROM live_visitors WHERE last_active_at < datetime('now', '-15 minutes')");

    $stmt = $pdo->query("
        SELECT lv.*, u.email as user_email, u.role as user_role
        FROM live_visitors lv
        LEFT JOIN users u ON lv.user_id = u.id
        WHERE lv.last_active_at >= datetime('now', '-2 minutes')
        ORDER BY lv.last_active_at DESC
    ");
    $visitors = $stmt->fetchAll();

    $authCount = 0;
    $guestCount = 0;
    $devices = ['Desktop' => 0, 'Mobile' => 0, 'Tablet' => 0];

    foreach ($visitors as $v) {
        if (!empty($v['user_id'])) {
            $authCount++;
        } else {
            $guestCount++;
        }
        $d = $v['device_type'] ?: 'Desktop';
        if (isset($devices[$d])) {
            $devices[$d]++;
        } else {
            $devices['Desktop']++;
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($visitors),
        'auth_count' => $authCount,
        'guest_count' => $guestCount,
        'devices' => $devices,
        'visitors' => $visitors,
    ]);
    exit;
}

// ========================================================
// 16. CONTENT ANALYZER & MODERATION STATS
// ========================================================
if ($action === 'content_stats') {
    $totalFiles = (int)$pdo->query("SELECT COUNT(*) FROM files")->fetchColumn();
    $aiCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE content_tag = 'ai_generated'")->fetchColumn();
    $adultCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE content_tag = 'adult_18'")->fetchColumn();
    $suspiciousCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE content_tag = 'suspicious'")->fetchColumn();
    $safeCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE content_tag = 'safe'")->fetchColumn();
    $unclassifiedCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE content_tag = 'unclassified' OR content_tag IS NULL")->fetchColumn();
    $quarantinedCount = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE is_visibility_blocked = 1")->fetchColumn();

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_files' => $totalFiles,
            'ai_generated' => $aiCount,
            'adult_18' => $adultCount,
            'suspicious' => $suspiciousCount,
            'safe' => $safeCount,
            'unclassified' => $unclassifiedCount,
            'quarantined' => $quarantinedCount,
        ]
    ]);
    exit;
}

// ========================================================
// 17. CONTENT ANALYZER FILES LIST (WITH FILTERING & USER TRACE)
// ========================================================
if ($action === 'content_files') {
    $category = trim($_GET['category'] ?? $data['category'] ?? 'all');
    $userId = (int)($_GET['user_id'] ?? $data['user_id'] ?? 0);
    $search = trim($_GET['search'] ?? $data['search'] ?? '');

    $where = ["1=1"];
    $params = [];

    if ($category === 'ai_generated') {
        $where[] = "f.content_tag = 'ai_generated'";
    } elseif ($category === 'adult_18') {
        $where[] = "f.content_tag = 'adult_18'";
    } elseif ($category === 'suspicious') {
        $where[] = "f.content_tag = 'suspicious'";
    } elseif ($category === 'safe') {
        $where[] = "f.content_tag = 'safe'";
    } elseif ($category === 'unclassified') {
        $where[] = "(f.content_tag = 'unclassified' OR f.content_tag IS NULL)";
    } elseif ($category === 'blocked') {
        $where[] = "f.is_visibility_blocked = 1";
    }

    if ($userId > 0) {
        $where[] = "f.user_id = ?";
        $params[] = $userId;
    }

    if (!empty($search)) {
        $where[] = "(f.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSql = implode(" AND ", $where);
    $stmt = $pdo->prepare("
        SELECT f.id, f.name, f.size_bytes, f.mime_type, f.created_at,
               f.content_tag, f.content_confidence, f.content_details,
               f.is_visibility_blocked, f.blocked_reason, f.blocked_at,
               f.user_id, u.username, u.email as user_email,
               g.account_email as google_email
        FROM files f
        JOIN users u ON f.user_id = u.id
        JOIN google_accounts g ON f.google_account_id = g.id
        WHERE $whereSql
        ORDER BY f.id DESC
        LIMIT 200
    ");
    $stmt->execute($params);
    $files = $stmt->fetchAll();

    echo json_encode(['success' => true, 'files' => $files]);
    exit;
}

// ========================================================
// 18. CONTENT ACTIONS (BLOCK VISIBILITY, REANALYZE, BATCH SCAN)
// ========================================================
if ($action === 'content_action') {
    $subaction = $data['subaction'] ?? '';
    $fileId = (int)($data['file_id'] ?? 0);

    // A. Toggle Block Visibility (Quarantine)
    if ($subaction === 'toggle_block_visibility') {
        if ($fileId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File ID required.']);
            exit;
        }

        $block = !empty($data['block']) ? 1 : 0;
        $reason = trim($data['reason'] ?? ($block ? 'Quarantined by Administrator for content policy violation' : ''));

        if ($block) {
            $pdo->prepare("
                UPDATE files 
                SET is_visibility_blocked = 1, is_public = 0, blocked_reason = ?, blocked_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$reason, $fileId]);
        } else {
            $pdo->prepare("
                UPDATE files 
                SET is_visibility_blocked = 0, blocked_reason = NULL, blocked_at = NULL
                WHERE id = ?
            ")->execute([$fileId]);
        }

        echo json_encode(['success' => true, 'is_visibility_blocked' => $block]);
        exit;
    }

    // B. Re-analyze a single file
    if ($subaction === 'reanalyze') {
        if ($fileId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'File ID required.']);
            exit;
        }
        $res = ContentAnalyzer::analyzeFile($pdo, $fileId);
        echo json_encode($res);
        exit;
    }

    // C. Batch Scan files
    if ($subaction === 'batch_scan') {
        $forceAll = !empty($data['force_all']);
        $res = ContentAnalyzer::batchAnalyze($pdo, 100, $forceAll);
        echo json_encode($res);
        exit;
    }

    // D. Manual Tag Override
    if ($subaction === 'set_content_tag') {
        $tag = trim($data['tag'] ?? 'safe');
        $validTags = ['ai_generated', 'adult_18', 'suspicious', 'safe', 'unclassified'];
        if (!in_array($tag, $validTags)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid content tag.']);
            exit;
        }
        $pdo->prepare("UPDATE files SET content_tag = ?, content_confidence = 100, content_details = 'Manually categorized by administrator.' WHERE id = ?")
            ->execute([$tag, $fileId]);
        echo json_encode(['success' => true, 'content_tag' => $tag]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid content subaction.']);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Invalid admin action.']);
