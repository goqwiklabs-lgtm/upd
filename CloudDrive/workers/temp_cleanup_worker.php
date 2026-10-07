<?php
// ============================================================================
// CloudDrive Background Temp Upload Cleanup Worker
// Purges expired temp files permanently from Google Drive and Database
// Can be scheduled via crontab: * * * * * php /path/to/workers/temp_cleanup_worker.php
// ============================================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$pdo = getDBConnection();
$now = date('Y-m-d H:i:s');

echo "[" . date('Y-m-d H:i:s') . "] Starting expired temp uploads purge...\n";

$stmt = $pdo->prepare("SELECT id, title FROM temp_uploads WHERE expires_at <= ?");
$stmt->execute([$now]);
$expiredUploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalPurged = 0;
$filesDeleted = 0;

foreach ($expiredUploads as $upload) {
    $uploadId = (int)$upload['id'];
    
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
                $filesDeleted++;
                echo "  -> Deleted Google Drive file: {$f['name']} ({$f['google_file_id']})\n";
            } catch (Exception $e) {
                echo "  -> Failed deleting {$f['google_file_id']}: " . $e->getMessage() . "\n";
            }

            // Decrement storage
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

    $pdo->prepare("DELETE FROM temp_files WHERE temp_upload_id = ?")->execute([$uploadId]);
    $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$uploadId]);
    $totalPurged++;
}

echo "[" . date('Y-m-d H:i:s') . "] Finished purge: {$totalPurged} transfers, {$filesDeleted} Google Drive files deleted permanently.\n";
