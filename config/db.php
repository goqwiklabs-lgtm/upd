<?php
// ========================================================
// Database Configuration (InfinityFree MySQL / Local)
// ========================================================

// Set your InfinityFree MySQL database details here:
define('DB_HOST', getenv('DB_HOST') ?: 'sqlXXX.infinityfree.com'); // e.g. sql123.infinityfree.com or localhost
define('DB_NAME', getenv('DB_NAME') ?: 'epiz_xxxxxxx_clouddrive'); // e.g. epiz_12345678_drive
define('DB_USER', getenv('DB_USER') ?: 'epiz_xxxxxxx');            // e.g. epiz_12345678
define('DB_PASS', getenv('DB_PASS') ?: 'YOUR_VPANEL_PASSWORD');    // InfinityFree vPanel password

// SQLite local fallback support if MySQL is not configured yet
define('USE_SQLITE_FALLBACK', true);

function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // Try MySQL first if credentials were changed from default or environment variables exist
    if (DB_HOST !== 'sqlXXX.infinityfree.com' || getenv('DB_HOST')) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        } catch (PDOException $e) {
            if (!USE_SQLITE_FALLBACK) {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
                exit;
            }
        }
    }

    // Local SQLite fallback (so local testing and development work out of the box)
    $sqliteFile = __DIR__ . '/../database.sqlite';
    $initSchema = !file_exists($sqliteFile);
    $pdo = new PDO("sqlite:" . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    if ($initSchema) {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        // Clean MySQL-specific syntax for SQLite
        $schema = preg_replace('/ENGINE=InnoDB.*?;\s*/i', ';', $schema);
        $schema = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $schema);
        $schema = preg_replace('/ENUM\([^)]+\)/i', 'VARCHAR(20)', $schema);
        $schema = preg_replace('/INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $schema);
        $pdo->exec($schema);
    }

    return $pdo;
}
