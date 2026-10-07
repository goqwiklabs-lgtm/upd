<?php
// ============================================================================
// CloudDrive High-Performance P2P Engine (ShareIt & LocalSend Architecture)
// Zero Cloud Relay • LAN WebRTC DataChannel • Subnet Radar & Global Notifications
// ============================================================================

// Allow large transfers and streaming
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', '0');
ini_set('max_input_time', '0');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Range, Authorization, X-Requested-With, X-File-Index, X-Chunk-Index');
header('Access-Control-Expose-Headers: Content-Range, Content-Length, Accept-Ranges, Content-Disposition');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$cacheDir = __DIR__ . '/../cache/p2p';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}

$peersFile = $cacheDir . '/peers.json';
$sessionsFile = $cacheDir . '/sessions.json';
$offersFile = $cacheDir . '/offers.json';
$signalFile = $cacheDir . '/signals.json';

// Helper: Read JSON file safely with flock
function readJsonFile(string $path): array {
    if (!file_exists($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

// Helper: Write JSON file safely with flock
function writeJsonFile(string $path, array $data): void {
    $fp = fopen($path, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
}

$action = $_GET['action'] ?? '';

// Fast network probe endpoint for automatic pause/resume testing
if ($action === 'ping') {
    echo json_encode(['success' => true, 'pong' => true]);
    exit;
}

// ============================================================================
// 1. HEARTBEAT & NEARBY DISCOVERY + PENDING OFFERS DISPATCH
// ============================================================================
if ($action === 'heartbeat') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $peerId = trim($input['peer_id'] ?? '');
    $deviceName = trim($input['device_name'] ?? 'Unknown Device');
    $deviceType = trim($input['device_type'] ?? 'desktop');

    if (empty($peerId)) {
        echo json_encode(['success' => false, 'error' => 'Missing peer_id']);
        exit;
    }

    $now = time();
    $peers = readJsonFile($peersFile);

    // Register/update current peer
    $peers[$peerId] = [
        'peer_id' => $peerId,
        'device_name' => $deviceName,
        'device_type' => $deviceType,
        'last_seen' => $now,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
    ];

    // Clean up peers inactive for more than 20 seconds
    $activePeers = [];
    foreach ($peers as $id => $p) {
        if ($now - ($p['last_seen'] ?? 0) <= 20) {
            $activePeers[$id] = $p;
        }
    }
    writeJsonFile($peersFile, $activePeers);

    // Check for pending incoming transfer offers addressed to this peer
    $offers = readJsonFile($offersFile);
    $myOffers = [];
    $remainingOffers = [];
    foreach ($offers as $offId => $off) {
        // Expire offers older than 60 seconds
        if ($now - ($off['created_at'] ?? 0) > 60) {
            continue;
        }
        if (($off['target_peer'] ?? '') === $peerId && ($off['status'] ?? '') === 'pending') {
            $myOffers[] = $off;
        }
        $remainingOffers[$offId] = $off;
    }
    writeJsonFile($offersFile, $remainingOffers);

    // Return other active peers (excluding self)
    $nearby = [];
    foreach ($activePeers as $id => $p) {
        if ($id !== $peerId) {
            $nearby[] = $p;
        }
    }

    echo json_encode([
        'success' => true,
        'peers' => array_values($nearby),
        'offers' => $myOffers,
        'server_time' => $now,
    ]);
    exit;
}

// ============================================================================
// 2. DISPATCH TRANSFER OFFER TO TARGET PEER (RADAR TAP / SHAREIT NOTIFICATION)
// ============================================================================
if ($action === 'send_offer') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $targetPeer = trim($input['target_peer'] ?? '');
    $senderPeer = trim($input['sender_peer'] ?? '');
    $senderName = trim($input['sender_name'] ?? 'Nearby Device');
    $sessionId = trim($input['session_id'] ?? '');
    $files = $input['files'] ?? [];
    $totalSize = (int)($input['total_size'] ?? 0);

    if (empty($targetPeer) || empty($sessionId)) {
        echo json_encode(['success' => false, 'error' => 'Missing target_peer or session_id']);
        exit;
    }

    $offerId = 'off_' . bin2hex(random_bytes(4));
    $offers = readJsonFile($offersFile);

    $offers[$offerId] = [
        'offer_id' => $offerId,
        'sender_peer' => $senderPeer,
        'sender_name' => $senderName,
        'target_peer' => $targetPeer,
        'session_id' => $sessionId,
        'files' => $files,
        'total_size' => $totalSize,
        'created_at' => time(),
        'status' => 'pending',
    ];

    writeJsonFile($offersFile, $offers);

    echo json_encode([
        'success' => true,
        'offer_id' => $offerId,
    ]);
    exit;
}

// ============================================================================
// 3. RESPOND TO TRANSFER OFFER (ACCEPT / DECLINE)
// ============================================================================
if ($action === 'respond_offer') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $offerId = trim($input['offer_id'] ?? '');
    $response = trim($input['response'] ?? 'decline'); // 'accept' or 'decline'

    $offers = readJsonFile($offersFile);
    if (!isset($offers[$offerId])) {
        echo json_encode(['success' => false, 'error' => 'Offer not found or expired']);
        exit;
    }

    $offers[$offerId]['status'] = ($response === 'accept') ? 'accepted' : 'declined';
    $offers[$offerId]['responded_at'] = time();
    writeJsonFile($offersFile, $offers);

    echo json_encode([
        'success' => true,
        'status' => $offers[$offerId]['status'],
        'session_id' => $offers[$offerId]['session_id'],
    ]);
    exit;
}

// ============================================================================
// 4. CHECK STATUS OF AN OFFER (SENDER CHECKS IF RECEIVER ACCEPTED)
// ============================================================================
if ($action === 'check_offer_status') {
    $offerId = trim($_GET['offer_id'] ?? '');
    $offers = readJsonFile($offersFile);

    if (!isset($offers[$offerId])) {
        echo json_encode(['success' => false, 'status' => 'expired']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status' => $offers[$offerId]['status'],
        'session_id' => $offers[$offerId]['session_id'],
    ]);
    exit;
}

// ============================================================================
// 5. CREATE MULTI-FILE TRANSFER SESSION
// ============================================================================
if ($action === 'create_session') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $senderName = trim($input['sender_name'] ?? 'Sender Device');
    $senderPeer = trim($input['sender_peer'] ?? '');

    // Process files list (supports single file or multiple files)
    $files = [];
    $totalSize = 0;

    if (!empty($input['files']) && is_array($input['files'])) {
        foreach ($input['files'] as $idx => $f) {
            $fSize = (int)($f['size'] ?? 0);
            $totalSize += $fSize;
            $files[] = [
                'index' => $idx,
                'name' => trim($f['name'] ?? "File_{$idx}"),
                'size' => $fSize,
                'type' => trim($f['type'] ?? 'application/octet-stream'),
                'sha256' => trim($f['sha256'] ?? ''),
            ];
        }
    } else {
        // Fallback for single file input
        $fileName = trim($input['file_name'] ?? 'Shared_File');
        $fileSize = (int)($input['file_size'] ?? 0);
        $mimeType = trim($input['mime_type'] ?? 'application/octet-stream');
        $totalSize = $fileSize;
        $files[] = [
            'index' => 0,
            'name' => $fileName,
            'size' => $fileSize,
            'type' => $mimeType,
            'sha256' => trim($input['sha256'] ?? ''),
        ];
    }

    // Generate 6-character easy join code
    $sessionId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $sessions = readJsonFile($sessionsFile);
    $sessions[$sessionId] = [
        'session_id' => $sessionId,
        'sender_peer' => $senderPeer,
        'sender_name' => $senderName,
        'files' => $files,
        'total_size' => $totalSize,
        'uploaded_bytes' => $totalSize,
        'upload_percent' => 100,
        'created_at' => time(),
        'status' => 'ready', // Pure P2P: 100% instantly ready! Zero wait!
    ];
    writeJsonFile($sessionsFile, $sessions);

    // Build the public join URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $joinUrl = "{$protocol}://{$host}/?join={$sessionId}";

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'join_url' => $joinUrl,
        'files' => $files,
        'total_size' => $totalSize,
        'file_count' => count($files),
        'status' => 'ready',
    ]);
    exit;
}

