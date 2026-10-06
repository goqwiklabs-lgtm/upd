<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CloudDrive - Personal Cloud Storage</title>
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- FontAwesome Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- Custom UI & Basketball Animation Styles -->
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-slate-50 text-slate-900 font-sans antialiased min-h-screen flex flex-col">

  <!-- TOP NAVBAR -->
  <header class="bg-white border-b border-slate-200 sticky top-0 z-40 px-4 sm:px-6 py-3 flex items-center justify-between shadow-xs">
    <!-- Brand -->
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-blue-500/20">
        <i class="fa-solid fa-cloud text-lg"></i>
      </div>
      <div>
        <span class="font-bold text-lg text-slate-800 tracking-tight">CloudDrive</span>
        <span class="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 ml-1">Pro</span>
      </div>
    </div>

    <!-- Search Input -->
    <div class="hidden md:flex items-center flex-1 max-w-md mx-8">
      <div class="relative w-full">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400">
          <i class="fa-solid fa-magnifying-glass text-sm"></i>
        </span>
        <input type="text" id="search-input" placeholder="Search in drive..." class="w-full pl-9 pr-4 py-2 bg-slate-100 hover:bg-slate-200/70 focus:bg-white border border-transparent focus:border-blue-500 rounded-xl text-sm transition outline-none">
      </div>
    </div>

    <!-- User Action Controls -->
    <div class="flex items-center space-x-3">
      <!-- P2P Offline Share (LocalSend / Xender) -->
      <button onclick="openP2PShareModal()" class="px-3.5 py-2 bg-gradient-to-r from-emerald-600 to-cyan-600 hover:from-emerald-500 hover:to-cyan-500 text-white text-xs font-semibold rounded-xl flex items-center space-x-1.5 transition shadow-sm shadow-emerald-500/20" title="P2P Offline File Transfer (30-80+ MB/s Zero Data)">
        <i class="fa-solid fa-bolt"></i>
        <span>P2P Share</span>
      </button>

      <a id="admin-panel-btn" href="admin/" class="hidden px-3.5 py-2 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold rounded-xl border border-amber-200 items-center space-x-1.5 transition">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Admin Panel</span>
      </a>

      <div class="flex items-center space-x-2 pl-2 border-l border-slate-200">
        <div class="w-8 h-8 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center uppercase" id="user-avatar">
          <i class="fa-solid fa-user"></i>
        </div>
        <div class="hidden sm:block text-left">
          <div class="text-xs font-semibold text-slate-800" id="user-name-display">Guest</div>
          <div class="text-[10px] text-slate-400" id="user-email-display">Not logged in</div>
        </div>
        <button onclick="handleLogout()" title="Logout" class="text-slate-400 hover:text-red-500 p-1.5 rounded-lg transition ml-1">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
        </button>
      </div>
    </div>
  </header>

  <!-- MAIN BODY LAYOUT -->
  <div class="flex-1 flex overflow-hidden">

    <!-- SIDEBAR -->
    <aside class="w-64 bg-white border-r border-slate-200 p-4 hidden lg:flex flex-col justify-between shrink-0">
      <div class="space-y-6">
        <!-- Action Buttons -->
        <div class="space-y-2">
          <button onclick="document.getElementById('cloud-file-input').click()" class="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white font-medium text-sm rounded-xl shadow-md shadow-blue-500/20 transition flex items-center justify-center space-x-2">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Upload Files</span>
          </button>
          <button onclick="promptNewFolder()" class="w-full py-2 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-xs rounded-xl transition flex items-center justify-center space-x-2">
            <i class="fa-solid fa-folder-plus text-amber-500"></i>
            <span>New Folder</span>
          </button>
        </div>

        <!-- Navigation Links -->
        <nav class="space-y-1">
          <button onclick="loadFiles(null)" class="w-full flex items-center space-x-3 px-3 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-xl transition">
            <i class="fa-solid fa-hard-drive"></i>
            <span>My Drive</span>
          </button>
          <button class="w-full flex items-center space-x-3 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition">
            <i class="fa-solid fa-clock-rotate-left text-slate-400"></i>
            <span>Recent</span>
          </button>
          <button class="w-full flex items-center space-x-3 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition">
            <i class="fa-solid fa-share-nodes text-slate-400"></i>
            <span>Shared Links</span>
          </button>
          <button onclick="openP2PShareModal()" class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-slate-700 hover:bg-emerald-50 hover:text-emerald-700 rounded-xl transition group">
            <div class="flex items-center space-x-3">
              <i class="fa-solid fa-bolt text-emerald-600 group-hover:scale-110 transition-transform"></i>
              <span>P2P Share</span>
            </div>
            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 uppercase tracking-wide">Offline</span>
          </button>
        </nav>
      </div>

      <!-- Storage Usage Meter -->
      <div class="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl">
        <div class="flex items-center justify-between text-xs text-slate-600 font-medium mb-1.5">
          <span class="flex items-center"><i class="fa-solid fa-cloud text-blue-500 mr-1.5"></i> Storage</span>
          <span id="user-storage-text" class="text-slate-400">0 MB used</span>
        </div>
        <p class="text-[11px] text-slate-400 mt-2">Unlimited Multi-Account Storage Pool</p>
      </div>
    </aside>

    <!-- MAIN VIEW AREA -->
    <main class="flex-1 overflow-y-auto p-4 sm:p-8 space-y-8">

      <!-- THE BASKETBALL HOOP DROPZONE SECTION (Inspired by video) -->
      <section class="max-w-2xl mx-auto">
        <div class="flex items-center justify-between mb-3 px-1">
          <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-800 tracking-tight">Upload files</h1>
            <p class="text-xs sm:text-sm text-slate-400">Drag and drop, or take the shot.</p>
          </div>
          <div class="flex items-center space-x-2">
            <span id="uploaded-counter-badge" class="px-3 py-1 bg-slate-200/70 text-slate-700 text-xs font-bold rounded-full">
              Uploaded 0
            </span>
          </div>
        </div>

        <!-- Basketball Hoop Interactive Drop Box -->
        <div id="shot-dropzone" class="dropzone relative bg-white border-2 border-dashed border-slate-300 rounded-3xl p-8 text-center transition-all cursor-pointer shadow-xs hover:border-blue-400 overflow-hidden" onclick="document.getElementById('cloud-file-input').click()">
          <!-- Trajectory Canvas -->
          <canvas id="trajectory-canvas"></canvas>

          <div class="flex flex-col items-center justify-center space-y-4">
            <div class="flex items-center space-x-2 text-slate-600 text-sm font-medium">
              <i class="fa-solid fa-arrow-up-from-bracket text-blue-500"></i>
              <span>Drop files here or take the shot</span>
            </div>

            <!-- Basketball Hoop Illustration -->
            <div class="hoop-container">
              <div class="hoop-backboard">
                <div class="hoop-inner-box"></div>
              </div>
              <div class="hoop-rim"></div>
              <div class="hoop-net"></div>
              <div class="score-pop">+1</div>
            </div>

            <p class="text-xs text-slate-400">Supports videos, images, PDFs, documents, up to 2GB+ per file</p>
          </div>

          <!-- Hidden Input for File Selector -->
          <input type="file" id="cloud-file-input" multiple class="hidden">
        </div>
      </section>

      <!-- FILE EXPLORER SECTION -->
      <section class="max-w-6xl mx-auto space-y-4">
        <!-- Breadcrumbs & Quick Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 pb-2 border-b border-slate-200">
          <div id="breadcrumbs-bar" class="flex items-center text-sm text-slate-500 overflow-x-auto">
            <!-- Dynamically populated -->
          </div>

          <div class="flex items-center space-x-2">
            <button onclick="promptNewFolder()" class="px-3 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition shadow-2xs inline-flex items-center space-x-1.5">
              <i class="fa-solid fa-folder-plus text-amber-500"></i>
              <span>New folder</span>
            </button>
            <button onclick="document.getElementById('cloud-file-input').click()" class="px-3 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition shadow-xs inline-flex items-center space-x-1.5">
              <i class="fa-solid fa-cloud-arrow-up"></i>
              <span>Upload</span>
            </button>
          </div>
        </div>

        <!-- Files & Folders Container -->
        <div id="file-explorer-content">
          <!-- Dynamically populated -->
        </div>
      </section>

    </main>
  </div>

  <!-- FLOATING UPLOAD PROGRESS DOCK (BOTTOM RIGHT) -->
  <div id="upload-dock" class="fixed bottom-6 right-6 z-50 w-80 max-w-[90vw] hidden transition-all">
    <div class="bg-white border border-slate-200/90 rounded-2xl shadow-xl p-3 backdrop-blur space-y-2">
      <div class="flex items-center justify-between border-b border-slate-100 pb-2">
        <span class="text-xs font-bold text-slate-800 flex items-center">
          <i class="fa-solid fa-circle-arrow-up text-blue-500 mr-1.5"></i> Upload Activity
        </span>
        <button onclick="document.getElementById('upload-dock').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xs">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div id="upload-dock-items" class="space-y-2 max-h-60 overflow-y-auto">
        <!-- Dynamically added upload items -->
      </div>
    </div>
  </div>

  <!-- CONTEXT MENU (RIGHT CLICK OR 3-DOTS) -->
  <div id="context-menu" class="context-menu hidden">
    <div onclick="ctxOpen()" class="context-menu-item">
      <i class="fa-solid fa-arrow-up-right-from-square text-blue-500"></i>
      <span>Open / Preview</span>
    </div>
    <div onclick="ctxShare()" class="context-menu-item ctx-file-only">
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
        <p class="text-xs text-slate-400 mt-1">Multi-Account Cloud Storage Platform</p>
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
        <h3 class="text-base font-bold text-slate-800 flex items-center">
          <i class="fa-solid fa-share-nodes text-blue-500 mr-2"></i> Share File
        </h3>
        <button onclick="document.getElementById('share-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="flex items-center justify-between p-3.5 bg-slate-50 rounded-2xl border border-slate-200">
        <div>
          <div class="text-xs font-semibold text-slate-800">Public Link Access</div>
          <div class="text-[11px] text-slate-400">Anyone with this link can view and download</div>
        </div>
        <label class="relative inline-flex items-center cursor-pointer">
          <input type="checkbox" id="share-public-toggle" onchange="handleShareToggle()" class="sr-only peer">
          <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:width-5 after:transition-all peer-checked:bg-blue-600"></div>
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
    <!-- Modal Header -->
    <div class="flex items-center justify-between pb-4 max-w-6xl w-full mx-auto">
      <h3 id="preview-modal-title" class="text-base font-bold text-white truncate max-w-lg">File Preview</h3>
      <button onclick="closePreviewModal()" class="w-9 h-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition">
        <i class="fa-solid fa-xmark text-lg"></i>
      </button>
    </div>

    <!-- Modal Dynamic Content Container -->
    <div id="preview-modal-content" class="flex-1 flex items-center justify-center max-w-6xl w-full mx-auto overflow-hidden">
      <!-- Injected Video Player, Image, PDF, or Code Viewer -->
    </div>
  </div>

  <!-- P2P OFFLINE SHARE MODAL (SHAREIT / SNAPDROP ARCHITECTURE) -->
  <div id="p2p-share-modal" class="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-3 sm:p-6 z-50 hidden">
    <div class="bg-white rounded-3xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden max-h-[92vh] border border-slate-200/90 animate-in fade-in zoom-in-95">
      
      <!-- Modal Header -->
      <div class="px-6 py-4 bg-slate-900 text-white flex items-center justify-between border-b border-slate-800">
        <div class="flex items-center space-x-3">
          <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-emerald-500 to-cyan-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/25">
            <i class="fa-solid fa-bolt text-lg text-white"></i>
          </div>
          <div>
            <div class="flex items-center space-x-2">
              <h3 class="font-bold text-base tracking-tight text-white">ShareIt P2P File Transfer</h3>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase tracking-wider">
                30–80+ MB/s
              </span>
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase tracking-wider">
                Zero Data
              </span>
            </div>
            <p class="text-xs text-slate-400">
              Auto-Discovery Radar • Direct Socket Stream • Zero Internet Required
            </p>
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

        <div class="flex items-center space-x-2 pb-2">
          <span class="text-[11px] text-slate-400 font-medium">Radar:</span>
          <span id="p2p-radar-count-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">
            Searching...
          </span>
        </div>
      </div>

      <!-- Modal Body -->
      <div class="flex-1 overflow-y-auto p-6 bg-slate-50/50">
        
        <!-- SECTION 1: SEND FILES -->
        <div id="p2p-send-section" class="space-y-6">
          <input type="file" id="p2p-send-file-input" class="hidden" multiple>

          <!-- Dropzone -->
          <div id="p2p-send-dropzone" onclick="document.getElementById('p2p-send-file-input').click()"
               class="border-2 border-dashed border-slate-300 hover:border-emerald-500 hover:bg-emerald-50/20 rounded-3xl p-10 flex flex-col items-center justify-center text-center transition cursor-pointer bg-white">
            <div class="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4 shadow-inner text-2xl">
              <i class="fa-solid fa-cloud-arrow-up"></i>
            </div>
            <h4 class="font-bold text-slate-800 text-base mb-1">Select 4K Videos or Large Files to Stream</h4>
            <p class="text-xs text-slate-500 max-w-sm mb-4">
              Send raw videos, zip archives, or photo galleries directly to any nearby phone or PC without internet.
            </p>
            <span class="px-4 py-2 bg-emerald-600 text-white font-medium text-xs rounded-xl shadow-md shadow-emerald-500/25">
              Browse Local Files
            </span>
          </div>

          <!-- Active Hosting Card -->
          <div id="p2p-send-active-card" class="hidden grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
            <!-- Left: QR Code to Scan -->
            <div class="lg:col-span-6 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-3">
              <div class="flex items-center justify-between w-full">
                <span class="font-bold text-xs text-slate-800 flex items-center space-x-1.5">
                  <i class="fa-solid fa-qrcode text-emerald-600"></i>
                  <span>Scan to Receive on Phone</span>
                </span>
                <span id="p2p-send-code-badge" class="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold bg-slate-100 text-slate-700">
                  CODE: ------
                </span>
              </div>

              <!-- Canvas QR -->
              <div class="p-3 bg-white rounded-2xl border-2 border-slate-900 shadow-lg">
                <canvas id="p2p-send-qr-canvas" class="w-[210px] h-[210px] object-contain rounded-lg"></canvas>
              </div>

              <p class="text-[11px] text-slate-500 text-center">
                Point any phone camera at this QR code to download instantly over local high-speed link.
              </p>

              <!-- Join Link -->
              <div class="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                <div class="truncate mr-2">
                  <span class="text-[10px] text-slate-400 block">Download URL</span>
                  <input type="text" id="p2p-send-join-link" readonly class="w-full font-mono font-bold text-slate-700 text-[11px] bg-transparent outline-none truncate">
                </div>
                <button onclick="copyP2PJoinLink()" class="p-2 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 transition" title="Copy Link">
                  <i class="fa-solid fa-copy"></i>
                </button>
              </div>
            </div>

            <!-- Right: File Info & Nearby Radar -->
            <div class="lg:col-span-6 space-y-4">
              <!-- File Details Card -->
              <div class="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3">
                <div class="flex items-center justify-between">
                  <div class="flex items-center space-x-2.5">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold text-base">
                      <i class="fa-regular fa-file"></i>
                    </div>
                    <div>
                      <h5 id="p2p-send-file-name" class="font-bold text-slate-800 text-xs truncate max-w-[200px]">File</h5>
                      <span id="p2p-send-file-size" class="text-[10px] text-slate-400 font-mono">0 MB</span>
                    </div>
                  </div>
                  <button onclick="resetP2PSender()" class="text-[11px] text-blue-600 hover:underline font-semibold">
                    Change File
                  </button>
                </div>

                <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                  <span class="text-slate-400">Stream Status:</span>
                  <span id="p2p-send-upload-status" class="text-emerald-600 font-bold text-[11px]">Stream Live!</span>
                </div>
              </div>

              <!-- Nearby Radar Peer List -->
              <div class="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-2.5">
                <div class="flex items-center justify-between text-xs">
                  <span class="font-bold text-slate-800 flex items-center space-x-1.5">
                    <i class="fa-solid fa-satellite-dish text-emerald-600"></i>
                    <span>Nearby Devices on Subnet</span>
                  </span>
                  <span class="text-[10px] text-slate-400 font-mono">Tap to Send</span>
                </div>
                <div id="p2p-radar-peer-list" class="space-y-2 max-h-48 overflow-y-auto pr-1"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- SECTION 2: RECEIVE FILES -->
        <div id="p2p-receive-section" class="hidden space-y-6">
          <!-- Pending Incoming Session -->
          <div id="p2p-receive-pending" class="p-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs space-y-4">
            <div class="flex items-center space-x-3.5">
              <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl">
                <i class="fa-solid fa-mobile-screen"></i>
              </div>
              <div>
                <h4 class="font-bold text-slate-800 text-sm">
                  Incoming File from <span id="p2p-receive-sender-name">Nearby Device</span>
                </h4>
                <p class="text-xs text-slate-500">
                  Ready to stream directly over local network at 30–80+ MB/s
                </p>
              </div>
            </div>

            <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-between">
              <div>
                <span id="p2p-receive-file-name" class="font-bold text-xs text-slate-800 block truncate max-w-sm">File Name</span>
                <span id="p2p-receive-file-size" class="text-[10px] text-slate-400 font-mono">0 MB</span>
              </div>
              <button onclick="startP2PDownload()" class="px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-500/25 flex items-center space-x-2 transition">
                <i class="fa-solid fa-bolt"></i>
                <span>Accept & Download Now</span>
              </button>
            </div>
          </div>

          <!-- Active Download Speedometer Card -->
          <div id="p2p-receive-active" class="hidden p-6 bg-slate-900 text-white rounded-3xl shadow-xl space-y-4">
            <div class="flex items-center justify-between">
              <div>
                <span class="text-[10px] font-bold text-emerald-400 uppercase tracking-wider block">
                  High-Speed Socket Streaming
                </span>
                <h4 class="font-bold text-base text-white">Receiving File Chunks...</h4>
              </div>
              <div class="text-right">
                <span id="p2p-receive-speed" class="text-2xl font-black font-mono text-emerald-400">0.0 MB/s</span>
                <span id="p2p-receive-eta" class="text-[10px] text-slate-400 block font-mono">ETA: --</span>
              </div>
            </div>

            <div class="w-full bg-slate-800 rounded-full h-3 overflow-hidden p-0.5 border border-slate-700">
              <div id="p2p-receive-bar" class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-cyan-400 transition-all duration-150" style="width: 0%"></div>
            </div>

            <div class="flex justify-between items-center text-xs text-slate-400 font-mono">
              <span id="p2p-receive-bytes">0 B / 0 B</span>
              <span id="p2p-receive-pct">0%</span>
            </div>
          </div>

          <!-- Done Card -->
          <div id="p2p-receive-done" class="hidden p-6 bg-gradient-to-tr from-emerald-50 to-teal-50 border-2 border-emerald-400 rounded-2xl shadow-lg space-y-2 text-center">
            <div class="w-12 h-12 mx-auto rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xl">
              <i class="fa-solid fa-check"></i>
            </div>
            <h4 class="font-bold text-emerald-900 text-base">File Download Complete!</h4>
            <p class="text-xs text-emerald-700">The file has been saved to your downloads folder at maximum speed.</p>
          </div>

          <!-- Manual Code Entry Box -->
          <div class="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-2">
            <label class="text-xs font-semibold text-slate-700 block">Have a 6-digit Code from Sender?</label>
            <div class="flex items-center space-x-2">
              <input type="text" id="p2p-manual-code-input" placeholder="e.g. 748291" class="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono uppercase tracking-wider outline-none focus:border-emerald-500">
              <button onclick="handleP2PManualJoin()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold text-xs rounded-xl transition">
                Connect
              </button>
            </div>
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="px-6 py-3 bg-white border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
        <div class="flex items-center space-x-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
          <span class="text-[11px]">Direct P2P Link • No Cloud Relay • 100% Offline Compatible</span>
        </div>
        <button onclick="closeP2PShareModal()" class="px-4 py-1.5 text-xs font-semibold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-xl transition">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- QR Code Renderer Library (Offline Local) -->
  <script src="assets/js/qrcode.min.js"></script>
  <!-- Application & P2P Logic -->
  <script src="assets/js/app.js"></script>
  <script src="assets/js/p2p.js"></script>
</body>
</html>
