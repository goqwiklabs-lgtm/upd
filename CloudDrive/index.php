<?php
session_start();
require_once __DIR__ . '/config/db.php';
$pdo = getDBConnection();
$clientIP = getClientIP();
if (isIPBlocked($clientIP, $pdo)) {
    http_response_code(403);
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:50px;"><h1>403 Forbidden</h1><p>Your IP address has been blocked by administrator.</p></body></html>');
}
trackLiveVisitor($pdo, 'Home Explorer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CloudDrive - Secure Cloud Storage & Temp Transfer</title>
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- FontAwesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Custom UI Styles -->
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
</head>
<body class="bg-slate-50 text-slate-900 font-sans antialiased min-h-screen flex flex-col selection:bg-blue-600 selection:text-white">

  <!-- MOBILE SIDEBAR BACKDROP OVERLAY -->
  <div id="sidebar-overlay" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-slate-900/50 backdrop-blur-xs z-40 hidden lg:hidden transition-opacity"></div>

  <!-- TOP NAVBAR -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-30 px-4 sm:px-6 py-3 flex items-center justify-between shadow-xs">
    <!-- Brand & Mobile Menu Button -->
    <div class="flex items-center space-x-3">
      <button onclick="toggleMobileSidebar()" class="lg:hidden p-2 rounded-xl text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition" title="Toggle Menu">
        <i class="fa-solid fa-bars text-lg"></i>
      </button>

      <div class="flex items-center space-x-2.5 cursor-pointer" onclick="showDriveView()">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-blue-500/20">
          <i class="fa-solid fa-cloud text-base"></i>
        </div>
        <div>
          <span class="font-bold text-base sm:text-lg text-slate-800 tracking-tight">CloudDrive</span>
          <span class="text-[9px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 ml-1">v2</span>
        </div>
      </div>
    </div>

    <!-- Search Input -->
    <div class="hidden md:flex items-center flex-1 max-w-md mx-6">
      <div class="relative w-full">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <i class="fa-solid fa-magnifying-glass text-xs"></i>
        </span>
        <input type="text" id="search-input" placeholder="Search files & folders..." class="w-full pl-9 pr-4 py-2 bg-slate-100 hover:bg-slate-200/70 focus:bg-white border border-transparent focus:border-blue-500 rounded-xl text-xs sm:text-sm transition outline-none">
      </div>
    </div>

    <!-- User Action Controls -->
    <div class="flex items-center space-x-2 sm:space-x-3">
      <!-- P2P Share -->
      <button onclick="openP2PShareModal()" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl hidden sm:flex items-center space-x-1.5 transition" title="P2P Direct LAN Transfer">
        <i class="fa-solid fa-bolt text-emerald-600"></i>
        <span>P2P</span>
      </button>

      <!-- Admin Panel Button -->
      <a id="admin-panel-btn" href="admin/" class="hidden px-3 py-2 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold rounded-xl border border-amber-200 items-center space-x-1.5 transition">
        <i class="fa-solid fa-shield-halved"></i>
        <span class="hidden md:inline">Admin</span>
      </a>

      <!-- User Avatar / Menu -->
      <div class="flex items-center space-x-2 pl-2 border-l border-slate-200">
        <div onclick="promptAuthModal()" class="w-8 h-8 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center uppercase cursor-pointer hover:opacity-90 transition" id="user-avatar" title="Account">
          <i class="fa-solid fa-user"></i>
        </div>
        <div class="hidden lg:block text-left cursor-pointer" onclick="promptAuthModal()">
          <div class="text-xs font-semibold text-slate-800 truncate max-w-[100px]" id="user-name-display">Guest</div>
          <div class="text-[10px] text-slate-400 truncate max-w-[100px]" id="user-email-display">Sign in</div>
        </div>
        <button id="logout-btn" onclick="handleLogout()" title="Logout" class="hidden text-slate-400 hover:text-red-500 p-1.5 rounded-lg transition">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </button>
      </div>
    </div>
  </header>

  <!-- MAIN BODY LAYOUT -->
  <div class="flex-1 flex overflow-hidden">

    <!-- RESPONSIVE SIDEBAR (DRAWER ON MOBILE, FIXED ON DESKTOP) -->
    <aside id="app-sidebar" class="w-64 bg-white border-r border-slate-200 p-4 flex flex-col justify-between shrink-0 h-full">
      <div class="space-y-6">
        <!-- Mobile Sidebar Close Header -->
        <div class="flex items-center justify-between lg:hidden pb-3 border-b border-slate-100">
          <span class="text-sm font-bold text-slate-800">Menu</span>
          <button onclick="toggleMobileSidebar()" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>

        <!-- Quick Upload Action Buttons (Purely Main Drive) -->
        <div class="space-y-2">
          <button onclick="document.getElementById('cloud-file-input').click()" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs rounded-xl shadow-md shadow-blue-500/20 transition flex items-center justify-center space-x-2">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Upload Files</span>
          </button>
          <button onclick="document.getElementById('cloud-folder-input').click()" class="w-full py-2 px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-xs rounded-xl transition flex items-center justify-center space-x-2" title="Upload entire directory to current folder">
            <i class="fa-solid fa-folder-open text-blue-500 text-xs"></i>
            <span>Upload Folder</span>
          </button>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-1">
          <button id="nav-btn-drive" onclick="showDriveView()" class="w-full flex items-center space-x-3 px-3 py-2 text-xs font-semibold text-blue-600 bg-blue-50 rounded-xl transition">
            <i class="fa-solid fa-hard-drive text-sm"></i>
            <span>My Files</span>
          </button>
          
          <button id="nav-btn-temp" onclick="showTempUploadsView()" class="w-full flex items-center justify-between px-3 py-2 text-xs font-medium text-slate-600 hover:bg-amber-50 hover:text-amber-800 rounded-xl transition">
            <div class="flex items-center space-x-3">
              <i class="fa-solid fa-clock-rotate-left text-amber-500"></i>
              <span>Temp Upload</span>
            </div>
            <span id="temp-active-badge" class="hidden text-[10px] font-bold px-1.5 py-0.2 rounded-full bg-amber-100 text-amber-800">0</span>
          </button>

          <button id="nav-btn-shared" onclick="showSharedView()" class="w-full flex items-center space-x-3 px-3 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition">
            <i class="fa-solid fa-share-nodes text-slate-400"></i>
            <span>Shared Links</span>
          </button>

          <button onclick="openP2PShareModal()" class="w-full flex items-center justify-between px-3 py-2 text-xs font-medium text-slate-600 hover:bg-emerald-50 hover:text-emerald-800 rounded-xl transition">
            <div class="flex items-center space-x-3">
              <i class="fa-solid fa-bolt text-emerald-600"></i>
              <span>P2P Transfer</span>
            </div>
            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 uppercase">LAN</span>
          </button>
        </nav>
      </div>

      <!-- Storage Usage Meter -->
      <div class="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl space-y-2">
        <div class="flex items-center justify-between text-xs text-slate-600 font-medium">
          <span class="flex items-center"><i class="fa-solid fa-cloud text-blue-500 mr-1.5"></i> Storage</span>
          <span id="user-storage-text" class="text-slate-400">0 MB</span>
        </div>
        <div class="w-full bg-slate-200 rounded-full h-1.5 overflow-hidden">
          <div id="user-storage-bar" class="bg-blue-600 h-full rounded-full transition-all duration-300" style="width: 2%"></div>
        </div>
        <p class="text-[10px] text-slate-400">Multi-Account Google Drive Pool</p>
      </div>
    </aside>

    <!-- MAIN VIEW AREA -->
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 space-y-6">

      <!-- VIEW 1: DRIVE VIEW (MY FILES - PURELY PERMANENT DRIVE STORAGE) -->
      <div id="view-drive" class="space-y-6">

        <!-- CLEAN MODERN DROPZONE (MAIN UPLOAD ONLY - NEVER MERGED) -->
        <section class="max-w-4xl mx-auto">
          <div id="clean-dropzone" class="dropzone bg-white border-2 border-dashed border-slate-300 hover:border-blue-400 rounded-3xl p-6 sm:p-8 text-center transition cursor-pointer shadow-xs hover:shadow-md">
            <div class="flex flex-col items-center justify-center space-y-3">
              <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center text-2xl shadow-inner">
                <i class="fa-solid fa-cloud-arrow-up"></i>
              </div>
              <div>
                <h3 class="text-sm sm:text-base font-bold text-slate-800">Drag and drop files or folder here</h3>
                <p class="text-xs text-slate-400 mt-0.5">Upload permanently to your personal drive</p>
              </div>

              <!-- Quick Selection Buttons for Main Drive -->
              <div class="flex items-center justify-center gap-3 pt-2">
                <button type="button" onclick="event.stopPropagation(); document.getElementById('cloud-file-input').click()" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs rounded-xl shadow-sm transition flex items-center space-x-1.5">
                  <i class="fa-solid fa-file-arrow-up"></i>
                  <span>Browse Files</span>
                </button>
                <button type="button" onclick="event.stopPropagation(); document.getElementById('cloud-folder-input').click()" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
                  <i class="fa-solid fa-folder-open text-blue-500"></i>
                  <span>Browse Folder</span>
                </button>
              </div>
            </div>

            <!-- Hidden Inputs -->
            <input type="file" id="cloud-file-input" multiple class="hidden">
            <input type="file" id="cloud-folder-input" webkitdirectory directory multiple class="hidden">
          </div>
        </section>

        <!-- FILE EXPLORER SECTION -->
        <section class="max-w-6xl mx-auto space-y-4">
          <!-- Toolbar & Breadcrumbs -->
          <div class="flex flex-wrap items-center justify-between gap-3 pb-2 border-b border-slate-200">
            <div id="breadcrumbs-bar" class="flex items-center text-xs sm:text-sm text-slate-500 overflow-x-auto py-1">
              <!-- Dynamically populated -->
            </div>

            <div class="flex items-center space-x-2">
              <button onclick="promptNewFolder()" class="px-3 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition shadow-2xs inline-flex items-center space-x-1.5">
                <i class="fa-solid fa-folder-plus text-amber-500"></i>
                <span class="hidden sm:inline">New Folder</span>
              </button>
              <button onclick="toggleViewMode()" class="px-2.5 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 text-slate-600 text-xs rounded-xl transition" title="Toggle Grid/List">
                <i id="view-mode-icon" class="fa-solid fa-list"></i>
              </button>
            </div>
          </div>

          <!-- Files & Folders Container -->
          <div id="file-explorer-content">
            <!-- Dynamically populated -->
          </div>
        </section>

      </div>

      <!-- VIEW 2: DEDICATED SEPARATE TEMP UPLOADS WORKSPACE (100% SEPARATED) -->
      <div id="view-temp-uploads" class="space-y-8 hidden max-w-5xl mx-auto">
        <!-- View Header -->
        <div class="pb-4 border-b border-slate-200">
          <div class="flex items-center space-x-2.5">
            <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-orange-500 flex items-center justify-center text-white shadow-md shadow-amber-500/20">
              <i class="fa-solid fa-clock-rotate-left text-lg"></i>
            </div>
            <div>
              <div class="flex items-center space-x-2">
                <h2 class="text-lg sm:text-xl font-bold text-slate-800">Temporary Secure Transfer</h2>
                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200 uppercase">Self-Destruct</span>
              </div>
              <p class="text-xs text-slate-400 mt-0.5">Upload single files, multiple files, or an entire folder. All files are permanently deleted from Google Drive upon expiry.</p>
            </div>
          </div>
        </div>

        <!-- DEDICATED TEMP UPLOAD BOX (COMPLETELY SEPARATE FROM MAIN DRIVE) -->
        <div class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 shadow-xs space-y-6">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-slate-800 flex items-center space-x-2">
              <i class="fa-solid fa-cloud-arrow-up text-amber-500"></i>
              <span>Create New Temporary Transfer</span>
            </h3>
            <span class="text-[11px] text-slate-400">Min: 10 min • Max: 7 days</span>
          </div>

          <!-- Step 1: Selection & Settings -->
          <div id="dedicated-temp-select" class="space-y-5">
            <!-- Dedicated Temp Dropzone -->
            <div id="dedicated-temp-dropzone" class="border-2 border-dashed border-amber-300 hover:border-amber-500 bg-amber-50/20 hover:bg-amber-50/50 rounded-2xl p-8 text-center transition cursor-pointer" onclick="document.getElementById('dedicated-temp-file-input').click()">
              <div class="w-14 h-14 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center text-2xl mx-auto mb-3 shadow-inner">
                <i class="fa-solid fa-folder-arrow-up"></i>
              </div>
              <h4 class="text-sm font-bold text-slate-800 mb-0.5">Drop files or folder here for temporary upload</h4>
              <p class="text-xs text-slate-400 mb-4">Select single files, multiple files, or an entire directory</p>

              <div class="flex flex-wrap items-center justify-center gap-3" onclick="event.stopPropagation()">
                <button type="button" onclick="document.getElementById('dedicated-temp-file-input').click()" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs rounded-xl shadow-sm transition flex items-center space-x-1.5">
                  <i class="fa-solid fa-file text-xs"></i>
                  <span>Browse Files</span>
                </button>
                <button type="button" onclick="document.getElementById('dedicated-temp-folder-input').click()" class="px-4 py-2 bg-white border border-amber-300 hover:bg-amber-50 text-amber-800 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
                  <i class="fa-solid fa-folder-open text-xs"></i>
                  <span>Browse Folder</span>
                </button>
              </div>

              <!-- Dedicated Hidden Inputs for Temp Upload -->
              <input type="file" id="dedicated-temp-file-input" multiple class="hidden" onchange="handleDedicatedTempFileSelection(event)">
              <input type="file" id="dedicated-temp-folder-input" webkitdirectory directory multiple class="hidden" onchange="handleDedicatedTempFolderSelection(event)">
            </div>

            <!-- Selected Files Tray -->
            <div id="dedicated-temp-selected-tray" class="hidden space-y-2">
              <div class="flex items-center justify-between text-xs px-1">
                <span id="dedicated-temp-selected-summary" class="font-bold text-slate-700">0 files selected</span>
                <button onclick="clearDedicatedTempSelection()" class="text-rose-500 hover:underline text-[11px] font-semibold">Clear</button>
              </div>
              <div id="dedicated-temp-selected-list" class="max-h-48 overflow-y-auto space-y-1.5 pr-1"></div>
            </div>

            <!-- Expiry Duration Selector (10 min to 7 days) -->
            <div class="p-4 bg-slate-50 rounded-2xl border border-slate-200/80 space-y-3">
              <div class="flex items-center justify-between">
                <label class="text-xs font-bold text-slate-700 flex items-center">
                  <i class="fa-solid fa-stopwatch text-amber-500 mr-1.5"></i>
                  <span>Auto-Expiry & Deletion Timer:</span>
                </label>
                <span id="dedicated-expiry-label" class="text-xs font-mono font-bold text-amber-600">10 min</span>
              </div>

              <!-- Expiry Preset Pills -->
              <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-8 gap-1.5">
                <button type="button" onclick="setDedicatedTempExpiry(10, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400 active">10 min</button>
                <button type="button" onclick="setDedicatedTempExpiry(30, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">30 min</button>
                <button type="button" onclick="setDedicatedTempExpiry(60, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">1 hour</button>
                <button type="button" onclick="setDedicatedTempExpiry(360, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">6 hours</button>
                <button type="button" onclick="setDedicatedTempExpiry(1440, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">24 hours</button>
                <button type="button" onclick="setDedicatedTempExpiry(2880, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">2 days</button>
                <button type="button" onclick="setDedicatedTempExpiry(4320, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">3 days</button>
                <button type="button" onclick="setDedicatedTempExpiry(10080, this)" class="expiry-pill py-2 px-1 text-center text-xs font-semibold rounded-xl border border-slate-200 hover:border-amber-400">7 days</button>
              </div>

              <!-- Custom Duration -->
              <div class="flex items-center space-x-2 pt-1 text-xs">
                <span class="text-slate-500 text-[11px]">Or custom minutes (10 - 10080):</span>
                <input type="number" id="dedicated-temp-custom-expiry" min="10" max="10080" placeholder="10 - 10080" oninput="handleDedicatedCustomExpiry(event)" class="w-28 px-2.5 py-1 bg-white border border-slate-200 rounded-lg text-xs outline-none focus:border-amber-500">
              </div>

              <div class="text-[11px] text-amber-800 flex items-center space-x-1.5 pt-1">
                <i class="fa-solid fa-shield-halved text-amber-600"></i>
                <span>When expired, all files are <strong>permanently deleted from Google Drive</strong> and the link will stop working.</span>
              </div>
            </div>

            <!-- Upload Button -->
            <button id="dedicated-start-upload-btn" onclick="startDedicatedTempUpload()" class="w-full py-3.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-bold text-xs sm:text-sm rounded-2xl shadow-md shadow-amber-500/25 transition flex items-center justify-center space-x-2">
              <i class="fa-solid fa-bolt"></i>
              <span>Upload & Generate Temp Link</span>
            </button>
          </div>

          <!-- Step 2: Upload Progress -->
          <div id="dedicated-temp-progress" class="hidden space-y-4 py-6 text-center">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center text-2xl mx-auto animate-pulse">
              <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <div>
              <h4 id="dedicated-progress-filename" class="text-sm font-bold text-slate-800 truncate">Uploading...</h4>
              <p id="dedicated-progress-status" class="text-xs text-slate-400 mt-0.5">Streaming to Google Drive pool...</p>
            </div>

            <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden p-0.5 border border-slate-200 max-w-md mx-auto">
              <div id="dedicated-progress-bar" class="bg-gradient-to-r from-amber-500 to-orange-500 h-full rounded-full transition-all duration-150" style="width: 0%"></div>
            </div>

            <div class="flex justify-between items-center text-xs text-slate-400 font-mono max-w-md mx-auto">
              <span id="dedicated-progress-bytes">0 B / 0 B</span>
              <span id="dedicated-progress-pct" class="font-bold text-amber-600">0%</span>
            </div>
          </div>

          <!-- Step 3: Success Banner -->
          <div id="dedicated-temp-success" class="hidden space-y-4 text-center py-4 bg-emerald-50/50 rounded-2xl p-6 border border-emerald-200">
            <div class="w-14 h-14 rounded-2xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-2xl mx-auto shadow-inner">
              <i class="fa-solid fa-check"></i>
            </div>
            <div>
              <h4 class="text-base font-bold text-slate-800">Temporary Transfer Ready!</h4>
              <p id="dedicated-success-expiry-text" class="text-xs text-slate-500 mt-0.5">Expires in 10 minutes</p>
            </div>

            <div class="max-w-md mx-auto bg-white p-2.5 rounded-xl border border-slate-200 flex items-center space-x-2">
              <input type="text" id="dedicated-success-link-input" readonly class="flex-1 px-2.5 py-1.5 text-xs font-mono text-slate-700 outline-none truncate">
              <button onclick="copyDedicatedTempLink()" class="px-4 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-lg transition">
                Copy
              </button>
            </div>

            <div class="flex items-center justify-center gap-3 pt-2">
              <a id="dedicated-success-open-btn" href="#" target="_blank" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white text-xs font-semibold rounded-xl transition flex items-center space-x-1.5">
                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                <span>Open Transfer</span>
              </a>
              <button onclick="resetDedicatedTempUpload()" class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold rounded-xl transition">
                Upload Another
              </button>
            </div>
          </div>
        </div>

        <!-- ACTIVE TEMPORARY TRANSFERS SECTION -->
        <div class="space-y-4">
          <div class="flex items-center justify-between px-1">
            <h3 class="text-sm font-bold text-slate-800 flex items-center space-x-2">
              <i class="fa-solid fa-list-check text-amber-500"></i>
              <span>Active Temporary Transfers</span>
            </h3>
            <span class="text-xs text-slate-400">Live countdown to permanent deletion</span>
          </div>

          <!-- Temp Uploads Grid -->
          <div id="temp-uploads-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Dynamically populated -->
          </div>

          <div id="temp-uploads-empty" class="hidden text-center py-12 bg-white rounded-3xl border border-dashed border-slate-200 p-8">
            <div class="w-14 h-14 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center text-xl mx-auto mb-2">
              <i class="fa-solid fa-hourglass-start"></i>
            </div>
            <h4 class="text-sm font-bold text-slate-700">No active temporary transfers</h4>
            <p class="text-xs text-slate-400 mt-0.5">Use the box above to create a temporary transfer that self-destructs!</p>
          </div>
        </div>
      </div>

      <!-- VIEW 3: SHARED LINKS VIEW -->
      <div id="view-shared" class="space-y-6 hidden max-w-6xl mx-auto">
        <div class="pb-4 border-b border-slate-200">
          <h2 class="text-lg sm:text-xl font-bold text-slate-800">Publicly Shared Items</h2>
          <p class="text-xs text-slate-400 mt-0.5">Folders and files you have shared with public links.</p>
        </div>
        <div id="shared-items-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Dynamically populated -->
        </div>
      </div>

    </main>
  </div>



  <!-- FLOATING UPLOAD PROGRESS DOCK (BOTTOM RIGHT) -->
  <div id="upload-dock" class="fixed bottom-6 right-6 z-50 w-80 max-w-[90vw] hidden transition-all">
    <div class="bg-white border border-slate-200 rounded-2xl shadow-xl p-3 space-y-2">
      <div class="flex items-center justify-between border-b border-slate-100 pb-2">
        <span class="text-xs font-bold text-slate-800 flex items-center">
          <i class="fa-solid fa-circle-arrow-up text-blue-500 mr-1.5"></i> Upload Activity
        </span>
        <button onclick="document.getElementById('upload-dock').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xs">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div id="upload-dock-items" class="space-y-2 max-h-60 overflow-y-auto"></div>
    </div>
  </div>

  <!-- CONTEXT MENU (RIGHT CLICK OR 3-DOTS) -->
  <div id="context-menu" class="context-menu hidden">
    <div onclick="ctxOpen()" class="context-menu-item">
      <i class="fa-solid fa-arrow-up-right-from-square text-blue-500"></i>
      <span>Open / Preview</span>
    </div>
    <div onclick="ctxShare()" class="context-menu-item">
      <i class="fa-solid fa-share-nodes text-emerald-500"></i>
      <span>Share Link</span>
    </div>
    <div onclick="ctxRename()" class="context-menu-item">
      <i class="fa-solid fa-pen text-slate-500"></i>
      <span>Rename</span>
    </div>
    <div onclick="ctxCopy()" class="context-menu-item ctx-file-only">
      <i class="fa-solid fa-copy text-indigo-500"></i>
      <span>Make a copy</span>
    </div>
    <div onclick="ctxDownload()" class="context-menu-item ctx-file-only">
      <i class="fa-solid fa-download text-amber-500"></i>
      <span>Download</span>
    </div>
    <div class="my-1 border-t border-slate-100"></div>
    <div onclick="ctxDelete()" class="context-menu-item danger">
      <i class="fa-solid fa-trash text-red-500"></i>
      <span>Delete</span>
    </div>
  </div>

  <!-- AUTH MODAL (LOGIN / REGISTER) -->
  <div id="auth-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 sm:p-8 shadow-2xl border border-slate-100">
      <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white mx-auto mb-3 shadow-lg shadow-blue-500/25 text-xl">
          <i class="fa-solid fa-cloud"></i>
        </div>
        <h2 class="text-xl font-bold text-slate-800">Welcome to CloudDrive</h2>
        <p class="text-xs text-slate-400 mt-1">Multi-Account Cloud Storage & Secure Sharing</p>
      </div>

      <!-- Tabs -->
      <div class="flex border-b border-slate-200 mb-6">
        <button id="tab-login" onclick="switchAuthTab('login')" class="flex-1 pb-3 text-center text-sm font-semibold border-b-2 border-blue-600 text-blue-600 transition">Login</button>
        <button id="tab-register" onclick="switchAuthTab('register')" class="flex-1 pb-3 text-center text-sm font-semibold border-b-2 border-transparent text-slate-400 transition">Register</button>
      </div>

      <div id="auth-error" class="hidden mb-4 p-3 bg-red-50 border border-red-200 text-red-600 text-xs rounded-xl"></div>

      <!-- Login Form -->
      <form id="login-form" onsubmit="handleLogin(event)" class="space-y-4">
        <div>
          <label class="block text-xs font-medium text-slate-700 mb-1">Username or Email</label>
          <input type="text" name="identifier" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 mb-1">Password</label>
          <input type="password" name="password" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm rounded-xl transition shadow-md shadow-blue-500/20">Sign In</button>
      </form>

      <!-- Register Form -->
      <form id="register-form" onsubmit="handleRegister(event)" class="space-y-4 hidden">
        <div>
          <label class="block text-xs font-medium text-slate-700 mb-1">Username</label>
          <input type="text" name="username" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 mb-1">Email</label>
          <input type="email" name="email" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-700 mb-1">Password (min 6 characters)</label>
          <input type="password" name="password" minlength="6" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <button type="submit" class="w-full py-3 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-sm rounded-xl transition shadow-md shadow-blue-500/20">Create Account</button>
      </form>
    </div>
  </div>

  <!-- NEW FOLDER MODAL -->
  <div id="new-folder-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-slate-100">
      <h3 class="text-base font-bold text-slate-800 mb-4 flex items-center">
        <i class="fa-solid fa-folder-plus text-amber-500 mr-2"></i> New Folder
      </h3>
      <form onsubmit="handleCreateFolder(event)" class="space-y-4">
        <div>
          <input type="text" id="new-folder-name" placeholder="Folder name" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition">
        </div>
        <div class="flex items-center justify-end space-x-2">
          <button type="button" onclick="document.getElementById('new-folder-modal').classList.add('hidden')" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancel</button>
          <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition shadow-xs">Create</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SHARE MODAL -->
  <div id="share-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-100 space-y-5">
      <div class="flex items-center justify-between">
        <h3 id="share-modal-title" class="text-base font-bold text-slate-800 flex items-center">
          <i id="share-modal-icon" class="fa-solid fa-share-nodes text-blue-500 mr-2"></i> Share Link
        </h3>
        <button onclick="document.getElementById('share-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="flex items-center justify-between p-3.5 bg-slate-50 rounded-2xl border border-slate-200">
        <div>
          <div class="text-xs font-semibold text-slate-800">Public Link Access</div>
          <div id="share-modal-desc" class="text-[11px] text-slate-400">Anyone with this link can view and download</div>
        </div>
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" id="share-public-toggle" onchange="handleShareToggle()" class="sr-only peer">
          <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
        </label>
      </div>

      <div class="space-y-2">
        <label class="block text-xs font-medium text-slate-600">Shareable Link</label>
        <div class="flex items-center space-x-2">
          <input type="text" id="share-link-input" readonly class="flex-1 px-3 py-2 bg-slate-100 border border-slate-200 rounded-xl text-xs font-mono text-slate-700 outline-none">
          <button onclick="copyShareLink()" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition shadow-xs">
            Copy
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- INTEGRATED IN-APP PREVIEW MODAL -->
  <div id="preview-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md flex flex-col justify-between p-4 sm:p-6 z-50 hidden">
    <div class="flex items-center justify-between pb-4 max-w-6xl w-full mx-auto">
      <h3 id="preview-modal-title" class="text-base font-bold text-white truncate max-w-lg">File Preview</h3>
      <button onclick="closePreviewModal()" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition">
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>
    </div>
    <div id="preview-modal-content" class="flex-1 flex items-center justify-center max-w-6xl w-full mx-auto overflow-hidden"></div>
  </div>

  <!-- P2P SHARE MODAL (RETAINED FOR DIRECT LAN TRANSFERS) -->
  <div id="p2p-share-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-3 sm:p-6 z-50 hidden">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden max-h-[92vh] border border-slate-200 animate-in fade-in">
      
      <!-- Modal Header -->
      <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between border-b border-slate-800">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-emerald-500 to-cyan-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/25">
            <i class="fa-solid fa-bolt text-lg"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <h3 class="font-bold text-base tracking-tight text-white">Direct LAN Transfer</h3>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 uppercase">30-80 MB/s</span>
            </div>
            <p class="text-xs text-slate-400">WebRTC Direct Socket • Zero Internet Consumption</p>
          </div>
        </div>
        <button onclick="closeP2PShareModal()" class="text-slate-400 hover:text-white p-2 rounded-xl hover:bg-slate-800 transition">
          <i class="fa-solid fa-xmark text-lg"></i>
        </button>
      </div>

      <!-- Tab Switcher -->
      <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 pt-3">
        <div class="flex space-x-2">
          <button id="p2p-tab-send-btn" onclick="setP2PTab('send')" class="flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-emerald-600 text-emerald-600 transition">
            <i class="fa-solid fa-arrow-up-from-bracket"></i>
            <span>Send Files</span>
          </button>
          <button id="p2p-tab-receive-btn" onclick="setP2PTab('receive')" class="flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition">
            <i class="fa-solid fa-arrow-down-to-bracket"></i>
            <span>Receive Files</span>
          </button>
        </div>
        <span id="p2p-radar-count-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">Searching...</span>
      </div>

      <!-- Modal Body -->
      <div class="flex-1 overflow-y-auto p-6 bg-slate-50/50">
        <!-- SEND SECTION -->
        <div id="p2p-send-section" class="space-y-6">
          <input type="file" id="p2p-send-file-input" class="hidden" multiple>
          <div id="p2p-send-dropzone" onclick="document.getElementById('p2p-send-file-input').click()" class="border-2 border-dashed border-slate-300 hover:border-emerald-500 hover:bg-emerald-50/20 rounded-3xl p-8 flex flex-col items-center justify-center text-center transition cursor-pointer bg-white">
            <div class="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3 text-xl">
              <i class="fa-solid fa-bolt"></i>
            </div>
            <h4 class="font-bold text-slate-800 text-sm mb-1">Select Files for Direct Transfer</h4>
            <p class="text-xs text-slate-500 max-w-sm mb-3">Send to nearby devices on the same Wi-Fi without uploading to cloud</p>
            <span class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl shadow-xs transition">Choose Files</span>
          </div>

          <div id="p2p-send-active-card" class="hidden grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="flex flex-col items-center bg-white p-4 rounded-2xl border border-slate-200 space-y-2">
              <canvas id="p2p-send-qr-canvas" class="w-40 h-40 object-contain rounded-lg border"></canvas>
              <input type="text" id="p2p-send-join-link" readonly class="w-full text-[11px] font-mono text-center bg-slate-50 p-1.5 rounded-lg border">
            </div>
            <div class="space-y-3 bg-white p-4 rounded-2xl border border-slate-200">
              <div id="p2p-send-files-summary" class="text-xs font-bold text-slate-800">Files Selected</div>
              <div id="p2p-send-files-list" class="max-h-36 overflow-y-auto space-y-1 text-xs"></div>
              <div id="p2p-radar-peer-list" class="space-y-1 text-xs"></div>
            </div>
          </div>
        </div>

        <!-- RECEIVE SECTION -->
        <div id="p2p-receive-section" class="hidden space-y-4">
          <div id="p2p-receive-pending" class="p-6 bg-white rounded-3xl border border-slate-200 space-y-3">
            <h4 class="font-bold text-slate-800 text-sm">Nearby Incoming Session</h4>
            <div id="p2p-receive-files-list" class="space-y-2 text-xs"></div>
            <button onclick="startP2PDownload()" class="px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-emerald-600 hover:bg-emerald-500 transition">
              Accept & Stream
            </button>
          </div>
          <div class="p-4 bg-white rounded-2xl border border-slate-200 space-y-2">
            <label class="text-xs font-semibold text-slate-700 block">Or enter 6-digit PIN:</label>
            <div class="flex space-x-2">
              <input type="text" id="p2p-manual-code-input" placeholder="123456" class="px-3 py-1.5 border rounded-xl text-xs font-mono">
              <button onclick="handleP2PManualJoin()" class="px-4 py-1.5 bg-slate-800 text-white rounded-xl text-xs font-bold">Connect</button>
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-3 bg-white border-t border-slate-200 text-xs text-slate-500 flex justify-between items-center">
        <span>WebRTC Direct Socket</span>
        <button onclick="closeP2PShareModal()" class="px-3 py-1 bg-slate-100 rounded-lg text-xs font-medium">Close</button>
      </div>
    </div>
  </div>

  <!-- QR Code Renderer Library -->
  <script src="assets/js/qrcode.min.js"></script>
  <!-- Application & P2P Logic -->
  <script src="assets/js/app.js"></script>
  <script src="assets/js/p2p.js"></script>
  <script>
    // Live visitor heartbeat ping
    setInterval(() => {
      fetch('api/live.php?action=heartbeat&page=Home Explorer').catch(() => {});
    }, 25000);
  </script>
</body>
</html>
