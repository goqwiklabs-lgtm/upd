<?php
require_once __DIR__ . '/config/db.php';

$pdo = getDBConnection();
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    die('Invalid or missing share link token.');
}

// Fetch file by share token (Accessible to ANYONE with the link - no login required!)
$stmt = $pdo->prepare("SELECT f.*, u.username FROM files f JOIN users u ON f.user_id = u.id WHERE f.share_token = ?");
$stmt->execute([$token]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('File not found or link has expired.');
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
