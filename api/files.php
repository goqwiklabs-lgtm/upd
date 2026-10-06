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
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// 1. LIST FILES & FOLDERS
if ($action === 'list') {
    $folderId = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int)$_GET['folder_id'] : null;

    // Get folders
    if ($folderId === null) {
        $stmtF = $pdo->prepare("SELECT * FROM folders WHERE user_id = ? AND parent_id IS NULL ORDER BY name ASC");
        $stmtF->execute([$userId]);
    } else {
        $stmtF = $pdo->prepare("SELECT * FROM folders WHERE user_id = ? AND parent_id = ? ORDER BY name ASC");
        $stmtF->execute([$userId, $folderId]);
    }
    $folders = $stmtF->fetchAll();

    // Get files
    if ($folderId === null) {
        $stmtFiles = $pdo->prepare("SELECT * FROM files WHERE user_id = ? AND folder_id IS NULL ORDER BY created_at DESC, id DESC");
        $stmtFiles->execute([$userId]);
    } else {
        $stmtFiles = $pdo->prepare("SELECT * FROM files WHERE user_id = ? AND folder_id = ? ORDER BY created_at DESC, id DESC");
        $stmtFiles->execute([$userId, $folderId]);
    }
    $files = $stmtFiles->fetchAll();

    // Build breadcrumb trail
    $breadcrumbs = [];
    $curr = $folderId;
    while ($curr !== null) {
        $stmtB = $pdo->prepare("SELECT id, name, parent_id FROM folders WHERE id = ? AND user_id = ?");
        $stmtB->execute([$curr, $userId]);
        $fRow = $stmtB->fetch();
        if ($fRow) {
            array_unshift($breadcrumbs, ['id' => $fRow['id'], 'name' => $fRow['name']]);
            $curr = $fRow['parent_id'];
        } else {
            break;
        }
    }

    // Total storage used by user
    $stmtTotal = $pdo->prepare("SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE user_id = ?");
    $stmtTotal->execute([$userId]);
    $totalUserBytes = (int)$stmtTotal->fetchColumn();

    echo json_encode([
        'success' => true,
        'current_folder_id' => $folderId,
        'breadcrumbs' => $breadcrumbs,
        'folders' => $folders,
        'files' => $files,
        'total_user_bytes' => $totalUserBytes,
    ]);
    exit;
}

// 2. CREATE FOLDER
if ($action === 'create_folder') {
    $name = trim($data['name'] ?? '');
    $parentId = !empty($data['parent_id']) ? (int)$data['parent_id'] : null;

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Folder name is required.']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO folders (user_id, parent_id, name) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $parentId, $name]);
    $folderId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'folder' => [
            'id' => (int)$folderId,
            'name' => $name,
            'parent_id' => $parentId,
        ]
    ]);
    exit;
}

