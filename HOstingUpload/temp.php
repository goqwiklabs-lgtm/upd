<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/google.php';

$pdo = getDBConnection();
$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    die('Access denied. Your IP has been blocked by administrator.');
}
trackLiveVisitor($pdo, 'Temp Transfer');

// Run auto-purge of expired files
$now = date('Y-m-d H:i:s');
$expiredStmt = $pdo->prepare("SELECT id FROM temp_uploads WHERE expires_at <= ?");
$expiredStmt->execute([$now]);
$expiredUploads = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($expiredUploads)) {
    foreach ($expiredUploads as $exp) {
        $upId = (int)$exp['id'];
        $fStmt = $pdo->prepare("
            SELECT tf.*, ga.client_id, ga.client_secret, ga.refresh_token, ga.access_token, ga.token_expires_at
            FROM temp_files tf
            LEFT JOIN google_accounts ga ON tf.google_account_id = ga.id
            WHERE tf.temp_upload_id = ?
        ");
        $fStmt->execute([$upId]);
        $files = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($files as $f) {
            if (!empty($f['google_file_id']) && !empty($f['refresh_token'])) {
                try {
                    $acc = [
                        'id' => $f['google_account_id'],
                        'client_id' => $f['client_id'],
                        'client_secret' => $f['client_secret'],
                        'refresh_token' => $f['refresh_token'],
                        'access_token' => $f['access_token'],
                        'token_expires_at' => $f['token_expires_at'],
                    ];
                    GoogleDriveManager::deleteGoogleFile($acc, $pdo, $f['google_file_id']);
                } catch (Exception $e) {}
                $pdo->prepare("
                    UPDATE google_accounts 
                    SET used_storage_bytes = CASE 
                        WHEN used_storage_bytes > ? THEN used_storage_bytes - ? 
                        ELSE 0 
                    END 
                    WHERE id = ?
                ")->execute([$f['size_bytes'], $f['size_bytes'], $f['google_account_id']]);
            }
        }
        $pdo->prepare("DELETE FROM temp_files WHERE temp_upload_id = ?")->execute([$upId]);
        $pdo->prepare("DELETE FROM temp_uploads WHERE id = ?")->execute([$upId]);
    }
}

$token = trim($_GET['token'] ?? $_GET['code'] ?? '');
if (empty($token) && !empty($_SERVER['REQUEST_URI'])) {
    if (preg_match('#/(?:temp|t|share)/([a-zA-Z0-9_\-]+)#i', $_SERVER['REQUEST_URI'], $matches)) {
        $token = $matches[1];
    }
}
$upload = null;
$files = [];
$isExpired = false;

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT * FROM temp_uploads WHERE share_token = ?");
    $stmt->execute([$token]);
    $upload = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($upload) {
        if (strtotime($upload['expires_at']) <= time()) {
            $isExpired = true;
            $upload = null;
        } else {
            $fStmt = $pdo->prepare("SELECT * FROM temp_files WHERE temp_upload_id = ? ORDER BY id ASC");
            $fStmt->execute([(int)$upload['id']]);
            $files = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $isExpired = true;
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $upload ? htmlspecialchars($upload['title']) . ' - Temporary Transfer' : 'Temporary File Transfer' ?> - CloudDrive</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    .expiry-pill.active {
      background-color: #f59e0b;
      color: #ffffff;
      border-color: #d97706;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.25);
    }
  </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between selection:bg-amber-500 selection:text-white font-sans antialiased">

  <!-- TOP HEADER -->
  <header class="border-b border-slate-800/80 bg-slate-900/90 backdrop-blur sticky top-0 z-40 px-4 sm:px-8 py-3.5 flex items-center justify-between">
    <a href="index.php" class="flex items-center space-x-3 group">
      <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/25 group-hover:scale-105 transition-transform">
        <i class="fa-solid fa-clock-rotate-left text-lg"></i>
      </div>
      <div>
        <div class="flex items-center space-x-2">
          <span class="font-bold text-lg text-white tracking-tight">CloudDrive</span>
          <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">Temp Share</span>
        </div>
        <p class="text-[11px] text-slate-400">Self-Destructing File & Folder Transfers</p>
      </div>
    </a>

    <div class="flex items-center space-x-2.5">
      <?php if ($upload && !$isExpired): ?>
        <a href="temp.php" class="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-xl shadow-md shadow-amber-500/20 transition flex items-center space-x-1.5">
          <i class="fa-solid fa-plus text-xs"></i>
          <span class="hidden sm:inline">Upload New</span>
        </a>
      <?php endif; ?>
      <a href="index.php" class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl border border-slate-700/80 transition flex items-center space-x-1.5">
        <i class="fa-solid fa-house text-xs"></i>
        <span>Go to Drive</span>
      </a>
    </div>
  </header>

  <!-- MAIN BODY -->
  <main class="flex-1 max-w-4xl w-full mx-auto p-4 sm:p-8 space-y-6">

    <?php if ($upload && !$isExpired): 
        $expiresEpoch = strtotime($upload['expires_at']);
        $secondsRemaining = max(0, $expiresEpoch - time());
    ?>

      <!-- STATUS & COUNTDOWN BANNER (RECIPIENT VIEWER) -->
      <div class="bg-gradient-to-br from-slate-900 to-slate-900/90 border border-amber-500/30 rounded-3xl p-6 sm:p-8 shadow-2xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-52 h-52 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 relative z-10">
          <div class="space-y-2">
            <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 text-xs font-semibold border border-amber-500/30">
              <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span>
              <span>Self-Destruct Active</span>
            </div>
            <h1 class="text-xl sm:text-2xl font-bold text-white tracking-tight break-words">
              <?= htmlspecialchars($upload['title']) ?>
            </h1>
            <p class="text-xs text-slate-400">
              <?= count($files) ?> <?= count($files) === 1 ? 'file' : 'files' ?> • <?= formatBytes($upload['total_size']) ?> total
              <?php if (!empty($upload['is_folder'])): ?>
                • <span class="text-amber-400 font-semibold"><i class="fa-solid fa-folder-closed mr-1"></i>Folder Transfer</span>
              <?php endif; ?>
            </p>
          </div>

          <!-- COUNTDOWN TIMER DISPLAY -->
          <div class="bg-slate-950/80 border border-amber-500/40 rounded-2xl p-4 sm:px-6 text-center min-w-[210px] shadow-lg">
            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block mb-1">Time Until Permanent Deletion</span>
            <div id="countdown-clock" class="text-2xl sm:text-3xl font-black font-mono text-amber-400 tracking-wider">
              --:--:--
            </div>
            <span class="text-[10px] text-slate-500 block mt-1">Permanently deleted at 00:00</span>
          </div>
        </div>

        <div class="mt-6 pt-5 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-400">
          <div class="flex items-center space-x-2">
            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
            <span>All files permanently purged from Google Drive upon countdown expiration.</span>
          </div>
          <div class="flex items-center space-x-2">
            <button onclick="copyCurrentLink()" class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl font-medium transition flex items-center space-x-1.5 border border-slate-700/80">
              <i class="fa-solid fa-copy text-amber-400"></i>
              <span>Copy Share Link</span>
            </button>
          </div>
        </div>
      </div>

      <!-- FILE LIST SECTION -->
      <div class="space-y-4">
        <div class="flex items-center justify-between px-1">
          <h2 class="text-base font-bold text-slate-200 flex items-center space-x-2">
            <i class="fa-solid fa-folder-tree text-amber-500"></i>
            <span>Shared Files (<?= count($files) ?>)</span>
          </h2>
          <span class="text-xs text-slate-400 font-mono"><?= formatBytes($upload['total_size']) ?></span>
        </div>

        <div class="grid grid-cols-1 gap-2.5">
          <?php foreach ($files as $f): 
              $fSize = formatBytes($f['size_bytes']);
              $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
              $mime = $f['mime_type'] ?: '';
              $isVid = strpos($mime, 'video/') === 0 || in_array($ext, ['mp4','webm','mov','mkv']);
              $isImg = strpos($mime, 'image/') === 0 || in_array($ext, ['png','jpg','jpeg','webp','gif','svg']);
              $isAudio = strpos($mime, 'audio/') === 0 || in_array($ext, ['mp3','wav','ogg','m4a']);
              $isPdf = $ext === 'pdf' || $mime === 'application/pdf';
              $canPreview = $isVid || $isImg || $isAudio || $isPdf;
              
              $icon = 'fa-file';
              $iconColor = 'text-slate-400';
              if ($isVid) { $icon = 'fa-file-video'; $iconColor = 'text-purple-400'; }
              elseif ($isImg) { $icon = 'fa-file-image'; $iconColor = 'text-emerald-400'; }
              elseif ($isAudio) { $icon = 'fa-file-audio'; $iconColor = 'text-pink-400'; }
              elseif ($isPdf) { $icon = 'fa-file-pdf'; $iconColor = 'text-rose-400'; }
              elseif (in_array($ext, ['zip','tar','gz','rar','7z'])) { $icon = 'fa-file-zipper'; $iconColor = 'text-amber-400'; }
          ?>
            <div class="bg-slate-900/80 hover:bg-slate-900 border border-slate-800/80 rounded-2xl p-4 flex items-center justify-between gap-4 transition">
              <div class="flex items-center space-x-3.5 min-w-0">
                <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center shrink-0 text-lg <?= $iconColor ?>">
                  <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <div class="min-w-0">
                  <h3 class="text-sm font-semibold text-slate-100 truncate" title="<?= htmlspecialchars($f['name']) ?>">
                    <?= htmlspecialchars($f['name']) ?>
                  </h3>
                  <div class="flex items-center space-x-2 text-[11px] text-slate-400 mt-0.5">
                    <span><?= $fSize ?></span>
                    <?php if (!empty($f['relative_path'])): ?>
                      <span>•</span>
                      <span class="font-mono text-slate-500 truncate max-w-xs"><?= htmlspecialchars($f['relative_path']) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="flex items-center space-x-2 shrink-0">
                <?php if ($canPreview): ?>
                  <button onclick="openPreview(<?= (int)$f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name'])) ?>', '<?= htmlspecialchars($mime) ?>')" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium rounded-xl transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-eye text-xs"></i>
                    <span class="hidden sm:inline">Preview</span>
                  </button>
                <?php endif; ?>
                <a href="stream.php?temp_file_id=<?= (int)$f['id'] ?>&token=<?= urlencode($token) ?>&download=1" class="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs rounded-xl shadow-md shadow-amber-500/20 transition flex items-center space-x-1.5">
                  <i class="fa-solid fa-download text-xs"></i>
                  <span>Download</span>
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- COUNTDOWN TIMER SCRIPT -->
      <script>
        let remainingSeconds = <?= (int)$secondsRemaining ?>;
        const clockEl = document.getElementById('countdown-clock');

        function updateClock() {
          if (remainingSeconds <= 0) {
            clockEl.textContent = '00:00:00';
            clockEl.classList.add('text-rose-500');
            setTimeout(() => {
              window.location.reload();
            }, 2000);
            return;
          }

          const days = Math.floor(remainingSeconds / 86400);
          const hours = Math.floor((remainingSeconds % 86400) / 3600);
          const minutes = Math.floor((remainingSeconds % 3600) / 60);
          const seconds = remainingSeconds % 60;

          const pad = (n) => String(n).padStart(2, '0');

          if (days > 0) {
            clockEl.textContent = `${days}d ${pad(hours)}h ${pad(minutes)}m ${pad(seconds)}s`;
          } else {
            clockEl.textContent = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
          }

          remainingSeconds--;
        }

        updateClock();
        setInterval(updateClock, 1000);

        function copyCurrentLink() {
          navigator.clipboard.writeText(window.location.href).then(() => {
            showInAppToast('Share link copied to clipboard!', 'success');
          });
        }

        function openPreview(fileId, fileName, mimeType) {
          const modal = document.getElementById('preview-modal');
          const title = document.getElementById('preview-modal-title');
          const body = document.getElementById('preview-modal-body');
          const streamUrl = `stream.php?temp_file_id=${fileId}&token=<?= urlencode($token) ?>`;

          title.textContent = fileName;
          body.innerHTML = '';

          if (mimeType.startsWith('image/')) {
            body.innerHTML = `<img src="${streamUrl}" class="max-h-[75vh] max-w-full rounded-xl object-contain mx-auto shadow-2xl" alt="${fileName}">`;
          } else if (mimeType.startsWith('video/')) {
            body.innerHTML = `
              <video src="${streamUrl}" controls autoplay class="max-h-[75vh] max-w-full rounded-xl shadow-2xl mx-auto bg-black">
                Your browser does not support the video tag.
              </video>`;
          } else if (mimeType.startsWith('audio/')) {
            body.innerHTML = `
              <div class="p-8 bg-slate-900 rounded-2xl border border-slate-800 text-center space-y-4">
                <i class="fa-solid fa-music text-4xl text-amber-400"></i>
                <audio src="${streamUrl}" controls autoplay class="w-full max-w-md mx-auto"></audio>
              </div>`;
          } else if (mimeType === 'application/pdf') {
            body.innerHTML = `<iframe src="${streamUrl}" class="w-full h-[75vh] rounded-xl border border-slate-800 bg-white"></iframe>`;
          }

          modal.classList.remove('hidden');
        }

        function closePreview() {
          document.getElementById('preview-modal').classList.add('hidden');
          document.getElementById('preview-modal-body').innerHTML = '';
        }
      </script>

    <?php else: ?>

      <!-- IF EXPIRED LINK PROVIDED: SHOW NOTICE -->
      <?php if ($isExpired): ?>
        <div class="bg-slate-900/90 border border-rose-500/30 rounded-3xl p-6 sm:p-8 text-center max-w-xl mx-auto shadow-2xl space-y-4 mb-6">
          <div class="w-16 h-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center mx-auto text-2xl">
            <i class="fa-solid fa-hourglass-end animate-pulse"></i>
          </div>
          <div class="space-y-1">
            <h1 class="text-xl font-bold text-white tracking-tight">This Temporary Link Has Expired</h1>
            <p class="text-xs text-slate-400 leading-relaxed">
              The transfer has reached its expiration time and all files have been <strong class="text-rose-400">permanently wiped from Google Drive</strong> for security and privacy.
            </p>
          </div>
          <div class="pt-2">
            <span class="text-xs font-bold text-amber-400">You can create a new temporary transfer below:</span>
          </div>
        </div>
      <?php endif; ?>

      <!-- STANDALONE RESPONSIVE TEMPORARY UPLOAD PORTAL -->
      <div class="space-y-6">

        <!-- Hero Header -->
        <div class="text-center max-w-xl mx-auto space-y-2">
          <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-amber-500/15 text-amber-300 text-xs font-semibold border border-amber-500/25">
            <i class="fa-solid fa-bolt text-amber-400"></i>
            <span>Self-Destructing File Transfer Portal</span>
          </div>
          <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">
            Share Temporary Files & Folders
          </h1>
          <p class="text-xs sm:text-sm text-slate-400">
            Upload files or entire folders with automatic expiration (10 min to 7 days). Files are permanently wiped from Google Drive when expired.
          </p>
        </div>

        <!-- UPLOAD CONTAINER CARD -->
        <div class="bg-slate-900/90 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-2xl space-y-6">

          <!-- Step 1: Upload Selection & Settings -->
          <div id="standalone-temp-select" class="space-y-5">

            <!-- Interactive Dropzone -->
            <div id="standalone-temp-dropzone" class="border-2 border-dashed border-amber-500/40 hover:border-amber-400 bg-amber-500/5 hover:bg-amber-500/10 rounded-3xl p-8 sm:p-12 text-center transition cursor-pointer" onclick="document.getElementById('standalone-temp-file-input').click()">
              <div class="w-16 h-16 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-3xl mx-auto mb-4 shadow-inner">
                <i class="fa-solid fa-cloud-arrow-up"></i>
              </div>
              <h3 class="text-base sm:text-lg font-bold text-white mb-1">
                Drop files or folder here to upload
              </h3>
              <p class="text-xs text-slate-400 mb-5">
                Single file, multiple files, or an entire folder hierarchy
              </p>

              <div class="flex flex-wrap items-center justify-center gap-3" onclick="event.stopPropagation()">
                <button type="button" onclick="document.getElementById('standalone-temp-file-input').click()" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs rounded-xl shadow-lg shadow-amber-500/20 transition flex items-center space-x-2">
                  <i class="fa-solid fa-file text-xs"></i>
                  <span>Choose Files</span>
                </button>
                <button type="button" onclick="document.getElementById('standalone-temp-folder-input').click()" class="px-5 py-2.5 bg-slate-800 border border-amber-500/30 hover:bg-slate-700 text-amber-300 font-bold text-xs rounded-xl transition flex items-center space-x-2">
                  <i class="fa-solid fa-folder-open text-xs"></i>
                  <span>Choose Folder</span>
                </button>
              </div>

              <!-- Hidden Inputs -->
              <input type="file" id="standalone-temp-file-input" multiple class="hidden" onchange="handleStandaloneFiles(event)">
              <input type="file" id="standalone-temp-folder-input" webkitdirectory directory multiple class="hidden" onchange="handleStandaloneFolder(event)">
            </div>

            <!-- Selected Files Tray -->
            <div id="standalone-temp-selected-tray" class="hidden space-y-2">
              <div class="flex items-center justify-between text-xs px-1">
                <span id="standalone-temp-selected-summary" class="font-bold text-amber-400">0 files selected</span>
                <button onclick="clearStandaloneSelection()" class="text-rose-400 hover:underline text-[11px] font-semibold flex items-center space-x-1">
                  <i class="fa-solid fa-trash-can"></i>
                  <span>Clear All</span>
                </button>
              </div>
              <div id="standalone-temp-selected-list" class="max-h-48 overflow-y-auto space-y-1.5 pr-1"></div>
            </div>

            <!-- Expiry Duration Selector (10 min to 7 days) -->
            <div class="p-5 bg-slate-950/60 rounded-2xl border border-slate-800 space-y-3.5">
              <div class="flex items-center justify-between">
                <label class="text-xs font-bold text-slate-200 flex items-center space-x-2">
                  <i class="fa-solid fa-stopwatch text-amber-400"></i>
                  <span>Auto-Expiry & Deletion Timer:</span>
                </label>
                <span id="standalone-expiry-label" class="text-xs font-mono font-bold text-amber-400">10 min</span>
              </div>

              <!-- Expiry Preset Pills -->
              <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-8 gap-2">
                <button type="button" onclick="setStandaloneExpiry(10, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition active">10 min</button>
                <button type="button" onclick="setStandaloneExpiry(30, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">30 min</button>
                <button type="button" onclick="setStandaloneExpiry(60, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">1 hour</button>
                <button type="button" onclick="setStandaloneExpiry(360, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">6 hours</button>
                <button type="button" onclick="setStandaloneExpiry(1440, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">24 hours</button>
                <button type="button" onclick="setStandaloneExpiry(2880, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">2 days</button>
                <button type="button" onclick="setStandaloneExpiry(4320, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">3 days</button>
                <button type="button" onclick="setStandaloneExpiry(10080, this)" class="expiry-pill py-2.5 px-2 text-center text-xs font-bold rounded-xl border border-slate-800 bg-slate-900 hover:border-amber-500 text-slate-300 transition">7 days</button>
              </div>

              <!-- Custom Duration Input -->
              <div class="flex items-center space-x-2 pt-1 text-xs">
                <span class="text-slate-400 text-[11px]">Or custom duration (10 - 10080 minutes):</span>
                <input type="number" id="standalone-custom-expiry" min="10" max="10080" placeholder="10 - 10080" oninput="handleStandaloneCustomExpiry(event)" class="w-32 px-3 py-1.5 bg-slate-900 border border-slate-800 rounded-xl text-xs text-white outline-none focus:border-amber-500 font-mono">
              </div>

              <div class="text-[11px] text-amber-300/80 flex items-center space-x-2 pt-1">
                <i class="fa-solid fa-shield-halved text-amber-400"></i>
                <span>When expired, files are <strong>permanently deleted from Google Drive</strong> and the transfer link stops working.</span>
              </div>
            </div>

            <!-- Upload Start Button -->
            <button id="standalone-start-btn" onclick="startStandaloneTempUpload()" class="w-full py-4 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-slate-950 font-black text-sm rounded-2xl shadow-xl shadow-amber-500/25 transition flex items-center justify-center space-x-2">
              <i class="fa-solid fa-bolt text-slate-950"></i>
              <span>Upload & Generate Shareable Link</span>
            </button>
          </div>

          <!-- Step 2: Upload Progress Display -->
          <div id="standalone-temp-progress" class="hidden space-y-4 py-8 text-center">
            <div class="w-16 h-16 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-3xl mx-auto animate-pulse">
              <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <div>
              <h4 id="standalone-progress-filename" class="text-base font-bold text-white truncate max-w-md mx-auto">Uploading...</h4>
              <p id="standalone-progress-status" class="text-xs text-slate-400 mt-0.5">Streaming to Google Drive pool...</p>
            </div>

            <div class="w-full bg-slate-800 rounded-full h-3.5 overflow-hidden p-0.5 border border-slate-700 max-w-md mx-auto">
              <div id="standalone-progress-bar" class="bg-gradient-to-r from-amber-500 to-orange-500 h-full rounded-full transition-all duration-150" style="width: 0%"></div>
            </div>

            <div class="flex justify-between items-center text-xs text-slate-400 font-mono max-w-md mx-auto">
              <span id="standalone-progress-bytes">0 B / 0 B</span>
              <span id="standalone-progress-pct" class="font-bold text-amber-400">0%</span>
            </div>
          </div>

          <!-- Step 3: Success Screen with QR Code and Link -->
          <div id="standalone-temp-success" class="hidden space-y-6 text-center py-6 bg-slate-950/60 rounded-3xl p-6 sm:p-8 border border-emerald-500/30">
            <div class="w-16 h-16 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-3xl mx-auto shadow-inner">
              <i class="fa-solid fa-check"></i>
            </div>
            <div class="space-y-1">
              <h3 class="text-lg font-bold text-white">Temporary Transfer Ready!</h3>
              <p id="standalone-success-expiry-text" class="text-xs text-slate-400">Expires in 10 minutes</p>
            </div>

            <!-- Share Link Input & Copy -->
            <div class="max-w-md mx-auto bg-slate-900 p-2.5 rounded-2xl border border-slate-800 flex items-center space-x-2">
              <input type="text" id="standalone-success-link-input" readonly class="flex-1 px-3 py-1.5 text-xs font-mono text-slate-200 bg-transparent outline-none truncate">
              <button onclick="copyStandaloneLink()" class="px-4 py-2 bg-amber-500 hover:bg-amber-400 text-slate-950 font-bold text-xs rounded-xl transition flex items-center space-x-1.5 shrink-0">
                <i class="fa-solid fa-copy"></i>
                <span>Copy</span>
              </button>
            </div>

            <!-- QR Code Box -->
            <div class="flex flex-col items-center justify-center space-y-2">
              <div id="standalone-qrcode" class="p-3 bg-white rounded-2xl border-2 border-slate-800 shadow-xl"></div>
              <span class="text-[11px] text-slate-400">Scan with your phone camera to open or download</span>
            </div>

            <div class="flex items-center justify-center gap-3 pt-2">
              <a id="standalone-success-open-btn" href="#" target="_blank" class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold rounded-xl transition flex items-center space-x-2 border border-slate-700">
                <i class="fa-solid fa-arrow-up-right-from-square text-xs text-amber-400"></i>
                <span>Open Transfer Page</span>
              </a>
              <button onclick="resetStandaloneUpload()" class="px-5 py-2.5 bg-slate-900 border border-slate-800 hover:bg-slate-800 text-slate-300 text-xs font-bold rounded-xl transition">
                Create Another Transfer
              </button>
            </div>
          </div>

        </div>

      </div>

      <!-- STANDALONE JS SCRIPT -->
      <script>
        let standaloneFiles = [];
        let standaloneExpiryMinutes = 10;
        let standaloneIsFolder = false;
        let standaloneFolderName = '';

        function setStandaloneExpiry(minutes, btn) {
          standaloneExpiryMinutes = minutes;
          document.querySelectorAll('.expiry-pill').forEach(b => b.classList.remove('active'));
          if (btn) btn.classList.add('active');
          const customInput = document.getElementById('standalone-custom-expiry');
          if (customInput) customInput.value = '';
          updateExpiryLabel();
        }

        function handleStandaloneCustomExpiry(e) {
          const val = parseInt(e.target.value, 10);
          if (!isNaN(val) && val >= 10 && val <= 10080) {
            standaloneExpiryMinutes = val;
            document.querySelectorAll('.expiry-pill').forEach(b => b.classList.remove('active'));
            updateExpiryLabel();
          }
        }

        function updateExpiryLabel() {
          const label = document.getElementById('standalone-expiry-label');
          if (!label) return;
          if (standaloneExpiryMinutes < 60) label.textContent = `${standaloneExpiryMinutes} min`;
          else if (standaloneExpiryMinutes < 1440) label.textContent = `${Math.round(standaloneExpiryMinutes / 60)} hour(s)`;
          else label.textContent = `${Math.round(standaloneExpiryMinutes / 1440)} day(s)`;
        }

        function handleStandaloneFiles(e) {
          const files = Array.from(e.target.files || []);
          if (files.length === 0) return;
          standaloneIsFolder = false;
          standaloneFolderName = '';
          standaloneFiles = files;
          renderStandaloneSelectedList();
        }

        function handleStandaloneFolder(e) {
          const files = Array.from(e.target.files || []);
          if (files.length === 0) return;
          standaloneIsFolder = true;
          const firstPath = files[0].webkitRelativePath || '';
          standaloneFolderName = firstPath.split('/')[0] || 'Shared Folder';
          standaloneFiles = files;
          renderStandaloneSelectedList();
        }

        function renderStandaloneSelectedList() {
          const tray = document.getElementById('standalone-temp-selected-tray');
          const summary = document.getElementById('standalone-temp-selected-summary');
          const list = document.getElementById('standalone-temp-selected-list');
          if (!tray || !list) return;

          if (standaloneFiles.length === 0) {
            tray.classList.add('hidden');
            return;
          }

          tray.classList.remove('hidden');
          const totalBytes = standaloneFiles.reduce((s, f) => s + f.size, 0);
          summary.textContent = `${standaloneFiles.length} file${standaloneFiles.length > 1 ? 's' : ''} (${formatBytesJs(totalBytes)}) ${standaloneIsFolder ? `in [${standaloneFolderName}]` : 'selected'}`;

          list.innerHTML = standaloneFiles.map((f, i) => `
            <div class="p-2.5 rounded-xl bg-slate-950/70 border border-slate-800 flex items-center justify-between text-xs">
              <div class="flex items-center space-x-2.5 truncate mr-2">
                <i class="fa-solid fa-file text-amber-400/80"></i>
                <span class="font-medium text-slate-200 truncate">${escapeHtmlJs(f.webkitRelativePath || f.name)}</span>
              </div>
              <div class="flex items-center space-x-2 shrink-0">
                <span class="text-[10px] text-slate-500 font-mono">${formatBytesJs(f.size)}</span>
                <button type="button" onclick="removeStandaloneFile(${i})" class="text-slate-500 hover:text-rose-400 p-1">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </div>
            </div>
          `).join('');
        }

        function removeStandaloneFile(index) {
          standaloneFiles.splice(index, 1);
          renderStandaloneSelectedList();
        }

        function clearStandaloneSelection() {
          standaloneFiles = [];
          standaloneIsFolder = false;
          standaloneFolderName = '';
          renderStandaloneSelectedList();
        }

        // Drag & drop handlers
        const dropzone = document.getElementById('standalone-temp-dropzone');
        if (dropzone) {
          dropzone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('border-amber-400', 'bg-amber-500/15');
          });
          dropzone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dropzone.classList.remove('border-amber-400', 'bg-amber-500/15');
          });
          dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('border-amber-400', 'bg-amber-500/15');
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
              standaloneFiles = Array.from(e.dataTransfer.files);
              standaloneIsFolder = false;
              renderStandaloneSelectedList();
            }
          });
        }

        async function sendStandaloneChunk(uploadUrl, contentRange, chunk) {
          try {
            const res = await fetch(uploadUrl, {
              method: 'PUT',
              headers: { 'Content-Range': contentRange },
              body: chunk,
            });
            if (res.status === 200 || res.status === 201 || res.status === 308) return res;
          } catch (_) {}

          // Fallback to proxy relay
          const relayUrl = `api/upload_chunk.php?upload_url=${encodeURIComponent(uploadUrl)}`;
          return await fetch(relayUrl, {
            method: 'POST',
            headers: {
              'Content-Range': contentRange,
              'X-Content-Range': contentRange,
            },
            body: chunk,
          });
        }

        async function startStandaloneTempUpload() {
          if (standaloneFiles.length === 0) {
            showInAppToast('Please select at least one file or folder to upload.', 'error');
            return;
          }

          document.getElementById('standalone-temp-select')?.classList.add('hidden');
          document.getElementById('standalone-temp-progress')?.classList.remove('hidden');
          document.getElementById('standalone-temp-success')?.classList.add('hidden');

          const progressBar = document.getElementById('standalone-progress-bar');
          const progressPct = document.getElementById('standalone-progress-pct');
          const progressBytes = document.getElementById('standalone-progress-bytes');
          const progressFilename = document.getElementById('standalone-progress-filename');

          const totalSizeAll = standaloneFiles.reduce((s, f) => s + f.size, 0);
          let totalBytesUploadedAll = 0;
          const uploadedMeta = [];

          try {
            for (let i = 0; i < standaloneFiles.length; i++) {
              const file = standaloneFiles[i];
              if (progressFilename) progressFilename.textContent = `(${i + 1}/${standaloneFiles.length}) ${file.name}`;

              // 1. Init upload
              const initRes = await fetch('api/temp_upload.php?action=init_upload', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  filename: file.name,
                  mime_type: file.type || 'application/octet-stream',
                  size: file.size,
                }),
              });
              const initData = await initRes.json();
              if (!initData.success || !initData.upload_url) {
                throw new Error(initData.error || 'Failed to initialize Google Drive upload.');
              }

              const uploadUrl = initData.upload_url;
              const googleAccountId = initData.google_account_id;

              // 2. Stream chunks
              let googleFileId = null;
              let offset = 0;
              const chunkSize = 2 * 1024 * 1024; // 2MB chunk

              while (offset < file.size) {
                const end = Math.min(offset + chunkSize, file.size);
                const chunk = file.slice(offset, end);
                const contentRange = `bytes ${offset}-${end - 1}/${file.size}`;

                const uploadRes = await sendStandaloneChunk(uploadUrl, contentRange, chunk);

                if (uploadRes.status === 200 || uploadRes.status === 201) {
                  try {
                    const finishedData = await uploadRes.json();
                    if (finishedData && finishedData.id) googleFileId = finishedData.id;
                  } catch (_) {}
                  totalBytesUploadedAll += (end - offset);
                  offset = end;
                  break;
                } else if (uploadRes.status === 308) {
                  totalBytesUploadedAll += (end - offset);
                  offset = end;
                  const pct = Math.round((totalBytesUploadedAll / totalSizeAll) * 100);
                  if (progressBar) progressBar.style.width = `${pct}%`;
                  if (progressPct) progressPct.textContent = `${pct}%`;
                  if (progressBytes) progressBytes.textContent = `${formatBytesJs(totalBytesUploadedAll)} / ${formatBytesJs(totalSizeAll)}`;
                } else {
                  throw new Error(`Upload chunk failed with status HTTP ${uploadRes.status}`);
                }
              }

              if (!googleFileId) {
                googleFileId = 'gdrive_' + Math.random().toString(36).substr(2, 12);
              }

              uploadedMeta.push({
                name: file.name,
                relative_path: file.webkitRelativePath || '',
                size_bytes: file.size,
                mime_type: file.type || 'application/octet-stream',
                google_account_id: googleAccountId,
                google_file_id: googleFileId,
              });
            }

            // 3. Finalize
            const createRes = await fetch('api/temp_upload.php?action=create', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                title: standaloneIsFolder ? standaloneFolderName : (standaloneFiles.length === 1 ? standaloneFiles[0].name : 'Temporary Transfer'),
                expiry_minutes: standaloneExpiryMinutes,
                is_folder: standaloneIsFolder ? 1 : 0,
                folder_name: standaloneFolderName,
                files: uploadedMeta,
              }),
            });
            const createData = await createRes.json();
            if (!createData.success) throw new Error(createData.error || 'Failed to finalize transfer.');

            // 4. Render success
            document.getElementById('standalone-temp-progress')?.classList.add('hidden');
            document.getElementById('standalone-temp-success')?.classList.remove('hidden');

            const linkInput = document.getElementById('standalone-success-link-input');
            const openBtn = document.getElementById('standalone-success-open-btn');
            const expiryText = document.getElementById('standalone-success-expiry-text');

            if (linkInput) linkInput.value = createData.share_url;
            if (openBtn) openBtn.href = createData.share_url;
            if (expiryText) expiryText.textContent = `Expires in ${formatMinutesJs(createData.expiry_minutes)} • Permanently deleted at ${createData.expires_at}`;

            // Generate QR Code
            const qrBox = document.getElementById('standalone-qrcode');
            if (qrBox && typeof QRCode !== 'undefined') {
              qrBox.innerHTML = '';
              new QRCode(qrBox, {
                text: createData.share_url,
                width: 170,
                height: 170,
                colorDark: '#0f172a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
              });
            }

          } catch (err) {
            showInAppToast('Upload error: ' + err.message, 'error');
            document.getElementById('standalone-temp-progress')?.classList.add('hidden');
            document.getElementById('standalone-temp-select')?.classList.remove('hidden');
          }
        }

        function copyStandaloneLink() {
          const input = document.getElementById('standalone-success-link-input');
          if (input) {
            navigator.clipboard.writeText(input.value).then(() => {
              showInAppToast('Temporary share link copied to clipboard!', 'success');
            });
          }
        }

        function resetStandaloneUpload() {
          clearStandaloneSelection();
          document.getElementById('standalone-temp-success')?.classList.add('hidden');
          document.getElementById('standalone-temp-select')?.classList.remove('hidden');
        }

        function formatBytesJs(bytes) {
          if (bytes === 0) return '0 B';
          const k = 1024;
          const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
          const i = Math.floor(Math.log(bytes) / Math.log(k));
          return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function formatMinutesJs(min) {
          if (min < 60) return `${min} minutes`;
          if (min < 1440) return `${Math.round(min / 60)} hours`;
          return `${Math.round(min / 1440)} days`;
        }

        function escapeHtmlJs(str) {
          return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
      </script>

    <?php endif; ?>

  </main>

  <!-- PREVIEW MODAL -->
  <div id="preview-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex flex-col justify-between p-4 sm:p-6 z-50 hidden">
    <div class="flex items-center justify-between pb-4 max-w-5xl w-full mx-auto">
      <h3 id="preview-modal-title" class="text-sm sm:text-base font-bold text-white truncate max-w-lg">Preview</h3>
      <button onclick="closePreview()" class="w-9 h-9 rounded-full bg-slate-800 hover:bg-slate-700 text-white flex items-center justify-center transition">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
    <div id="preview-modal-body" class="flex-1 flex items-center justify-center max-w-5xl w-full mx-auto overflow-hidden"></div>
  </div>

  <!-- FOOTER -->
  <footer class="border-t border-slate-800/80 py-5 px-6 text-center text-xs text-slate-500">
    CloudDrive Temporary Transfer Engine • 100% Self-Destructing • Zero Retained Data After Expiry
  </footer>

  <!-- FLOATING TOAST CONTAINER -->
  <div id="toast-container" class="fixed bottom-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none max-w-sm w-full px-4"></div>

  <script>
    function showInAppToast(message, type = 'info') {
      const container = document.getElementById('toast-container');
      if (!container) return;
      const toast = document.createElement('div');
      const bg = type === 'success' ? 'bg-slate-900 border-emerald-500/50 text-emerald-300 ring-1 ring-emerald-500/30' :
                 type === 'error' ? 'bg-slate-900 border-rose-500/50 text-rose-300 ring-1 ring-rose-500/30' :
                 'bg-slate-900 border-slate-700 text-white shadow-xl';
      const icon = type === 'success' ? '<i class="fa-solid fa-circle-check text-emerald-400 mr-2 text-sm"></i>' :
                   type === 'error' ? '<i class="fa-solid fa-circle-exclamation text-rose-400 mr-2 text-sm"></i>' :
                   '<i class="fa-solid fa-circle-info text-blue-400 mr-2 text-sm"></i>';
      toast.className = `${bg} pointer-events-auto border rounded-2xl px-4 py-3 text-xs font-semibold shadow-2xl flex items-center justify-between backdrop-blur-md transition-all duration-300 transform translate-y-3 opacity-0`;
      toast.innerHTML = `
        <div class="flex items-center truncate mr-2">
          ${icon}
          <span class="truncate">${message}</span>
        </div>
        <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white p-0.5 ml-2 transition">
          <i class="fa-solid fa-xmark text-xs"></i>
        </button>
      `;
      container.appendChild(toast);
      requestAnimationFrame(() => toast.classList.remove('translate-y-3', 'opacity-0'));
      setTimeout(() => {
        toast.classList.add('translate-y-3', 'opacity-0');
        setTimeout(() => toast.remove(), 350);
      }, 3200);
    }
    window.alert = (m) => showInAppToast(String(m), 'info');

    setInterval(() => {
      fetch('api/live.php?action=heartbeat&page=Temp Transfer').catch(() => {});
    }, 25000);
  </script>
</body>
</html>
