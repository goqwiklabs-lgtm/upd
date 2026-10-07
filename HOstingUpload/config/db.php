<?php
// ========================================================
// Database Configuration (InfinityFree MySQL / Local)
// ========================================================

// Set your InfinityFree MySQL database details here:
define('DB_HOST', getenv('DB_HOST') ?: 'sql200.infinityfree.com');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'if0_37976074_fileupload');
define('DB_USER', getenv('DB_USER') ?: 'if0_37976074');
define('DB_PASS', getenv('DB_PASS') ?: 'fACI2gSxa2zDmYT');

// SQLite local fallback support if MySQL is firewalled from outside
define('USE_SQLITE_FALLBACK', true);

function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Connect to InfinityFree MySQL
    try {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        initAppSession($pdo);
        return $pdo;
    } catch (PDOException $e) {
        if (!USE_SQLITE_FALLBACK) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
            exit;
        }
    }

    // Local SQLite fallback (so local testing and development work out of the box)
    $sqliteFile = __DIR__ . '/../database.sqlite';
    $initSchema = !file_exists($sqliteFile);
    $pdo = new PDO("sqlite:" . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA busy_timeout = 5000;");
    } catch (Exception $e) {}

    if ($initSchema) {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        // Clean MySQL-specific syntax for SQLite
        $schema = preg_replace('/ENGINE=InnoDB.*?;\s*/i', ';', $schema);
        $schema = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
        $schema = preg_replace('/ENUM\([^)]+\)/i', 'VARCHAR(20)', $schema);
        $schema = preg_replace('/INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $schema);
        $pdo->exec($schema);
    }

    // Auto-ensure migrations exist for backward compatibility
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address VARCHAR(45) NOT NULL UNIQUE,
                reason VARCHAR(255) DEFAULT '',
                blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS live_visitors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id VARCHAR(64) NOT NULL UNIQUE,
                user_id INT NULL,
                username VARCHAR(100) DEFAULT 'Guest',
                ip_address VARCHAR(45) NOT NULL,
                device_type VARCHAR(20) DEFAULT 'Desktop',
                os_name VARCHAR(50) DEFAULT 'Unknown',
                browser_name VARCHAR(50) DEFAULT 'Unknown',
                current_page VARCHAR(150) DEFAULT 'Home',
                last_active_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            // Check users and files columns
            $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            if ($isSqlite) {
                $colsU = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('is_blocked', $colsU)) $pdo->exec("ALTER TABLE users ADD COLUMN is_blocked INTEGER DEFAULT 0");
                if (!in_array('storage_limit_bytes', $colsU)) $pdo->exec("ALTER TABLE users ADD COLUMN storage_limit_bytes BIGINT DEFAULT NULL");
                if (!in_array('registration_ip', $colsU)) $pdo->exec("ALTER TABLE users ADD COLUMN registration_ip VARCHAR(45) DEFAULT NULL");
                if (!in_array('last_login_ip', $colsU)) $pdo->exec("ALTER TABLE users ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL");
                if (!in_array('remember_token', $colsU)) $pdo->exec("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) DEFAULT NULL");

                $colsF = $pdo->query("PRAGMA table_info(files)")->fetchAll(PDO::FETCH_COLUMN, 1);
                if (!in_array('content_tag', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN content_tag VARCHAR(50) DEFAULT 'unclassified'");
                if (!in_array('content_confidence', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN content_confidence INT DEFAULT 0");
                if (!in_array('content_details', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN content_details TEXT NULL");
                if (!in_array('is_visibility_blocked', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN is_visibility_blocked TINYINT(1) DEFAULT 0");
                if (!in_array('blocked_reason', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN blocked_reason VARCHAR(255) NULL");
                if (!in_array('blocked_at', $colsF)) $pdo->exec("ALTER TABLE files ADD COLUMN blocked_at TIMESTAMP NULL");
            } else {
                // MySQL migrations
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) NULL");
                } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            // Ignore if columns already exist or on restricted permission
        }
    }

    initAppSession($pdo);
    return $pdo;
}

function initAppSession(?PDO $pdo = null): void {
    if (session_status() === PHP_SESSION_NONE) {
        $lifetime = 365 * 86400; // 1 year persistent session
        ini_set('session.cookie_lifetime', (string)$lifetime);
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => '',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }

    // Auto-restore session from persistent token if session expired or browser refreshed
    if (empty($_SESSION['user_id']) && $pdo !== null) {
        $token = $_COOKIE['clouddrive_remember'] ?? '';
        if (empty($token) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(\S+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $token = $m[1];
            }
        }
        if (empty($token) && !empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            $token = trim($_SERVER['HTTP_X_AUTH_TOKEN']);
        }
        if (empty($token) && !empty($_GET['auth_token'])) {
            $token = trim($_GET['auth_token']);
        }

        if (!empty($token) && strlen($token) >= 32) {
            try {
                $stmt = $pdo->prepare("SELECT id, username, email, role, is_blocked, storage_limit_bytes FROM users WHERE remember_token = ?");
                $stmt->execute([$token]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($u && empty($u['is_blocked'])) {
                    $_SESSION['user_id'] = (int)$u['id'];
                    $_SESSION['username'] = $u['username'];
                    $_SESSION['email'] = $u['email'];
                    $_SESSION['role'] = $u['role'];
                }
            } catch (Exception $e) {}
        }
    }
}

function getClientIP(): string {
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function isIPBlocked(string $ip, PDO $pdo): bool {
    if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM blocked_ips WHERE ip_address = ?");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function parseUserAgent(?string $ua = null): array {
    $ua = $ua ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $device = 'Desktop';
    $os = 'Unknown OS';
    $browser = 'Unknown Browser';

    if (empty($ua)) {
        return ['device' => 'Desktop', 'os' => 'Unknown', 'browser' => 'Unknown'];
    }

    // Device Detection
    if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
        $device = 'Tablet';
    } elseif (preg_match('/(mobile|iphone|ipod|blackberry|opera mini|iemobile|wpdesktop)/i', $ua)) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }

    // OS Detection
    if (preg_match('/windows nt 10/i', $ua)) $os = 'Windows 10/11';
    elseif (preg_match('/windows nt 6\.3/i', $ua)) $os = 'Windows 8.1';
    elseif (preg_match('/windows nt 6\.2/i', $ua)) $os = 'Windows 8';
    elseif (preg_match('/windows nt 6\.1/i', $ua)) $os = 'Windows 7';
    elseif (preg_match('/windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone|ipad|ipod/i', $ua)) $os = 'iOS';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

    // Browser Detection
    if (preg_match('/edg/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/chrome|crios/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/firefox|fxios/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/opera|opr/i', $ua)) $browser = 'Opera';
    elseif (preg_match('/msie|trident/i', $ua)) $browser = 'Internet Explorer';

    return ['device' => $device, 'os' => $os, 'browser' => $browser];
}

function trackLiveVisitor(PDO $pdo, string $page = 'Home'): void {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sessId = session_id();
        if (empty($sessId)) {
            $sessId = md5(getClientIP() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }

        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $username = isset($_SESSION['username']) ? $_SESSION['username'] : 'Guest Visitor';
        $ip = getClientIP();
        $uaInfo = parseUserAgent();

        $stmt = $pdo->prepare("
            INSERT INTO live_visitors (session_id, user_id, username, ip_address, device_type, os_name, browser_name, current_page, last_active_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(session_id) DO UPDATE SET
                user_id = excluded.user_id,
                username = excluded.username,
                ip_address = excluded.ip_address,
                device_type = excluded.device_type,
                os_name = excluded.os_name,
                browser_name = excluded.browser_name,
                current_page = excluded.current_page,
                last_active_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            $sessId,
            $userId,
            $username,
            $ip,
            $uaInfo['device'],
            $uaInfo['os'],
            $uaInfo['browser'],
            $page
        ]);

        // Occasional cleanup of stale visitors (older than 15 minutes)
        if (mt_rand(1, 20) === 1) {
            $pdo->exec("DELETE FROM live_visitors WHERE last_active_at < datetime('now', '-15 minutes')");
        }
    } catch (Exception $e) {
        // Silently skip tracking errors so main execution never breaks
    }
}

// Auto-initialize persistent 1-year session settings on include
initAppSession();

