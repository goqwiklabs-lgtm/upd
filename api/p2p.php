<?php
// ============================================================================
// CloudDrive P2P Transfer Engine (ShareIt / Snapdrop Architecture)
// Zero Cloud Relay • Subnet Peer Discovery • High-Speed Memory-Safe Binary Stream
// ============================================================================

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, HEAD');
header('Access-Control-Allow-Headers: Content-Type, Range, Authorization, X-Requested-With');
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

// ============================================================================
// 1. HEARTBEAT & NEARBY PEER DISCOVERY (SHAREIT RADAR)
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

    // Clean up peers inactive for more than 25 seconds
    $activePeers = [];
    foreach ($peers as $id => $p) {
        if ($now - ($p['last_seen'] ?? 0) <= 25) {
            $activePeers[$id] = $p;
        }
    }
    writeJsonFile($peersFile, $activePeers);

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
        'server_time' => $now,
    ]);
    exit;
}

// ============================================================================
// 2. CREATE TRANSFER SESSION (SENDER INITIATES)
// ============================================================================
if ($action === 'create_session') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $fileName = trim($input['file_name'] ?? 'Shared_File');
    $fileSize = (int)($input['file_size'] ?? 0);
    $mimeType = trim($input['mime_type'] ?? 'application/octet-stream');
    $senderName = trim($input['sender_name'] ?? 'Sender Device');
    $sha256 = trim($input['sha256'] ?? '');

    // Generate 6-digit easy session code
    $sessionId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

    $sessions = readJsonFile($sessionsFile);
    $sessions[$sessionId] = [
        'session_id' => $sessionId,
        'file_name' => $fileName,
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
        'sender_name' => $senderName,
        'sha256' => $sha256,
        'created_at' => time(),
        'status' => 'waiting',
    ];
    writeJsonFile($sessionsFile, $sessions);

    // Build the public join URL using current request host/origin
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    $joinUrl = "{$protocol}://{$host}/?join={$sessionId}";

    echo json_encode([
        'success' => true,
        'session_id' => $sessionId,
        'join_url' => $joinUrl,
        'file' => [
            'name' => $fileName,
            'size' => $fileSize,
            'mime' => $mimeType,
            'sha256' => $sha256,
        ],
    ]);
    exit;
}

// ============================================================================
// 3. UPLOAD BINARY CHUNK FOR SESSION
// ============================================================================
if ($action === 'upload_chunk') {
    $sessionId = trim($_GET['session'] ?? $_POST['session'] ?? '');
    if (empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing session ID']);
        exit;
    }

    $filePath = "{$cacheDir}/{$sessionId}_payload.bin";
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
        echo json_encode(['success' => true, 'bytes_written' => strlen($chunkData)]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to write chunk to disk']);
    }
    exit;
}

// ============================================================================
// 4. GET SESSION DETAILS (RECEIVER CHECKS QR / JOIN CODE)
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
// 5. HIGH-SPEED BINARY STREAMING (ZERO RAM BUFFERING + HTTP RANGE)
// ============================================================================
if ($action === 'stream') {
    $sessionId = strtoupper(trim($_GET['session'] ?? ''));
    $sessions = readJsonFile($sessionsFile);

    if (!isset($sessions[$sessionId])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Session not found']);
        exit;
    }

    $session = $sessions[$sessionId];
    $filePath = "{$cacheDir}/{$sessionId}_payload.bin";

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Payload file not found on disk']);
        exit;
    }

    $fileSize = filesize($filePath);
    $fileName = $session['file_name'];
    $mimeType = $session['mime_type'] ?: 'application/octet-stream';

    // Disable output buffering and compression
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

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

    // Handle HTTP Range Requests
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
        $bytesRemaining = $length;
        $chunkSize = 1024 * 1024; // 1MB buffer

        while (!feof($fp) && $bytesRemaining > 0 && !connection_aborted()) {
            $readSize = min($chunkSize, $bytesRemaining);
            $buffer = fread($fp, $readSize);
            echo $buffer;
            flush();
            $bytesRemaining -= strlen($buffer);
        }
        fclose($fp);
        exit;
    }

    // Full Stream
    header("Content-Length: {$fileSize}");
    http_response_code(200);

    $chunkSize = 1024 * 1024; // 1MB chunked pipe
    while (!feof($fp) && !connection_aborted()) {
        echo fread($fp, $chunkSize);
        flush();
    }
    fclose($fp);
    exit;
}

// ============================================================================
// 6. WEBRTC SIGNALING RELAY (DIRECT BROWSER-TO-BROWSER PEERING)
// ============================================================================
if ($action === 'signal') {
    $signalFile = $cacheDir . '/signals.json';
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'POST') {
        $targetPeer = trim($input['target_peer'] ?? '');
        $senderPeer = trim($input['sender_peer'] ?? '');
        $signalData = $input['signal'] ?? null;

        if (!$targetPeer || !$signalData) {
            echo json_encode(['success' => false, 'error' => 'Missing target_peer or signal']);
            exit;
        }

        $signals = readJsonFile($signalFile);
        $signals[$targetPeer][] = [
            'sender_peer' => $senderPeer,
            'signal' => $signalData,
            'time' => time(),
        ];
        writeJsonFile($signalFile, $signals);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'GET') {
        $myPeerId = trim($_GET['peer_id'] ?? '');
        if (!$myPeerId) {
            echo json_encode(['success' => false, 'error' => 'Missing peer_id']);
            exit;
        }

        $signals = readJsonFile($signalFile);
        $mySignals = $signals[$myPeerId] ?? [];
        unset($signals[$myPeerId]); // Pop signals
        writeJsonFile($signalFile, $signals);

        echo json_encode(['success' => true, 'signals' => $mySignals]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
