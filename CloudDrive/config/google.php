<?php
// ========================================================
// Google Drive API v3 Lightweight Manager (Zero Dependencies)
// ========================================================

require_once __DIR__ . '/db.php';

// Default storage limit per Gmail account: 13 GB (leaves 2GB buffer for email)
define('GMAIL_STORAGE_LIMIT_BYTES', 13 * 1024 * 1024 * 1024); // 13,958,643,712 bytes

class GoogleDriveManager {

    /**
     * Refreshes the OAuth2 Access Token using client_id, client_secret, and refresh_token
     */
    public static function refreshAccessToken(array $account, PDO $pdo): ?string {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $account['client_id'],
                'client_secret' => $account['client_secret'],
                'refresh_token' => $account['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("Failed to refresh Google access token for account {$account['account_email']}: $response");
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            return null;
        }

        $accessToken = $data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3500);
        $expiresAt = time() + $expiresIn - 60; // 60s buffer

        $stmt = $pdo->prepare("UPDATE google_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?");
        $stmt->execute([$accessToken, $expiresAt, $account['id']]);

        return $accessToken;
    }

    /**
     * Retrieves a valid access token (refreshes automatically if expired)
     */
    public static function getValidAccessToken(array $account, PDO $pdo): ?string {
        if (!empty($account['access_token']) && !empty($account['token_expires_at']) && $account['token_expires_at'] > time()) {
            return $account['access_token'];
        }
        return self::refreshAccessToken($account, $pdo);
    }

    /**
     * Multi-Account Pool Logic:
     * Finds an active Google account with enough remaining space under 13 GB.
     */
    public static function getAvailableStorageAccount(PDO $pdo, int $incomingFileSizeBytes = 0): ?array {
        $stmt = $pdo->prepare("
            SELECT * FROM google_accounts 
            WHERE is_active = 1 
              AND (used_storage_bytes + ?) <= storage_limit_bytes 
            ORDER BY used_storage_bytes ASC 
            LIMIT 1
        ");
        $stmt->execute([$incomingFileSizeBytes]);
        $account = $stmt->fetch();
        return $account ?: null;
    }

    /**
     * Gets or creates the dedicated "CloudDrive_files" folder in Google Drive
     */
    public static function getOrCreateCloudDriveFolder(array $account, PDO $pdo): ?string {
        if (!empty($account['drive_folder_id'])) {
            return $account['drive_folder_id'];
        }

        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return null;

        // 1. Search for existing folder
        $q = "name = 'CloudDrive_files' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $url = "https://www.googleapis.com/drive/v3/files?q=" . urlencode($q);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $searchData = json_decode($res, true);

        if (!empty($searchData['files'][0]['id'])) {
            $folderId = $searchData['files'][0]['id'];
            $pdo->prepare("UPDATE google_accounts SET drive_folder_id = ? WHERE id = ?")->execute([$folderId, $account['id']]);
            return $folderId;
        }

        // 2. Create folder if not found
        $ch = curl_init("https://www.googleapis.com/drive/v3/files");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'name' => 'CloudDrive_files',
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $createRes = curl_exec($ch);
        curl_close($ch);
        $createData = json_decode($createRes, true);

        if (!empty($createData['id'])) {
            $folderId = $createData['id'];
            $pdo->prepare("UPDATE google_accounts SET drive_folder_id = ? WHERE id = ?")->execute([$folderId, $account['id']]);
            return $folderId;
        }

        return null;
    }

    /**
     * Initiates a Google Drive Resumable Upload Session.
     * All files are stored directly inside the "CloudDrive_files" folder!
     */
    public static function createResumableUploadSession(array $account, PDO $pdo, string $filename, string $mimeType, int $fileSize, string $clientOrigin = ''): ?string {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) {
            return null;
        }

        $folderId = self::getOrCreateCloudDriveFolder($account, $pdo);

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
        $meta = [
            'name' => $filename,
            'mimeType' => $mimeType ?: 'application/octet-stream',
        ];
        if (!empty($folderId)) {
            $meta['parents'] = [$folderId];
        }
        $metadata = json_encode($meta);

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: ' . ($mimeType ?: 'application/octet-stream'),
            'X-Upload-Content-Length: ' . $fileSize,
        ];

        if (!empty($clientOrigin)) {
            $headers[] = 'Origin: ' . $clientOrigin;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $metadata,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headersText = substr($response, 0, $headerSize);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && preg_match('/Location:\s*([^\r\n]+)/i', $headersText, $matches)) {
            return trim($matches[1]);
        }

        error_log("Failed to create resumable upload session: HTTP $httpCode - $response");
        return null;
    }

    /**
     * Delete file from Google Drive
     */
    public static function deleteGoogleFile(array $account, PDO $pdo, string $googleFileId): bool {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return false;

        $url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 204 || $httpCode === 200 || $httpCode === 404);
    }

    /**
     * Rename file in Google Drive
     */
    public static function renameGoogleFile(array $account, PDO $pdo, string $googleFileId, string $newName): bool {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return false;

        $url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $newName]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8'
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200);
    }

    /**
     * Copy file in Google Drive
     */
    public static function copyGoogleFile(array $account, PDO $pdo, string $googleFileId, string $copyName): ?string {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return null;

        $url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}/copy";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode(['name' => $copyName]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8'
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['id'] ?? null;
        }

        return null;
    }

    /**
     * Make Google Drive file public or private
     */
    public static function setGoogleFilePublic(array $account, PDO $pdo, string $googleFileId, bool $isPublic): bool {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return false;

        if ($isPublic) {
            // Add 'anyone' permission
            $url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}/permissions";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'role' => 'reader',
                    'type' => 'anyone',
                ]),
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json; charset=UTF-8'
                ],
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($httpCode === 200);
        }

        return true;
    }

    /**
     * Sync and fetch actual Google Drive used storage quota directly from Google API
     * Auto-detects total storage, user's previous usage, and reserves a 2 GB safety buffer!
     */
    public static function syncGoogleDriveQuota(array $account, PDO $pdo): ?array {
        $accessToken = self::getValidAccessToken($account, $pdo);
        if (!$accessToken) return null;

        // Ensure "CloudDrive_files" folder exists in this account
        self::getOrCreateCloudDriveFolder($account, $pdo);

        $url = "https://www.googleapis.com/drive/v3/about?fields=storageQuota,user";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            // 1. Total capacity (Google limit: e.g. 15 GB, 2 TB, or 5 TB)
            $totalCapacity = (int)($data['storageQuota']['limit'] ?? (5 * 1024 * 1024 * 1024 * 1024)); // Default 5TB if unlimited
            if ($totalCapacity <= 0) {
                $totalCapacity = 15 * 1024 * 1024 * 1024;
            }

            // 2. Current total used in account by user
            $currentUsed = (int)($data['storageQuota']['usage'] ?? 0);

            // 3. Subtract 2 GB safety buffer for personal emails/files
            $twoGb = 2 * 1024 * 1024 * 1024;
            $remaining = max(0, $totalCapacity - $currentUsed);
            $systemAllowedQuota = max(0, $remaining - $twoGb);

            // Update in DB
            $stmt = $pdo->prepare("
                UPDATE google_accounts 
                SET total_capacity_bytes = ?, 
                    initial_used_bytes = ?, 
                    storage_limit_bytes = ? 
                WHERE id = ?
            ");
            $stmt->execute([$totalCapacity, $currentUsed, $systemAllowedQuota, $account['id']]);

            return [
                'total_capacity_bytes' => $totalCapacity,
                'initial_used_bytes' => $currentUsed,
                'storage_limit_bytes' => $systemAllowedQuota,
            ];
        }

        return null;
    }

    /**
     * Cross-Account File Move:
     * Migrates a file from one connected Google Account to another connected Google Account.
     */
    public static function moveFileBetweenAccounts(array $sourceAccount, array $targetAccount, PDO $pdo, int $fileId): array {
        if ($sourceAccount['id'] === $targetAccount['id']) {
            return ['success' => false, 'error' => 'Source and destination accounts are the same.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
        $stmt->execute([$fileId]);
        $file = $stmt->fetch();
        if (!$file) {
            return ['success' => false, 'error' => 'File not found.'];
        }

        // Verify destination capacity
        $targetUsed = (int)$targetAccount['used_storage_bytes'];
        $targetLimit = (int)$targetAccount['storage_limit_bytes'];
        if (($targetUsed + (int)$file['size_bytes']) > $targetLimit) {
            return ['success' => false, 'error' => 'Target Google account does not have sufficient capacity.'];
        }

        $srcToken = self::getValidAccessToken($sourceAccount, $pdo);
        $tgtToken = self::getValidAccessToken($targetAccount, $pdo);
        if (!$srcToken || !$tgtToken) {
            return ['success' => false, 'error' => 'Failed to obtain OAuth tokens for one or both Google accounts.'];
        }

        // Download source file to temporary file
        $tmpPath = tempnam(sys_get_temp_dir(), 'cd_mv_');
        $fp = fopen($tmpPath, 'w+');
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$file['google_file_id']}?alt=media");
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $srcToken],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        curl_exec($ch);
        $downCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($downCode !== 200 || filesize($tmpPath) === 0) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => "Failed to download file from source Google Drive (HTTP $downCode)."];
        }

        $fileSize = filesize($tmpPath);

        // Upload to Target Account via Resumable Upload
        $uploadUrl = self::createResumableUploadSession($targetAccount, $pdo, $file['name'], $file['mime_type'], $fileSize);
        if (!$uploadUrl) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => 'Failed to initialize upload session on destination Google Drive.'];
        }

        $fp = fopen($tmpPath, 'rb');
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fp,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'),
                'Content-Length: ' . $fileSize,
            ],
            CURLOPT_TIMEOUT => 300,
        ]);
        $upRes = curl_exec($ch);
        $upCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        @unlink($tmpPath);

        if ($upCode !== 200 && $upCode !== 201) {
            return ['success' => false, 'error' => "Failed to upload file to target Google Drive (HTTP $upCode)."];
        }

        $upData = json_decode($upRes, true);
        $newGoogleFileId = $upData['id'] ?? null;
        if (!$newGoogleFileId) {
            return ['success' => false, 'error' => 'Target Google Drive did not return a valid file ID.'];
        }

        // Maintain public access if file was public
        if (!empty($file['is_public'])) {
            self::setGoogleFilePublic($targetAccount, $pdo, $newGoogleFileId, true);
        }

        // Delete from source Google account
        self::deleteGoogleFile($sourceAccount, $pdo, $file['google_file_id']);

        // Update database file record
        $pdo->prepare("UPDATE files SET google_account_id = ?, google_file_id = ? WHERE id = ?")
            ->execute([$targetAccount['id'], $newGoogleFileId, $file['id']]);

        // Adjust pool storage usage
        $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = MAX(0, used_storage_bytes - ?) WHERE id = ?")
            ->execute([(int)$file['size_bytes'], $sourceAccount['id']]);
        $pdo->prepare("UPDATE google_accounts SET used_storage_bytes = used_storage_bytes + ? WHERE id = ?")
            ->execute([(int)$file['size_bytes'], $targetAccount['id']]);

        return ['success' => true, 'new_google_file_id' => $newGoogleFileId];
    }
}