// 3. RENAME
if ($action === 'rename') {
    $type = $data['type'] ?? 'file';
    $id = (int)($data['id'] ?? 0);
    $newName = trim($data['new_name'] ?? '');

    if (!$id || empty($newName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters.']);
        exit;
    }

    if ($type === 'folder') {
        $stmt = $pdo->prepare("UPDATE folders SET name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$newName, $id, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // File rename
    $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ? AND f.user_id = ?");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found.']);
        exit;
    }

    GoogleDriveManager::renameGoogleFile($row, $pdo, $row['google_file_id'], $newName);

    $upd = $pdo->prepare("UPDATE files SET name = ? WHERE id = ?");
    $upd->execute([$newName, $id]);

    echo json_encode(['success' => true, 'name' => $newName]);
    exit;
}

// 4. DELETE
if ($action === 'delete') {
    $type = $data['type'] ?? 'file';
    $id = (int)($data['id'] ?? 0);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid ID.']);
        exit;
    }

    if ($type === 'folder') {
        // Delete all files inside recursively
        $filesInside = $pdo->prepare("
            SELECT f.id as file_id, f.size_bytes, f.google_file_id, f.google_account_id,
                   g.id as account_id, g.account_email, g.client_id, g.client_secret, g.refresh_token, g.access_token, g.token_expires_at
            FROM files f 
            JOIN google_accounts g ON f.google_account_id = g.id 
            WHERE f.folder_id = ? AND f.user_id = ?
        ");
        $filesInside->execute([$id, $userId]);
        $files = $filesInside->fetchAll();

        foreach ($files as $f) {
            $accountData = [
                'id' => $f['account_id'],
                'account_email' => $f['account_email'],
                'client_id' => $f['client_id'],
                'client_secret' => $f['client_secret'],
                'refresh_token' => $f['refresh_token'],
                'access_token' => $f['access_token'],
                'token_expires_at' => $f['token_expires_at'],
            ];
            try {
                GoogleDriveManager::deleteGoogleFile($accountData, $pdo, $f['google_file_id']);
            } catch (Exception $e) {
                // Ignore remote deletion errors during batch delete
            }
            $pdo->prepare("
                UPDATE google_accounts 
                SET used_storage_bytes = CASE 
                    WHEN used_storage_bytes > ? THEN used_storage_bytes - ? 
                    ELSE 0 
                END 
                WHERE id = ?
            ")->execute([$f['size_bytes'], $f['size_bytes'], $f['google_account_id']]);
        }
        $pdo->prepare("DELETE FROM files WHERE folder_id = ? AND user_id = ?")->execute([$id, $userId]);
        $pdo->prepare("DELETE FROM folders WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        echo json_encode(['success' => true]);
        exit;
    }

    // Delete single file
    $stmt = $pdo->prepare("
        SELECT f.id as file_id, f.user_id, f.size_bytes, f.google_file_id, f.google_account_id,
               g.id as account_id, g.account_email, g.client_id, g.client_secret, g.refresh_token, g.access_token, g.token_expires_at
        FROM files f 
        JOIN google_accounts g ON f.google_account_id = g.id 
        WHERE f.id = ? AND f.user_id = ?
    ");
    $stmt->execute([$id, $userId]);
    $file = $stmt->fetch();

    if (!$file) {
        // If not found in JOIN, clean up if record exists in files
        $pdo->prepare("DELETE FROM files WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        echo json_encode(['success' => true, 'cleaned' => true]);
        exit;
    }

    $accountData = [
        'id' => $file['account_id'],
        'account_email' => $file['account_email'],
        'client_id' => $file['client_id'],
        'client_secret' => $file['client_secret'],
        'refresh_token' => $file['refresh_token'],
        'access_token' => $file['access_token'],
        'token_expires_at' => $file['token_expires_at'],
    ];

    // Delete in Google Drive (silently handled if already 404 in Drive)
    try {
        GoogleDriveManager::deleteGoogleFile($accountData, $pdo, $file['google_file_id']);
    } catch (Exception $e) {
        error_log("Google delete warning: " . $e->getMessage());
    }

    // Reduce storage counter using universal ANSI SQL
    $pdo->prepare("
        UPDATE google_accounts 
        SET used_storage_bytes = CASE 
            WHEN used_storage_bytes > ? THEN used_storage_bytes - ? 
            ELSE 0 
        END 
        WHERE id = ?
    ")->execute([$file['size_bytes'], $file['size_bytes'], $file['google_account_id']]);

    // Delete in DB
    $pdo->prepare("DELETE FROM files WHERE id = ? AND user_id = ?")->execute([$id, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

// 5. MOVE FILE
if ($action === 'move') {
    $fileId = (int)($data['file_id'] ?? 0);
    $targetFolderId = !empty($data['target_folder_id']) ? (int)$data['target_folder_id'] : null;

    $stmt = $pdo->prepare("UPDATE files SET folder_id = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$targetFolderId, $fileId, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

// 6. COPY FILE
if ($action === 'copy') {
    $fileId = (int)($data['file_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ? AND f.user_id = ?");
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found.']);
        exit;
    }

    $copyName = 'Copy of ' . $file['name'];
    $newGoogleFileId = GoogleDriveManager::copyGoogleFile($file, $pdo, $file['google_file_id'], $copyName);

    if (!$newGoogleFileId) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Failed to duplicate file in Google Drive.']);
        exit;
    }

    $newShareToken = bin2hex(random_bytes(16));
    $ins = $pdo->prepare("
        INSERT INTO files (user_id, folder_id, google_account_id, google_file_id, name, size_bytes, mime_type, share_token, is_public)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
    ");
    $ins->execute([
        $userId,
        $file['folder_id'],
        $file['google_account_id'],
        $newGoogleFileId,
        $copyName,
        $file['size_bytes'],
        $file['mime_type'],
        $newShareToken
    ]);
    $newId = $pdo->lastInsertId();

    // Increment storage counter
    $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = used_storage_bytes + ? WHERE id = ?")
        ->execute([$file['size_bytes'], $file['google_account_id']]);

    echo json_encode([
        'success' => true,
        'file' => [
            'id' => (int)$newId,
            'name' => $copyName,
            'size_bytes' => $file['size_bytes'],
            'mime_type' => $file['mime_type'],
            'share_token' => $newShareToken,
        ]
    ]);
    exit;
}

// 7. TOGGLE SHARE
if ($action === 'toggle_share') {
    $fileId = (int)($data['file_id'] ?? 0);
    $isPublic = !empty($data['is_public']) ? 1 : 0;

    $stmt = $pdo->prepare("SELECT f.*, g.* FROM files f JOIN google_accounts g ON f.google_account_id = g.id WHERE f.id = ? AND f.user_id = ?");
    $stmt->execute([$fileId, $userId]);
    $file = $stmt->fetch();

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'File not found.']);
        exit;
    }

    if ($isPublic) {
        GoogleDriveManager::setGoogleFilePublic($file, $pdo, $file['google_file_id'], true);
    }

    $upd = $pdo->prepare("UPDATE files SET is_public = ? WHERE id = ?");
    $upd->execute([$isPublic, $fileId]);

    echo json_encode([
        'success' => true,
        'is_public' => $isPublic,
        'share_token' => $file['share_token'],
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Action not found.']);
