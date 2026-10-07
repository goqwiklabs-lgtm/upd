<?php
// ========================================================
// CloudDrive Development CLI Server Router (php -S)
// Supports clean URLs without .php extension
// ========================================================

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// 1. Serve existing static assets & scripts directly
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// 2. Clean P2P Join Links (e.g. /p2p/ABCDEF, /join/ABCDEF)
if (preg_match('#^/(?:p2p|join)/([a-zA-Z0-9_\-]+)/?$#i', $uri, $matches)) {
    $_GET['join'] = $matches[1];
    require __DIR__ . '/index.php';
    return true;
}

// 3. Clean Share Links (e.g. /share/Agd82hwshufsAiheiidhcsi34f)
if (preg_match('#^/share/([a-zA-Z0-9_\-]+)/?$#i', $uri, $matches)) {
    $_GET['token'] = $matches[1];
    require __DIR__ . '/share.php';
    return true;
}

// 4. Clean Folder Links (e.g. /folder/Agd82hwshufsAiheiidhcsi34f)
if (preg_match('#^/folder/([a-zA-Z0-9_\-]+)/?$#i', $uri, $matches)) {
    $_GET['folder'] = $matches[1];
    require __DIR__ . '/share.php';
    return true;
}

// 5. Clean Temp Links (e.g. /temp/CODE or /t/CODE)
if (preg_match('#^/(?:temp|t)/([a-zA-Z0-9_\-]+)/?$#i', $uri, $matches)) {
    $_GET['token'] = $matches[1];
    require __DIR__ . '/temp.php';
    return true;
}

// 1b. Serve index.php inside subdirectories if accessed directly (e.g. /admin/)
if (is_dir($file) && file_exists(rtrim($file, '/') . '/index.php') && $uri !== '/') {
    require rtrim($file, '/') . '/index.php';
    return true;
}

// 6. Clean Admin Route (e.g. /admin or /admin/)
if (preg_match('#^/admin/?$#i', $uri)) {
    require __DIR__ . '/admin/index.php';
    return true;
}

// Default fallback
if ($uri === '/' || $uri === '') {
    require __DIR__ . '/index.php';
    return true;
}

return false;
