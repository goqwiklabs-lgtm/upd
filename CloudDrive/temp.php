<?php
session_start();
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

$token = trim($_GET['token'] ?? '');
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
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col justify-between selection:bg-amber-500 selection:text-white font-sans antialiased">

  <!-- TOP HEADER -->
  <header class="border-b border-slate-800/80 bg-slate-900/90 backdrop-blur sticky top-0 z-40 px-4 sm:px-8 py-3.5 flex items-center justify-between">
    <a href="index.php" class="flex items-center space-x-3 group">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-lg shadow-amber-500/20 group-hover:scale-105 transition-transform">
        <i class="fa-solid fa-clock text-lg"></i>
      </div>
      <div>
        <div class="flex items-center space-x-2">
          <span class="font-bold text-lg text-white tracking-tight">CloudDrive</span>
          <span class="text-[10px] uppercase font-bold tracking-wider px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30">Temp Share</span>
        </div>
        <p class="text-[11px] text-slate-400">Self-Destructing File Transfer</p>
      </div>
    </a>

    <a href="index.php" class="px-3.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl border border-slate-700/80 transition flex items-center space-x-1.5">
      <i class="fa-solid fa-house text-xs"></i>
      <span>Go to Drive</span>
    </a>
  </header>

  <!-- MAIN BODY -->
  <main class="flex-1 max-w-4xl w-full mx-auto p-4 sm:p-8 space-y-6">

    <?php if ($upload && !$isExpired): 
        $expiresEpoch = strtotime($upload['expires_at']);
        $secondsRemaining = max(0, $expiresEpoch - time());
    ?>

      <!-- STATUS & COUNTDOWN BANNER -->
      <div class="bg-gradient-to-br from-slate-900 to-slate-900/80 border border-amber-500/30 rounded-3xl p-6 sm:p-8 shadow-2xl relative overflow-hidden">
        <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>

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
                • <span class="text-amber-400"><i class="fa-solid fa-folder-closed mr-1"></i>Folder Upload</span>
              <?php endif; ?>
            </p>
          </div>

          <!-- COUNTDOWN TIMER DISPLAY -->
          <div class="bg-slate-950/80 border border-amber-500/40 rounded-2xl p-4 sm:px-6 text-center min-w-[200px] shadow-lg">
            <span class="text-[10px] uppercase font-bold tracking-wider text-slate-400 block mb-1">Time Remaining</span>
            <div id="countdown-clock" class="text-2xl sm:text-3xl font-black font-mono text-amber-400 tracking-wider">
              --:--:--
            </div>
            <span class="text-[10px] text-slate-500 block mt-1">Permanently deleted at 00:00</span>
          </div>
        </div>

        <div class="mt-6 pt-5 border-t border-slate-800 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-400">
          <div class="flex items-center space-x-2">
            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
            <span>Files are permanently wiped from Google Drive upon expiry</span>
          </div>
          <div class="flex items-center space-x-2">
            <button onclick="copyCurrentLink()" class="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 rounded-xl font-medium transition flex items-center space-x-1.5">
              <i class="fa-solid fa-copy text-amber-400"></i>
              <span>Copy Link</span>
            </button>
          </div>
        </div>
      </div>

      <!-- FILE LIST SECTION -->
      <div class="space-y-4">
        <div class="flex items-center justify-between px-1">
          <h2 class="text-base font-bold text-slate-200 flex items-center space-x-2">
            <i class="fa-solid fa-file-zipper text-amber-500"></i>
            <span>Included Files (<?= count($files) ?>)</span>
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
            alert('Share link copied to clipboard!');
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

      <!-- EXPIRED OR NOT FOUND CARD -->
      <div class="bg-slate-900/90 border border-rose-500/30 rounded-3xl p-8 sm:p-12 text-center max-w-lg mx-auto shadow-2xl space-y-5 my-12">
        <div class="w-20 h-20 rounded-3xl bg-rose-500/10 border border-rose-500/20 text-rose-500 flex items-center justify-center mx-auto text-3xl">
          <i class="fa-solid fa-hourglass-end animate-pulse"></i>
        </div>
        <div class="space-y-2">
          <h1 class="text-2xl font-bold text-white tracking-tight">This Link Has Expired</h1>
          <p class="text-sm text-slate-400 leading-relaxed">
            The temporary transfer has expired and all files have been <strong class="text-rose-400">permanently deleted</strong> from Google Drive for your privacy and security.
          </p>
        </div>
        <div class="pt-4 border-t border-slate-800 flex justify-center">
          <a href="index.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs rounded-xl shadow-lg shadow-blue-500/20 transition flex items-center space-x-2">
            <i class="fa-solid fa-arrow-left"></i>
            <span>Return to CloudDrive</span>
          </a>
        </div>
      </div>

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
  <footer class="border-t border-slate-800/80 py-4 px-6 text-center text-xs text-slate-500">
    CloudDrive Temporary Transfer Engine • Zero Retained Data After Expiry
  </footer>

  <script>
    setInterval(() => {
      fetch('api/live.php?action=heartbeat&page=Temp Transfer').catch(() => {});
    }, 25000);
  </script>
</body>
</html>
