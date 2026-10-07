<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$pdo = getDBConnection();

/**
 * Purges all expired temporary uploads permanently from Google Drive and Database
 */
function purgeExpiredTempUploads(PDO $pdo): int {
    $now = date('Y-m-d H:i:s');
    // Fetch all expired temp uploads
    $stmt = $pdo->prepare("SELECT id, title FROM temp_uploads WHERE expires_at <= ?");
    $stmt->execute([$now]);
    $expiredUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $purgedCount = 0;

    foreach ($expiredUploads as $upload) {
        $uploadId = (int)$upload['id'];

        // Get all files belonging to this temp upload
        $fStmt = $pdo->prepare("
            SELECT tf.*, ga.client_id, ga.client_secret, ga.refresh_token, ga.access_token, ga.token_expires_at
            FROM temp_files tf
            LEFT JOIN google_accounts ga ON tf.google_account_id = ga.id
            WHERE tf.temp_upload_id = ?
        ");
        $fStmt->execute([$uploadId]);
        $files = $fStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($files as $f) {
            if (!empty($f['google_file_id']) && !empty($f['refresh_token'])) {
                try {
                    $acc = [
                        'id' => $f['google_account_id'],
                        'client_id' => $f['client_id'],
                        'client_secret' => $f['client_secret'],
                        'refresh_token' => $f['refresh_token'],
                        'access_token' => $f['access_token'],
                        'token_expires_at' => $f['token_expires_at'],
                    ];
                    // Permanently delete from Google Drive!
                    GoogleDriveManager::deleteGoogleFile($acc, $pdo, $f['google_file_id']);
                } catch (Exception $e) {
                    error_log("Failed to delete expired Google Drive temp file {$f['google_file_id']}: " . $e->getMessage());
                }

                // Decrement used_storage_bytes
                if (!empty($f['size_bytes'])) {
                    $pdo->prepare("
                        UPDATE google_accounts 
                        SET used_storage_bytes = CASE 
                            WHEN used_storage_bytes > ? THEN used_storage_bytes - ? 
                            ELSE 0 
                        END 
                        WHERE id = ?
                    ")->execute([$f['size_bytes'], $f['size_bytes'], $f['google_account_id']]);
                }
            }
        }

        // Delete database records
        $pdo->prepare("DELETE FROM temp_files WHERE temp_upload_id = ?")->execute([$uploadId]);
        $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$uploadId]);
        $purgedCount++;
    }

    return $purgedCount;
}

// Auto-run cleanup on every call
purgeExpiredTempUploads($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true) ?: $_POST;

// 1. INITIATE UPLOAD SESSION FOR A TEMP FILE
if ($action === 'init_upload') {
    $filename = trim($data['filename'] ?? '');
    $mimeType = trim($data['mime_type'] ?? 'application/octet-stream');
    $size = (int)($data['size'] ?? 0);

    if (empty($filename) || $size <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid file parameters.']);
        exit;
    }

    $account = GoogleDriveManager::getAvailableStorageAccount($pdo, $size);
    if (!$account) {
        http_response_code(507);
        echo json_encode(['success' => false, 'error' => 'Storage limit reached. No Google Drive account available.']);
        exit;
    }

    $clientOrigin = $_SERVER['HTTP_ORIGIN'] ?? ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $sessionUrl = GoogleDriveManager::createResumableUploadSession($account, $pdo, $filename, $mimeType, $size, $clientOrigin);

    if (!$sessionUrl) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Failed to initiate Google Drive upload session.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'upload_url' => $sessionUrl,
        'google_account_id' => (int)$account['id'],
    ]);
    exit;
}