// ============================================================================
// 6. UPLOAD BINARY CHUNK FOR MULTI-FILE STREAMING (HTTP FALLBACK)
// ============================================================================
if ($action === 'upload_chunk') {
    $sessionId = trim($_GET['session'] ?? $_POST['session'] ?? '');
    $fileIndex = (int)($_GET['file_index'] ?? $_POST['file_index'] ?? 0);
    $uploadedBytes = (int)($_GET['uploaded_bytes'] ?? $_POST['uploaded_bytes'] ?? 0);
    $isFinal = (int)($_GET['is_final'] ?? $_POST['is_final'] ?? 0);

    if (empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing session ID']);
        exit;
    }

    $filePath = "{$cacheDir}/{$sessionId}_{$fileIndex}_payload.bin";
    $chunkData = file_get_contents('php://input');

    if ($chunkData === false || strlen($chunkData) === 0) {
        echo json_encode(['success' => false, 'error' => 'Empty chunk payload']);
        exit;
    }

    // Append chunk directly to disk
    $fp = fopen($filePath, 'a');
    if ($fp) {
        fwrite($fp, $chunkData);
        fclose($fp);

        // Update session progress in background
        $sessions = readJsonFile($sessionsFile);
        if (isset($sessions[$sessionId])) {
            $currentTotal = (int)$sessions[$sessionId]['total_size'];
            $newBytes = max((int)($sessions[$sessionId]['uploaded_bytes'] ?? 0), $uploadedBytes);
            $sessions[$sessionId]['uploaded_bytes'] = $newBytes;
            $pct = ($currentTotal > 0) ? min(100, (int)round(($newBytes / $currentTotal) * 100)) : 0;
            $sessions[$sessionId]['upload_percent'] = $pct;
            if ($isFinal === 1 || $pct >= 100) {
                $sessions[$sessionId]['status'] = 'ready';
                $sessions[$sessionId]['upload_percent'] = 100;
            }
            writeJsonFile($sessionsFile, $sessions);
        }

        echo json_encode([
            'success' => true,
            'bytes_written' => strlen($chunkData),
            'total_on_disk' => file_exists($filePath) ? filesize($filePath) : 0,
            'status' => $sessions[$sessionId]['status'] ?? 'buffering',
            'upload_percent' => $sessions[$sessionId]['upload_percent'] ?? 0,
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write chunk to disk']);
    }
    exit;
}

// ============================================================================
// 6b. EXPLICIT PROGRESS UPDATE / STATUS NOTIFIER
// ============================================================================
if ($action === 'update_progress') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $sessionId = strtoupper(trim($input['session_id'] ?? ''));
    $pct = (int)($input['upload_percent'] ?? 0);
    $bytes = (int)($input['uploaded_bytes'] ?? 0);
    $status = trim($input['status'] ?? 'buffering');

    $sessions = readJsonFile($sessionsFile);
    if (isset($sessions[$sessionId])) {
        $sessions[$sessionId]['upload_percent'] = $pct;
        if ($bytes > 0) $sessions[$sessionId]['uploaded_bytes'] = $bytes;
        if ($status) $sessions[$sessionId]['status'] = $status;
        writeJsonFile($sessionsFile, $sessions);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ============================================================================
// 7. GET SESSION DETAILS (RECEIVER LOOKUP BY CODE / QR / NOTIFICATION)
// ============================================================================
if ($action === 'get_session') {
    $sessionId = strtoupper(trim($_GET['session'] ?? ''));
    $sessions = readJsonFile($sessionsFile);

    if (!isset($sessions[$sessionId])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Transfer session not found or expired']);
        exit;
    }

    $session = $sessions[$sessionId];
    echo json_encode([
        'success' => true,
        'session' => $session,
    ]);
    exit;
}

// ============================================================================
// 8. HIGH-SPEED RESUMABLE BINARY STREAMING (ZERO CUTOFF + HTTP RANGE)
// ============================================================================
if ($action === 'stream') {
    $sessionId = strtoupper(trim($_GET['session'] ?? ''));
    $fileIndex = (int)($_GET['file_index'] ?? 0);
    $sessions = readJsonFile($sessionsFile);

    if (!isset($sessions[$sessionId])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }

    $session = $sessions[$sessionId];
    $files = $session['files'] ?? [];
    $targetFile = null;

    foreach ($files as $f) {
        if ((int)$f['index'] === $fileIndex) {
            $targetFile = $f;
            break;
        }
    }

    if (!$targetFile && !empty($files)) {
        $targetFile = $files[0];
    }

    $expectedSize = $targetFile ? (int)$targetFile['size'] : 0;
    $fileName = $targetFile ? $targetFile['name'] : "download_{$sessionId}.bin";
    $mimeType = $targetFile ? ($targetFile['type'] ?: 'application/octet-stream') : 'application/octet-stream';

    $filePath = "{$cacheDir}/{$sessionId}_{$fileIndex}_payload.bin";

    // Wait up to 5 seconds if file hasn't started writing yet
    $waitStart = time();
    while (!file_exists($filePath) && (time() - $waitStart) < 5) {
        usleep(150000); // 150ms sleep
    }

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payload file not yet ready']);
        exit;
    }

    // Disable output buffering and compression completely
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $currentDiskSize = filesize($filePath);
    $fileSize = $expectedSize > 0 ? $expectedSize : $currentDiskSize;

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . rawurlencode($fileName) . '"');
    header('Accept-Ranges: bytes');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $fp = fopen($filePath, 'rb');

    if (!$fp) {
        http_response_code(500);
        exit;
    }

    // Handle HTTP Range Requests (Pause / Resume Support)
    if (!empty($range) && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
        $start = (int)$matches[1];
        $end = !empty($matches[2]) ? (int)$matches[2] : ($fileSize - 1);

        if ($start >= $fileSize || $end >= $fileSize || $start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */{$fileSize}");
            fclose($fp);
            exit;
        }

        $length = $end - $start + 1;
        http_response_code(206);
        header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
        header("Content-Length: {$length}");

        fseek($fp, $start);
        $bytesSent = 0;
        $chunkSize = 1024 * 1024; // 1MB buffer

        $stallCount = 0;
        while ($bytesSent < $length && !connection_aborted()) {
            $currentDisk = filesize($filePath);
            $bytesAvailable = $currentDisk - ftell($fp);

            if ($bytesAvailable <= 0) {
                // Wait briefly if sender is still writing chunks (max 1s so worker is never locked)
                if ($currentDisk < $fileSize && $stallCount < 10) {
                    usleep(100000); // 100ms
                    $stallCount++;
                    clearstatcache(true, $filePath);
                    continue;
                } else {
                    break;
                }
            }
            $stallCount = 0;

            $readSize = min($chunkSize, $length - $bytesSent, $bytesAvailable);
            $buffer = fread($fp, $readSize);
            if ($buffer === false || strlen($buffer) === 0) {
                break;
            }
            echo $buffer;
            flush();
            $bytesSent += strlen($buffer);
        }
        fclose($fp);
        exit;
    }

    // Full Stream (Non-range)
    header("Content-Length: {$fileSize}");
    http_response_code(200);

    $chunkSize = 1024 * 1024; // 1MB chunks
    $bytesSent = 0;
    $stallCount = 0;

    while ($bytesSent < $fileSize && !connection_aborted()) {
        $currentDisk = filesize($filePath);
        $bytesAvailable = $currentDisk - ftell($fp);

        if ($bytesAvailable <= 0) {
            if ($currentDisk < $fileSize && $stallCount < 10) {
                usleep(100000); // Max 1s wait
                $stallCount++;
                clearstatcache(true, $filePath);
                continue;
            } else {
                break;
            }
        }
        $stallCount = 0;

        $readSize = min($chunkSize, $fileSize - $bytesSent, $bytesAvailable);
        $buffer = fread($fp, $readSize);
        if ($buffer === false || strlen($buffer) === 0) {
            break;
        }
        echo $buffer;
        flush();
        $bytesSent += strlen($buffer);
    }

    fclose($fp);
    exit;
}

// ============================================================================
// 9. WEBRTC SIGNALING RELAY (DIRECT BROWSER-TO-BROWSER PEERING: 30-80 MB/s)
// ============================================================================
if ($action === 'signal') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        $sessionId = strtoupper(trim($input['session_id'] ?? ''));
        $toRole = trim($input['to'] ?? ''); // 'sender' or 'receiver'
        $targetPeer = trim($input['target_peer'] ?? '');
        $senderPeer = trim($input['sender_peer'] ?? '');
        $signalData = $input['signal'] ?? null;

        if (!$signalData) {
            echo json_encode(['success' => false, 'error' => 'Missing signal']);
            exit;
        }

        // Support routing by (session_id + toRole) or target_peer
        $targetKey = ($sessionId && $toRole) ? "{$sessionId}_{$toRole}" : $targetPeer;
        if (!$targetKey) {
            echo json_encode(['success' => false, 'error' => 'Missing target_peer or session_id+to']);
            exit;
        }

        $signals = readJsonFile($signalFile);
        $signalItem = [
            'id' => uniqid('sig_', true),
            'sender_peer' => $senderPeer,
            'signal' => $signalData,
            'time' => microtime(true),
        ];

        if (!isset($signals[$targetKey]) || !is_array($signals[$targetKey])) {
            $signals[$targetKey] = [];
        }
        $signals[$targetKey][] = $signalItem;

        // Cleanup stale signals older than 120s
        $now = microtime(true);
        foreach ($signals as $k => $list) {
            if (is_array($list)) {
                $signals[$k] = array_values(array_filter($list, function($item) use ($now) {
                    return ($now - (float)($item['time'] ?? 0)) < 120;
                }));
                if (empty($signals[$k])) {
                    unset($signals[$k]);
                }
            }
        }

        writeJsonFile($signalFile, $signals);
        echo json_encode(['success' => true, 'id' => $signalItem['id']]);
        exit;
    }

    if ($method === 'GET') {
        $sessionId = strtoupper(trim($_GET['session_id'] ?? ''));
        $role = trim($_GET['role'] ?? ''); // 'sender' or 'receiver'
        $myPeerId = trim($_GET['peer_id'] ?? '');
        $since = (float)($_GET['since'] ?? 0);
        $shouldPop = !empty($_GET['pop']);

        $lookupKey = ($sessionId && $role) ? "{$sessionId}_{$role}" : $myPeerId;
        if (!$lookupKey) {
            echo json_encode(['success' => false, 'error' => 'Missing peer_id or session_id+role']);
            exit;
        }

        $signals = readJsonFile($signalFile);
        $list = $signals[$lookupKey] ?? [];
        $results = [];

        if (is_array($list)) {
            foreach ($list as $item) {
                $t = (float)($item['time'] ?? 0);
                if ($since <= 0 || $t > $since) {
                    $results[] = $item;
                }
            }
        }

        if ($shouldPop) {
            unset($signals[$lookupKey]);
            writeJsonFile($signalFile, $signals);
        }

        echo json_encode([
            'success' => true,
            'signals' => $results,
            'server_time' => microtime(true),
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
