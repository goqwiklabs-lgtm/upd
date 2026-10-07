<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
$pdo = getDBConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? 'heartbeat';
$page = trim($_GET['page'] ?? $_POST['page'] ?? 'CloudDrive');

if ($action === 'heartbeat') {
    trackLiveVisitor($pdo, $page);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Invalid action']);
