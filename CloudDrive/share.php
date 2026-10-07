<?php
require_once __DIR__ . '/config/db.php';

$pdo = getDBConnection();
$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    die('Access denied. Your IP has been blocked by administrator.');
}

$token = trim($_GET['temp'] ?? $_GET['token'] ?? $_GET['folder'] ?? '');

if (empty($token)) {
    die('Invalid or missing share link token.');
}

// Check if token belongs to a Temp Upload
$tempCheck = $pdo->prepare("SELECT id FROM temp_uploads WHERE share_token = ?");
$tempCheck->execute([$token]);
if ($tempCheck->fetch()) {
    header("Location: temp.php?token=" . urlencode($token));
    exit;
}

// 1. Check if token belongs to a Shared Folder
$folderStmt = $pdo->prepare("SELECT fo.*, u.username FROM folders fo JOIN users u ON fo.user_id = u.id WHERE fo.share_token = ?");
$folderStmt->execute([$token]);
$sharedFolder = $folderStmt->fetch();

if ($sharedFolder && !empty($sharedFolder['is_public'])) {
    $folderName = htmlspecialchars($sharedFolder['name']);
    $folderOwner = htmlspecialchars($sharedFolder['username']);
    $folderDate = date('M d, Y', strtotime($sharedFolder['created_at']));
    $folderId = (int)$sharedFolder['id'];

    // Fetch all files currently inside this shared folder (excluding quarantined files)
    $filesStmt = $pdo->prepare("SELECT * FROM files WHERE folder_id = ? AND (is_visibility_blocked = 0 OR is_visibility_blocked IS NULL) ORDER BY created_at DESC, id DESC");
    $filesStmt->execute([$folderId]);
    $folderFiles = $filesStmt->fetchAll();

    $totalBytes = array_sum(array_column($folderFiles, 'size_bytes'));
    $totalCount = count($folderFiles);
    $totalMb = round($totalBytes / (1024 * 1024), 2);
    $totalSizeStr = $totalMb < 1 ? round($totalBytes / 1024, 1) . ' KB' : ($totalMb > 1024 ? round($totalMb / 1024, 2) . ' GB' : $totalMb . ' MB');

    // Subfolders
    $subStmt = $pdo->prepare("SELECT * FROM folders WHERE parent_id = ? AND is_public = 1 ORDER BY name ASC");
    $subStmt->execute([$folderId]);
    $subFolders = $subStmt->fetchAll();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $folderName ?> - Shared Google Drive Folder</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </head>
    <body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-between selection:bg-blue-600 selection:text-white">

        <!-- Top Navigation Bar -->
        <header class="border-b border-slate-800 bg-slate-950/90 backdrop-blur px-6 py-4 flex items-center justify-between sticky top-0 z-40">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-600 flex items-center justify-center text-white shadow-lg shadow-amber-500/20">
                    <i class="fa-solid fa-folder-open text-lg"></i>
                </div>
                <div>
                    <span class="font-bold text-lg text-white">CloudDrive</span>
                    <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 ml-1.5 border border-amber-500/30">Shared Folder</span>
                </div>
            </div>

            <!-- Search Input -->
            <div class="hidden sm:flex items-center flex-1 max-w-sm mx-6">
                <div class="relative w-full">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-500 text-xs"></i>
                    <input type="text" id="folder-search-input" oninput="filterSharedFiles()" placeholder="Search in this folder..." class="w-full pl-8 pr-3 py-1.5 bg-slate-800/80 border border-slate-700/80 rounded-xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-blue-500 transition">
                </div>
            </div>

            <div class="flex items-center space-x-3">
                <div class="flex items-center space-x-1.5 px-3 py-1.5 bg-emerald-500/10 border border-emerald-500/20 rounded-xl text-emerald-400 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <span class="hidden sm:inline">Live Synced</span>
                </div>
                <button onclick="pollSharedFolder(true)" class="p-2 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl transition" title="Check for latest uploads">
                    <i class="fa-solid fa-arrows-rotate text-xs" id="refresh-icon"></i>
                </button>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 max-w-6xl w-full mx-auto p-4 sm:p-8 space-y-6">

            <!-- Live Upload Alert Banner -->
            <div id="live-upload-banner" class="hidden p-4 bg-emerald-500/15 border border-emerald-500/30 rounded-2xl flex items-center justify-between text-emerald-300 text-xs font-medium animate-bounce">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-circle-check text-emerald-400 text-base"></i>
                    <span id="live-upload-text">Owner uploaded new files! Synced just now.</span>
                </div>
                <button onclick="document.getElementById('live-upload-banner').classList.add('hidden')" class="text-emerald-400 hover:text-white">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <!-- Folder Header Card -->
            <div class="bg-slate-950/70 border border-slate-800 rounded-3xl p-6 sm:p-8 backdrop-blur shadow-xl relative overflow-hidden">
                <div class="absolute -right-8 -top-8 w-40 h-40 bg-amber-500/10 rounded-full blur-2xl pointer-events-none"></div>
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start space-x-4">
                        <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-amber-500 to-amber-600 flex items-center justify-center text-white text-2xl shadow-lg shadow-amber-500/25 shrink-0">
                            <i class="fa-solid fa-folder-open"></i>
                        </div>
                        <div>
                            <h1 class="text-xl sm:text-2xl font-black text-white tracking-tight"><?= $folderName ?></h1>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400 mt-1">
                                <span><i class="fa-solid fa-user mr-1 text-slate-500"></i> Shared by <strong class="text-slate-300">@<?= $folderOwner ?></strong></span>
                                <span>•</span>
                                <span><i class="fa-solid fa-calendar mr-1 text-slate-500"></i> <?= $folderDate ?></span>
                                <span>•</span>
                                <span id="summary-badge" class="text-amber-400 font-bold"><?= $totalCount ?> files (<?= $totalSizeStr ?>)</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 shrink-0">
                        <button onclick="copyFolderShareLink()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl border border-slate-700/80 flex items-center space-x-1.5 transition">
                            <i class="fa-solid fa-copy"></i>
                            <span>Copy Link</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Subfolders (if any) -->
            <?php if (!empty($subFolders)): ?>
            <div>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Folders</h3>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    <?php foreach ($subFolders as $sf): ?>
                    <a href="share.php?folder=<?= urlencode($sf['share_token']) ?>" class="bg-slate-950/60 hover:bg-slate-800 border border-slate-800 hover:border-amber-500/50 rounded-2xl p-3.5 flex items-center space-x-3 transition group">
                        <i class="fa-solid fa-folder text-amber-400 text-lg group-hover:scale-110 transition-transform"></i>
                        <span class="text-xs font-medium text-slate-200 truncate"><?= htmlspecialchars($sf['name']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Files Grid -->
            <div>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center space-x-2">
                        <span>All Uploaded Files</span>
                        <span id="files-counter-pill" class="px-2 py-0.5 rounded-full bg-slate-800 text-slate-300 text-[10px] font-bold"><?= $totalCount ?></span>
                    </h3>
                    <span class="text-[11px] text-slate-500">Auto-updated live &bull; Just like Google Drive</span>
                </div>

                <div id="shared-files-grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <?php if (empty($folderFiles)): ?>
                    <div id="empty-state-card" class="col-span-full py-16 text-center text-slate-500 bg-slate-950/40 rounded-3xl border border-slate-800/80">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-slate-800 flex items-center justify-center text-slate-400 text-2xl">
                            <i class="fa-regular fa-folder-open"></i>
                        </div>
                        <p class="font-semibold text-slate-300 text-sm">No files uploaded in this folder yet</p>
                        <p class="text-xs text-slate-500 mt-1">Whenever @<?= $folderOwner ?> uploads files to this folder, they will automatically appear here!</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($folderFiles as $ff):
                        $fName = htmlspecialchars($ff['name']);
                        $fExt = strtolower(pathinfo($ff['name'], PATHINFO_EXTENSION));
                        $fBytes = (int)$ff['size_bytes'];
                        $fMb = round($fBytes / (1024 * 1024), 2);
                        $fSizeStr = $fMb < 0.1 ? round($fBytes / 1024, 1) . ' KB' : $fMb . ' MB';
                        $fToken = htmlspecialchars($ff['share_token']);
                        $fDate = date('M d, H:i', strtotime($ff['created_at']));

                        // Icon selection
                        $iconClass = 'fa-regular fa-file';
                        $iconColor = 'text-slate-400 bg-slate-800';
                        if (in_array($fExt, ['mp4', 'mkv', 'webm', 'mov', 'avi'])) {
                            $iconClass = 'fa-solid fa-film';
                            $iconColor = 'text-purple-400 bg-purple-500/15 border border-purple-500/20';
                        } elseif (in_array($fExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                            $iconClass = 'fa-solid fa-image';
                            $iconColor = 'text-blue-400 bg-blue-500/15 border border-blue-500/20';
                        } elseif (in_array($fExt, ['mp3', 'wav', 'ogg', 'm4a', 'flac'])) {
                            $iconClass = 'fa-solid fa-music';
                            $iconColor = 'text-pink-400 bg-pink-500/15 border border-pink-500/20';
                        } elseif ($fExt === 'pdf') {
                            $iconClass = 'fa-solid fa-file-pdf';
                            $iconColor = 'text-rose-400 bg-rose-500/15 border border-rose-500/20';
                        } elseif (in_array($fExt, ['zip', 'rar', '7z', 'tar', 'gz'])) {
                            $iconClass = 'fa-solid fa-file-zipper';
                            $iconColor = 'text-amber-400 bg-amber-500/15 border border-amber-500/20';
                        } elseif (in_array($fExt, ['txt', 'js', 'py', 'json', 'html', 'css', 'php', 'ts', 'tsx'])) {
                            $iconClass = 'fa-solid fa-code';
                            $iconColor = 'text-emerald-400 bg-emerald-500/15 border border-emerald-500/20';
                        }
                    ?>
                    <div class="file-card group bg-slate-950/60 hover:bg-slate-800/80 border border-slate-800 hover:border-blue-500/50 rounded-2xl p-4 flex flex-col justify-between transition shadow-sm hover:shadow-lg" data-filename="<?= strtolower($fName) ?>">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-10 h-10 rounded-xl <?= $iconColor ?> flex items-center justify-center text-lg">
                                <i class="<?= $iconClass ?>"></i>
                            </div>
                            <span class="text-[10px] text-slate-500 font-mono"><?= $fDate ?></span>
                        </div>

                        <div>
                            <h4 class="text-xs font-semibold text-slate-200 truncate group-hover:text-white transition" title="<?= $fName ?>"><?= $fName ?></h4>
                            <div class="text-[11px] text-slate-400 mt-1"><?= $fSizeStr ?></div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-slate-800/60 flex items-center justify-between gap-2">
                            <a href="share.php?token=<?= urlencode($fToken) ?>" target="_blank" class="flex-1 py-1.5 px-2 bg-slate-800 hover:bg-blue-600 text-slate-300 hover:text-white text-[11px] font-semibold rounded-xl text-center transition flex items-center justify-center space-x-1">
                                <i class="fa-solid fa-eye text-[10px]"></i>
                                <span>Preview</span>
                            </a>
                            <a href="stream.php?token=<?= urlencode($fToken) ?>&download=1" class="py-1.5 px-3 bg-blue-600/20 hover:bg-blue-600 text-blue-300 hover:text-white text-[11px] font-semibold rounded-xl transition flex items-center justify-center" title="Download">
                                <i class="fa-solid fa-download text-[11px]"></i>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </main>

        <!-- Footer -->
        <footer class="border-t border-slate-800 bg-slate-950/80 px-6 py-4 text-center text-xs text-slate-500">
            CloudDrive Multi-Account Storage &bull; Real-Time Live Synced Folder &bull; Powered by Google Drive
        </footer>

        <script>
          const folderToken = <?= json_encode($token) ?>;
          let currentFileCount = <?= (int)$totalCount ?>;

          function copyFolderShareLink() {
            navigator.clipboard.writeText(window.location.href);
            alert('Folder link copied to clipboard! Anyone with this link can view this folder.');
          }

          function filterSharedFiles() {
            const query = (document.getElementById('folder-search-input')?.value || '').toLowerCase().trim();
            const cards = document.querySelectorAll('.file-card');
            cards.forEach(card => {
              const name = card.getAttribute('data-filename') || '';
              if (!query || name.includes(query)) {
                card.classList.remove('hidden');
              } else {
                card.classList.add('hidden');
              }
            });
          }

          // Live Polling Engine: checks for new uploads every 12 seconds
          async function pollSharedFolder(manual = false) {
            const icon = document.getElementById('refresh-icon');
            if (icon) icon.classList.add('fa-spin');

            try {
              const res = await fetch(`api/files.php?action=shared_folder_contents&token=${encodeURIComponent(folderToken)}`);
              const data = await res.json();
              if (data.success && data.files) {
                if (data.files.length > currentFileCount) {
                  const added = data.files.length - currentFileCount;
                  const banner = document.getElementById('live-upload-banner');
                  const bannerText = document.getElementById('live-upload-text');
                  if (banner && bannerText) {
                    bannerText.textContent = `✨ Owner uploaded ${added} new file${added > 1 ? 's' : ''}! Updated live.`;
                    banner.classList.remove('hidden');
                  }
                  // Refresh files list
                  window.location.reload();
                } else if (manual) {
                  alert('Folder is up-to-date!');
                }
              }
            } catch (e) {
              console.warn('Poll error:', e);
            } finally {
              if (icon) setTimeout(() => icon.classList.remove('fa-spin'), 600);
            }
          }

          // Auto-poll every 12 seconds
          setInterval(() => pollSharedFolder(false), 12000);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// 2. Otherwise Fetch File by Share Token (Single File View)
$stmt = $pdo->prepare("SELECT f.*, u.username FROM files f JOIN users u ON f.user_id = u.id WHERE f.share_token = ?");
$stmt->execute([$token]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File or folder not found, or share link has been disabled.');
}

if (!empty($file['is_visibility_blocked'])) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;"><h2>Access Denied</h2><p>This file has been quarantined by administrator for policy violation. Public access is disabled.</p></body></html>');
}

$fileName = htmlspecialchars($file['name']);
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mimeType = $file['mime_type'] ?: 'application/octet-stream';
$fileSizeMb = round($file['size_bytes'] / (1024 * 1024), 2);
if ($fileSizeMb < 0.1) {
    $fileSizeDisplay = round($file['size_bytes'] / 1024, 1) . ' KB';
} else {
    $fileSizeDisplay = $fileSizeMb . ' MB';
}

$videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];
$imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
$audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
$codeExts  = ['txt', 'json', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'py', 'php', 'md', 'csv', 'xml', 'sql', 'sh', 'yaml', 'yml', 'c', 'cpp', 'java', 'log'];

$isVideo = in_array($ext, $videoExts) || strpos($mimeType, 'video/') === 0;
$isImage = in_array($ext, $imageExts) || strpos($mimeType, 'image/') === 0;
$isAudio = in_array($ext, $audioExts) || strpos($mimeType, 'audio/') === 0;
$isPdf   = $ext === 'pdf' || $mimeType === 'application/pdf';
$isCode  = in_array($ext, $codeExts) || strpos($mimeType, 'text/') === 0;
$isHtml  = in_array($ext, ['html', 'htm']);

$streamUrl = "stream.php?token=" . urlencode($token);
$downloadUrl = "stream.php?token=" . urlencode($token) . "&download=1";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $fileName ?> - Shared File Preview</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
      .code-container {
        counter-reset: line;
      }
      .code-line {
        display: block;
        line-height: 1.6;
      }
      .code-line::before {
        counter-increment: line;
        content: counter(line);
        display: inline-block;
        width: 3.5em;
        padding-right: 1.2em;
        margin-right: 0.8em;
        color: #64748b;
        text-align: right;
        border-right: 1px solid #334155;
        user-select: none;
      }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen flex flex-col justify-between selection:bg-blue-600 selection:text-white">

    <!-- Top Navigation Bar -->
    <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur px-6 py-4 flex items-center justify-between sticky top-0 z-40">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-blue-500/20">
                <i class="fa-solid fa-cloud text-lg"></i>
            </div>
            <div>
                <span class="font-bold text-lg text-white">CloudDrive</span>
                <span class="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-blue-500/20 text-blue-300 ml-1.5 border border-blue-500/30">Shared File</span>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <a href="<?= $downloadUrl ?>" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white text-xs sm:text-sm font-semibold rounded-xl inline-flex items-center space-x-2 transition shadow-lg shadow-blue-600/30">
                <i class="fa-solid fa-download"></i>
                <span>Download (<?= $fileSizeDisplay ?>)</span>
            </a>
        </div>
    </header>

    <!-- Content Workspace -->
    <main class="flex-1 flex flex-col items-center justify-center p-4 sm:p-8">
        <div class="w-full max-w-5xl bg-slate-950/70 border border-slate-800 rounded-3xl overflow-hidden shadow-2xl backdrop-blur flex flex-col">

            <!-- File Header Info -->
            <div class="p-5 border-b border-slate-800/80 flex flex-wrap items-center justify-between gap-3 bg-slate-900/60">
                <div class="flex items-center space-x-3 truncate">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center text-xl shrink-0">
                        <?php if ($isVideo): ?>
                            <i class="fa-regular fa-file-video text-purple-400"></i>
                        <?php elseif ($isImage): ?>
                            <i class="fa-regular fa-file-image text-emerald-400"></i>
                        <?php elseif ($isAudio): ?>
                            <i class="fa-regular fa-file-audio text-amber-400"></i>
                        <?php elseif ($isPdf): ?>
                            <i class="fa-regular fa-file-pdf text-rose-400"></i>
                        <?php elseif ($isCode): ?>
                            <i class="fa-regular fa-file-code text-blue-400"></i>
                        <?php else: ?>
                            <i class="fa-regular fa-file text-slate-400"></i>
                        <?php endif; ?>
                    </div>
                    <div class="truncate">
                        <h1 class="text-base sm:text-lg font-bold text-white truncate"><?= $fileName ?></h1>
                        <div class="text-xs text-slate-400 flex items-center space-x-2">
                            <span><?= $fileSizeDisplay ?></span>
                            <span>•</span>
                            <span>Shared by <strong><?= htmlspecialchars($file['username']) ?></strong></span>
                        </div>
                    </div>
                </div>

                <!-- Code / HTML view toggles if applicable -->
                <?php if ($isHtml): ?>
                <div class="flex items-center bg-slate-800/80 p-1 rounded-xl text-xs">
                    <button onclick="switchHtmlView('preview')" id="btn-html-preview" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white font-semibold transition">
                        <i class="fa-solid fa-eye mr-1"></i> Rendered Web View
                    </button>
                    <button onclick="switchHtmlView('code')" id="btn-html-code" class="px-3 py-1.5 rounded-lg text-slate-400 hover:text-white font-semibold transition">
                        <i class="fa-solid fa-code mr-1"></i> Source Code
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- Media Preview Body -->
            <div class="bg-black/40 flex items-center justify-center min-h-[400px] max-h-[75vh] overflow-auto p-4 relative">
                <?php if ($isVideo): ?>
                    <!-- INTEGRATED VIDEO VIEWER WITH YOUTUBE-STYLE QUALITY SELECTOR -->
                    <div class="w-full max-w-4xl flex flex-col items-center">
                        <div class="relative w-full rounded-2xl overflow-hidden shadow-2xl bg-black group">
                            <video id="player-video" controls autoplay class="w-full max-h-[70vh] bg-black" preload="auto">
                                <source src="<?= $streamUrl ?>&quality=auto" type="<?= htmlspecialchars($mimeType) ?>">
                                Your browser does not support video playback.
                            </video>

                            <!-- YouTube-style Quality Selector -->
                            <div class="absolute top-4 right-4 z-20">
                                <div class="relative">
                                    <button type="button" id="quality-btn" onclick="toggleQualityMenu()" class="px-3 py-1.5 bg-black/75 hover:bg-black/90 backdrop-blur-md text-white text-xs font-semibold rounded-xl border border-white/20 transition flex items-center space-x-1.5 shadow-lg">
                                        <i class="fa-solid fa-gear text-amber-400"></i>
                                        <span id="quality-current-label">Auto</span>
                                    </button>
                                    <div id="quality-menu" class="hidden absolute right-0 mt-2 w-40 bg-slate-900/95 backdrop-blur-md border border-slate-700/80 rounded-xl shadow-2xl p-1.5 text-xs text-slate-200 z-30">
                                        <div class="text-[10px] uppercase font-bold text-slate-400 px-2 py-1 border-b border-slate-800 flex items-center justify-between">
                                            <span>Resolution</span>
                                            <i class="fa-solid fa-sliders"></i>
                                        </div>
                                        <div id="quality-options" class="mt-1 space-y-0.5 max-h-60 overflow-y-auto">
                                            <!-- Dynamically loaded -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($isImage): ?>
                    <!-- INTEGRATED IMAGE VIEWER -->
                    <div class="flex flex-col items-center justify-center space-y-4">
                        <img id="shared-img" src="<?= $streamUrl ?>" alt="<?= $fileName ?>" class="max-h-[70vh] max-w-full object-contain rounded-2xl shadow-2xl transition-transform duration-200">
                        <div class="flex items-center space-x-2 bg-slate-800/90 border border-slate-700/80 px-3 py-1.5 rounded-xl text-xs text-slate-300">
                            <button onclick="zoomImg(-0.2)" class="px-2 py-1 hover:text-white"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                            <span id="zoom-text" class="font-mono px-2">100%</span>
                            <button onclick="zoomImg(0.2)" class="px-2 py-1 hover:text-white"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                            <button onclick="rotateImg()" class="px-2 py-1 hover:text-white"><i class="fa-solid fa-rotate"></i></button>
                        </div>
                    </div>

                <?php elseif ($isAudio): ?>
                    <!-- AUDIO VIEWER -->
                    <div class="p-12 text-center w-full max-w-md mx-auto space-y-6">
                        <div class="w-24 h-24 mx-auto rounded-full bg-amber-500/10 text-amber-400 flex items-center justify-center text-4xl shadow-inner">
                            <i class="fa-solid fa-music"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-white"><?= $fileName ?></h3>
                            <p class="text-xs text-slate-400 mt-1"><?= $fileSizeDisplay ?></p>
                        </div>
                        <audio controls class="w-full">
                            <source src="<?= $streamUrl ?>" type="<?= htmlspecialchars($mimeType) ?>">
                        </audio>
                    </div>

                <?php elseif ($isPdf): ?>
                    <!-- INTEGRATED PDF VIEWER -->
                    <iframe src="<?= $streamUrl ?>" class="w-full h-[70vh] rounded-2xl border border-slate-800"></iframe>

                <?php elseif ($isHtml): ?>
                    <!-- HTML VIEWER (RENDERED OR CODE) -->
                    <div id="html-render-container" class="w-full h-[70vh] rounded-2xl overflow-hidden bg-white">
                        <iframe src="<?= $streamUrl ?>" class="w-full h-full border-0"></iframe>
                    </div>
                    <div id="html-code-container" class="w-full h-[70vh] rounded-2xl overflow-auto hidden bg-slate-950 p-4 font-mono text-xs text-slate-200">
                        <pre id="code-box" class="code-container">Loading code...</pre>
                    </div>

                <?php elseif ($isCode): ?>
                    <!-- CODE & TEXT VIEWER -->
                    <div class="w-full max-h-[70vh] rounded-2xl overflow-auto bg-slate-950 p-4 font-mono text-xs text-slate-200 border border-slate-800">
                        <div class="flex justify-end pb-2 mb-2 border-b border-slate-800">
                            <button onclick="copyCode()" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white rounded-lg text-xs transition">
                                <i class="fa-solid fa-copy mr-1"></i> Copy Code
                            </button>
                        </div>
                        <pre id="code-box" class="code-container">Loading content...</pre>
                    </div>

                <?php else: ?>
                    <!-- GENERIC FILE CARD -->
                    <div class="text-center p-16 space-y-4">
                        <div class="w-20 h-20 mx-auto rounded-3xl bg-slate-800 text-slate-400 flex items-center justify-center text-4xl">
                            <i class="fa-solid fa-file"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-white"><?= $fileName ?></h3>
                            <p class="text-xs text-slate-400 mt-1">This file type is best viewed by downloading to your device.</p>
                        </div>
                        <a href="<?= $downloadUrl ?>" class="inline-flex items-center space-x-2 px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-2xl shadow-lg shadow-blue-500/25 transition">
                            <i class="fa-solid fa-download"></i>
                            <span>Download File</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Details -->
            <div class="p-5 border-t border-slate-800 bg-slate-950 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-400 gap-3">
                <div class="flex items-center space-x-2">
                    <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                    <span>Securely verified & served from Google Drive cloud pool</span>
                </div>
                <div>
                    <a href="<?= $downloadUrl ?>" class="text-blue-400 hover:text-blue-300 font-semibold inline-flex items-center">
                        <i class="fa-solid fa-download mr-1"></i> Direct Download
                    </a>
                </div>
            </div>

        </div>
    </main>

    <footer class="py-4 text-center text-xs text-slate-600 border-t border-slate-900">
        Powered by CloudDrive Multi-Account Storage Engine
    </footer>

    <script>
      let currentZoom = 1;
      let currentRot = 0;
      function zoomImg(delta) {
        currentZoom = Math.max(0.4, Math.min(3, currentZoom + delta));
        updateImg();
      }
      function rotateImg() {
        currentRot = (currentRot + 90) % 360;
        updateImg();
      }
      function updateImg() {
        const img = document.getElementById('shared-img');
        const txt = document.getElementById('zoom-text');
        if (img) img.style.transform = `scale(${currentZoom}) rotate(${currentRot}deg)`;
        if (txt) txt.textContent = Math.round(currentZoom * 100) + '%';
      }

      // --- YOUTUBE-STYLE VIDEO RESOLUTION SWITCHING ---
      let currentQuality = 'auto';
      const shareToken = '<?= $token ?>';

      function loadVideoQualities() {
        fetch(`api/files.php?action=video_qualities&token=${encodeURIComponent(shareToken)}`)
          .then(r => r.json())
          .then(data => {
            if (data.success && data.qualities) {
              renderQualityOptions(data.qualities);
            }
          })
          .catch(() => {});
      }

      function renderQualityOptions(qualities) {
        const container = document.getElementById('quality-options');
        if (!container) return;

        container.innerHTML = qualities.map(q => `
          <button type="button" onclick="changeQuality('${q.value}', '${q.label}')" class="w-full text-left px-2.5 py-1.5 hover:bg-slate-800 rounded-lg flex items-center justify-between transition ${currentQuality === q.value ? 'text-blue-400 font-bold bg-blue-500/10' : 'text-slate-200'}">
            <span>${q.label}</span>
            ${currentQuality === q.value ? '<i class="fa-solid fa-check text-xs text-blue-400"></i>' : ''}
          </button>
        `).join('');
      }

      function toggleQualityMenu() {
        const menu = document.getElementById('quality-menu');
        if (menu) menu.classList.toggle('hidden');
      }

      function changeQuality(quality, label) {
        currentQuality = quality;
        const labelEl = document.getElementById('quality-current-label');
        if (labelEl) labelEl.textContent = label;

        toggleQualityMenu();

        const video = document.getElementById('player-video');
        if (!video) return;

        const currentTime = video.currentTime;
        const isPaused = video.paused;

        video.src = `stream.php?token=${encodeURIComponent(shareToken)}&quality=${encodeURIComponent(quality)}`;
        video.load();

        video.onloadedmetadata = () => {
          video.currentTime = currentTime;
          if (!isPaused) {
            video.play();
          }
        };

        loadVideoQualities();
      }

      document.addEventListener('click', (e) => {
        const btn = document.getElementById('quality-btn');
        const menu = document.getElementById('quality-menu');
        if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
          menu.classList.add('hidden');
        }
      });

      <?php if ($isVideo): ?>
      document.addEventListener('DOMContentLoaded', loadVideoQualities);
      <?php endif; ?>

      // Load code text if code viewer is active
      <?php if ($isCode || $isHtml): ?>
      fetch('<?= $streamUrl ?>')
        .then(r => r.text())
        .then(text => {
          const box = document.getElementById('code-box');
          if (box) {
            const lines = text.split('\n');
            box.innerHTML = lines.map(line => `<span class="code-line">${escapeHtml(line)}</span>`).join('');
          }
        })
        .catch(() => {
          const box = document.getElementById('code-box');
          if (box) box.textContent = 'Failed to load text content.';
        });

      function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      }

      function copyCode() {
        const box = document.getElementById('code-box');
        if (box) {
          navigator.clipboard.writeText(box.innerText);
          alert('Code copied to clipboard!');
        }
      }

      function switchHtmlView(view) {
        const renderC = document.getElementById('html-render-container');
        const codeC = document.getElementById('html-code-container');
        const btnP = document.getElementById('btn-html-preview');
        const btnC = document.getElementById('btn-html-code');

        if (view === 'preview') {
          renderC.classList.remove('hidden');
          codeC.classList.add('hidden');
          btnP.className = 'px-3 py-1.5 rounded-lg bg-blue-600 text-white font-semibold transition';
          btnC.className = 'px-3 py-1.5 rounded-lg text-slate-400 hover:text-white font-semibold transition';
        } else {
          renderC.classList.add('hidden');
          codeC.classList.remove('hidden');
          btnC.className = 'px-3 py-1.5 rounded-lg bg-blue-600 text-white font-semibold transition';
          btnP.className = 'px-3 py-1.5 rounded-lg text-slate-400 hover:text-white font-semibold transition';
        }
      }
      <?php endif; ?>
    </script>
</body>
</html>
