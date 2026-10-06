<?php
require_once __DIR__ . '/config/db.php';

$pdo = getDBConnection();
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Invalid share link.');
}

$stmt = $pdo->prepare("SELECT f.*, u.username FROM files f JOIN users u ON f.user_id = u.id WHERE f.share_token = ?");
$stmt->execute([$token]);
$file = $stmt->fetch();

if (!$file) {
    die('File not found or link has expired.');
}

$fileName = htmlspecialchars($file['name']);
$mimeType = $file['mime_type'];
$fileSizeMb = round($file['size_bytes'] / (1024 * 1024), 2);
$isImage = strpos($mimeType, 'image/') === 0;
$isVideo = strpos($mimeType, 'video/') === 0;
$isAudio = strpos($mimeType, 'audio/') === 0;
$isPdf = $mimeType === 'application/pdf';
$streamUrl = "stream.php?token=" . urlencode($token);
$downloadUrl = "stream.php?token=" . urlencode($token) . "&download=1";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $fileName ?> - Shared Cloud File</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-between">
    <!-- Navbar -->
    <header class="border-b border-slate-800 bg-slate-950/70 backdrop-blur px-6 py-4 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <div class="w-9 h-9 rounded-xl bg-blue-600 flex items-center justify-center text-white shadow-lg shadow-blue-500/20">
                <i class="fa-solid fa-cloud"></i>
            </div>
            <span class="font-bold text-lg text-white">CloudDrive</span>
        </div>
        <div>
            <a href="<?= $downloadUrl ?>" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-xl inline-flex items-center space-x-2 transition shadow-lg shadow-blue-600/30">
                <i class="fa-solid fa-download"></i>
                <span>Download (<?= $fileSizeMb ?> MB)</span>
            </a>
        </div>
    </header>

    <!-- Content Viewer -->
    <main class="flex-1 flex flex-col items-center justify-center p-4 sm:p-8">
        <div class="w-full max-w-4xl bg-slate-950/60 border border-slate-800/80 rounded-2xl overflow-hidden shadow-2xl backdrop-blur">
            <!-- Media Preview Area -->
            <div class="bg-black/40 flex items-center justify-center min-h-[360px] max-h-[600px] overflow-hidden p-2">
                <?php if ($isVideo): ?>
                    <video controls autoplay class="w-full max-h-[560px] rounded-lg shadow" preload="metadata">
                        <source src="<?= $streamUrl ?>" type="<?= htmlspecialchars($mimeType) ?>">
                        Your browser does not support video playback.
                    </video>
                <?php elseif ($isImage): ?>
                    <img src="<?= $streamUrl ?>" alt="<?= $fileName ?>" class="max-h-[560px] object-contain rounded-lg">
                <?php elseif ($isAudio): ?>
                    <div class="p-8 text-center w-full">
                        <div class="w-20 h-20 mx-auto rounded-full bg-blue-500/10 text-blue-400 flex items-center justify-center text-3xl mb-4">
                            <i class="fa-solid fa-music"></i>
                        </div>
                        <audio controls class="w-full max-w-md mx-auto">
                            <source src="<?= $streamUrl ?>" type="<?= htmlspecialchars($mimeType) ?>">
                        </audio>
                    </div>
                <?php elseif ($isPdf): ?>
                    <iframe src="<?= $streamUrl ?>" class="w-full h-[560px] rounded-lg"></iframe>
                <?php else: ?>
                    <div class="text-center p-12">
                        <div class="w-24 h-24 mx-auto rounded-2xl bg-slate-800 text-slate-400 flex items-center justify-center text-4xl mb-4">
                            <i class="fa-solid fa-file"></i>
                        </div>
                        <p class="text-slate-400">Preview is not available for this file type.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- File Details Footer -->
            <div class="p-6 flex flex-col sm:flex-row items-start sm:items-center justify-between border-t border-slate-800/80 bg-slate-950/90 gap-4">
                <div>
                    <h1 class="text-xl font-bold text-white mb-1"><?= $fileName ?></h1>
                    <div class="text-sm text-slate-400 flex items-center space-x-3">
                        <span><i class="fa-solid fa-hard-drive mr-1"></i> <?= $fileSizeMb ?> MB</span>
                        <span>•</span>
                        <span><i class="fa-solid fa-user mr-1"></i> Shared by <?= htmlspecialchars($file['username']) ?></span>
                        <span>•</span>
                        <span><i class="fa-solid fa-clock mr-1"></i> <?= date('M d, Y', strtotime($file['created_at'])) ?></span>
                    </div>
                </div>
                <div class="flex items-center space-x-3 w-full sm:w-auto">
                    <a href="<?= $downloadUrl ?>" class="w-full sm:w-auto text-center px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/25 transition">
                        <i class="fa-solid fa-download mr-2"></i> Download
                    </a>
                </div>
            </div>
        </div>
    </main>

    <footer class="py-4 text-center text-xs text-slate-500 border-t border-slate-800/50">
        Powered by CloudDrive Multi-Account Storage Engine
    </footer>
</body>
</html>