// 2. FINALIZE TEMP UPLOAD CREATION
if ($action === 'create') {
    $title = trim($data['title'] ?? 'Temporary Transfer');
    $expiryMinutes = (int)($data['expiry_minutes'] ?? 60);

    // Enforce limits: min 10 minutes, max 7 days (10080 minutes)
    if ($expiryMinutes < 10) $expiryMinutes = 10;
    if ($expiryMinutes > 10080) $expiryMinutes = 10080;

    $isFolder = !empty($data['is_folder']) ? 1 : 0;
    $folderName = trim($data['folder_name'] ?? '');
    $files = $data['files'] ?? [];

    if (empty($files) || !is_array($files)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No files provided for temporary upload.']);
        exit;
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $shareToken = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

    $totalSize = 0;
    foreach ($files as $f) {
        $totalSize += (int)($f['size_bytes'] ?? 0);
    }
    $fileCount = count($files);

    // If title is default and only 1 file, name it after the file
    if ($fileCount === 1 && ($title === 'Temporary Transfer' || empty($title))) {
        $title = $files[0]['name'] ?? 'File Transfer';
    } elseif ($isFolder && !empty($folderName)) {
        $title = $folderName;
    }

    $stmt = $pdo->prepare("
        INSERT INTO temp_uploads (user_id, title, share_token, expiry_minutes, expires_at, is_folder, folder_name, total_size, file_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $title,
        $shareToken,
        $expiryMinutes,
        $expiresAt,
        $isFolder,
        $folderName ?: null,
        $totalSize,
        $fileCount
    ]);
    $tempUploadId = (int)$pdo->lastInsertId();

    // Insert files
    $insFile = $pdo->prepare("
        INSERT INTO temp_files (temp_upload_id, google_account_id, google_file_id, name, relative_path, size_bytes, mime_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($files as $f) {
        $gAccId = (int)($f['google_account_id'] ?? 0);
        $gFileId = trim($f['google_file_id'] ?? '');
        $name = trim($f['name'] ?? 'untitled');
        $relPath = trim($f['relative_path'] ?? '');
        $sizeBytes = (int)($f['size_bytes'] ?? 0);
        $mime = trim($f['mime_type'] ?? 'application/octet-stream');

        if ($gAccId > 0 && !empty($gFileId)) {
            $insFile->execute([
                $tempUploadId,
                $gAccId,
                $gFileId,
                $name,
                $relPath,
                $sizeBytes,
                $mime
            ]);

            // Increment used_storage_bytes
            $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = used_storage_bytes + ? WHERE id = ?")
                ->execute([$sizeBytes, $gAccId]);

            // Set file public in Google Drive so anyone with the temp link can stream/download
            $accRow = $pdo->query("SELECT * FROM google_accounts WHERE id = {$gAccId}")->fetch(PDO::FETCH_ASSOC);
            if ($accRow) {
                try {
                    GoogleDriveManager::setGoogleFilePublic($accRow, $pdo, $gFileId, true);
                } catch (Exception $e) {}
            }
        }
    }

    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $shareUrl = $baseUrl . "/temp.php?token=" . $shareToken;

    echo json_encode([
        'success' => true,
        'temp_upload_id' => $tempUploadId,
        'share_token' => $shareToken,
        'share_url' => $shareUrl,
        'title' => $title,
        'expiry_minutes' => $expiryMinutes,
        'expires_at' => $expiresAt,
        'file_count' => $fileCount,
        'total_size' => $totalSize
    ]);
    exit;
}

// 3. GET / INFO (PUBLIC DETAILS FOR A SHARE TOKEN)
if ($action === 'info' || $action === 'get') {
    $token = trim($_GET['token'] ?? $data['token'] ?? '');
    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing share token.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM temp_uploads WHERE share_token = ?");
    $stmt->execute([$token]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$upload) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'expired' => true,
            'error' => 'This temporary link has expired and all files have been permanently deleted.'
        ]);
        exit;
    }

    // Check if expired
    $nowEpoch = time();
    $expiresEpoch = strtotime($upload['expires_at']);
    if ($expiresEpoch <= $nowEpoch) {
        // Purge immediately
        purgeExpiredTempUploads($pdo);
        http_response_code(410);
        echo json_encode([
            'success' => false,
            'expired' => true,
            'error' => 'This temporary link has expired and all files have been permanently deleted.'
        ]);
        exit;
    }

    $secondsRemaining = max(0, $expiresEpoch - $nowEpoch);

    // Fetch files
    $fStmt = $pdo->prepare("SELECT id, name, relative_path, size_bytes, mime_type, google_file_id FROM temp_files WHERE temp_upload_id = ? ORDER BY id ASC");
    $fStmt->execute([(int)$upload['id']]);
    $files = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format files with stream/download URLs
    $formattedFiles = [];
    foreach ($files as $file) {
        $formattedFiles[] = [
            'id' => (int)$file['id'],
            'name' => $file['name'],
            'relative_path' => $file['relative_path'],
            'size_bytes' => (int)$file['size_bytes'],
            'mime_type' => $file['mime_type'],
            'google_file_id' => $file['google_file_id'],
            'download_url' => "stream.php?temp_file_id=" . $file['id'] . "&token=" . $upload['share_token'] . "&download=1",
            'preview_url' => "stream.php?temp_file_id=" . $file['id'] . "&token=" . $upload['share_token'] . "&preview=1",
        ];
    }

    echo json_encode([
        'success' => true,
        'upload' => [
            'id' => (int)$upload['id'],
            'title' => $upload['title'],
            'share_token' => $upload['share_token'],
            'expiry_minutes' => (int)$upload['expiry_minutes'],
            'expires_at' => $upload['expires_at'],
            'seconds_remaining' => $secondsRemaining,
            'is_folder' => (int)$upload['is_folder'],
            'folder_name' => $upload['folder_name'],
            'total_size' => (int)$upload['total_size'],
            'file_count' => (int)$upload['file_count'],
            'created_at' => $upload['created_at'],
        ],
        'files' => $formattedFiles
    ]);
    exit;
}

