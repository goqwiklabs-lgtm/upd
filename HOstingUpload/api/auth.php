<?php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$pdo = getDBConnection();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Your IP address has been blocked by administrator.']);
    exit;
}

// Check current session
if ($action === 'me') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['authenticated' => false]);
        exit;
    }
    // Verify user is not blocked
    $stmt = $pdo->prepare("SELECT id, username, email, role, is_blocked, storage_limit_bytes, remember_token FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !empty($user['is_blocked'])) {
        session_unset();
        session_destroy();
        echo json_encode(['authenticated' => false, 'error' => 'Account blocked.']);
        exit;
    }
    echo json_encode([
        'authenticated' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'storage_limit_bytes' => $user['storage_limit_bytes'],
            'remember_token' => $user['remember_token'] ?? null,
        ]
    ]);
    exit;
}

// User Logout
if ($action === 'logout') {
    if (!empty($_SESSION['user_id'])) {
        try {
            $pdo->prepare("UPDATE users SET remember_token = NULL WHERE id = ?")->execute([$_SESSION['user_id']]);
        } catch (Exception $e) {}
    }
    setcookie('clouddrive_remember', '', time() - 86400, '/', '', false, true);
    session_unset();
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

// Decode JSON or POST payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

// User Registration
if ($action === 'register') {
    $username = trim($data['username'] ?? '');
    $email = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'All fields are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
        exit;
    }

    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
        exit;
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Username or email already exists.']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $role = 'user';

    // If first user, make them admin automatically
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $role = 'admin';
    }

    $rememberToken = bin2hex(random_bytes(32));

    $insert = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, remember_token, registration_ip, last_login_ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $insert->execute([$username, $email, $hash, $role, $rememberToken, $clientIP, $clientIP]);
    $userId = (int)$pdo->lastInsertId();

    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('clouddrive_remember', $rememberToken, time() + (365 * 86400), '/', '', $isHttps, true);

    echo json_encode([
        'success' => true,
        'remember_token' => $rememberToken,
        'user' => [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'role' => $role,
            'storage_limit_bytes' => null,
            'remember_token' => $rememberToken,
        ]
    ]);
    exit;
}

// User Login
if ($action === 'login') {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? '');
    $password = $data['password'] ?? '';

    if (empty($identifier) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide username/email and password.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials.']);
        exit;
    }

    if (!empty($user['is_blocked'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'This account has been blocked by administrator. Access denied.']);
        exit;
    }

    $rememberToken = bin2hex(random_bytes(32));

    // Update last login IP and remember token
    $pdo->prepare("UPDATE users SET last_login_ip = ?, remember_token = ? WHERE id = ?")->execute([$clientIP, $rememberToken, $user['id']]);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    setcookie('clouddrive_remember', $rememberToken, time() + (365 * 86400), '/', '', $isHttps, true);

    echo json_encode([
        'success' => true,
        'remember_token' => $rememberToken,
        'user' => [
            'id' => (int)$user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'storage_limit_bytes' => $user['storage_limit_bytes'],
            'remember_token' => $rememberToken,
        ]
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Invalid auth action.']);