// 4. LIST MY TEMP UPLOADS (USER AUTHENTICATED)
if ($action === 'my_uploads') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized.']);
        exit;
    }

    $userId = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT * FROM temp_uploads WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nowEpoch = time();
    $result = [];
    foreach ($uploads as $u) {
        $expiresEpoch = strtotime($u['expires_at']);
        $secondsRemaining = max(0, $expiresEpoch - $nowEpoch);
        $result[] = [
            'id' => (int)$u['id'],
            'title' => $u['title'],
            'share_token' => $u['share_token'],
            'expiry_minutes' => (int)$u['expiry_minutes'],
            'expires_at' => $u['expires_at'],
            'seconds_remaining' => $secondsRemaining,
            'is_folder' => (int)$u['is_folder'],
            'folder_name' => $u['folder_name'],
            'total_size' => (int)$u['total_size'],
            'file_count' => (int)$u['file_count'],
            'created_at' => $u['created_at'],
        ];
    }

    echo json_encode(['success' => true, 'uploads' => $result]);
    exit;
}

// 5. DELETE TEMP UPLOAD IMMEDIATELY (BY OWNER OR ADMIN)
if ($action === 'delete') {
    $tempUploadId = (int)($data['id'] ?? $_GET['id'] ?? 0);
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = $_SESSION['role'] ?? 'user';

    if ($tempUploadId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid temp upload ID.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM temp_uploads WHERE id = ?");
    $stmt->execute([$tempUploadId]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$upload) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Temp upload not found.']);
        exit;
    }

    // Auth check
    if ($role !== 'admin' && (int)$upload['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden.']);
        exit;
    }

    // Fetch and delete files permanently from Google Drive
    $fStmt = $pdo->prepare("
        SELECT tf.*, ga.client_id, ga.client_secret, ga.refresh_token, ga.access_token, ga.token_expires_at
        FROM temp_files tf
        LEFT JOIN google_accounts ga ON tf.google_account_id = ga.id
        WHERE tf.temp_upload_id = ?
    ");
    $fStmt->execute([$tempUploadId]);
    $files = $fStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        if (!empty($f['google_file_id']) && !empty($f['refresh_token'])) {
            try {
                $acc = [
                    'id' => $f['google_account_id'],
                    'client_id' => $f['client_id'],
                    'client_secret' => $f['client_secret'],
                    'refresh_token' => $f['refresh_token'],
                    'access_token' => $f['access_token'],
                    'token_expires_at' => $f['token_expires_at'],
                ];
                GoogleDriveManager::deleteGoogleFile($acc, $pdo, $f['google_file_id']);
            } catch (Exception $e) {}

            $pdo->prepare("
                UPDATE google_accounts 
                SET used_storage_bytes = CASE 
                    WHEN used_storage_bytes > ? THEN used_storage_bytes - ? 
                    ELSE 0 
                END 
                WHERE id = ?
            ")->execute([$f['size_bytes'], $f['size_bytes'], $f['google_account_id']]);
        }
    }

    $pdo->prepare("DELETE FROM temp_files WHERE temp_upload_id = ?")->execute([$tempUploadId]);
    $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$tempUploadId]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action.']);
