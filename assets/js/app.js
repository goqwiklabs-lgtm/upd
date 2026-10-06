// ========================================================
// CloudDrive Main Application Controller
// ========================================================

const state = {
  user: null,
  currentFolderId: null,
  breadcrumbs: [],
  folders: [],
  files: [],
  totalUserBytes: 0,
  uploadedCount: 0,
  uploadQueue: [],
  activeUploads: 0,
  viewMode: 'grid', // 'grid' | 'list'
  searchQuery: '',
};

// --- INITIALIZATION ---
document.addEventListener('DOMContentLoaded', () => {
  checkAuth();
  setupEventListeners();
});

// --- AUTHENTICATION ---
async function checkAuth() {
  try {
    const res = await fetch('api/auth.php?action=me');
    const data = await res.json();
    if (data.authenticated) {
      state.user = data.user;
      renderApp();
      loadFiles(null);
    } else {
      showAuthModal('login');
    }
  } catch (err) {
    console.error('Auth check error:', err);
    showAuthModal('login');
  }
}

function showAuthModal(mode = 'login') {
  const modal = document.getElementById('auth-modal');
  modal.classList.remove('hidden');
  switchAuthTab(mode);
}

function hideAuthModal() {
  document.getElementById('auth-modal').classList.add('hidden');
}

function switchAuthTab(tab) {
  const loginForm = document.getElementById('login-form');
  const registerForm = document.getElementById('register-form');
  const tabLogin = document.getElementById('tab-login');
  const tabRegister = document.getElementById('tab-register');

  if (tab === 'login') {
    loginForm.classList.remove('hidden');
    registerForm.classList.add('hidden');
    tabLogin.classList.add('border-blue-600', 'text-blue-600');
    tabLogin.classList.remove('text-slate-400', 'border-transparent');
    tabRegister.classList.remove('border-blue-600', 'text-blue-600');
    tabRegister.classList.add('text-slate-400', 'border-transparent');
  } else {
    loginForm.classList.add('hidden');
    registerForm.classList.remove('hidden');
    tabRegister.classList.add('border-blue-600', 'text-blue-600');
    tabRegister.classList.remove('text-slate-400', 'border-transparent');
    tabLogin.classList.remove('border-blue-600', 'text-blue-600');
    tabLogin.classList.add('text-slate-400', 'border-transparent');
  }
}

async function handleLogin(e) {
  e.preventDefault();
  const form = e.target;
  const identifier = form.identifier.value;
  const password = form.password.value;
  const errorEl = document.getElementById('auth-error');
  errorEl.classList.add('hidden');

  try {
    const res = await fetch('api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ identifier, password }),
    });
    const data = await res.json();
    if (data.success) {
      state.user = data.user;
      hideAuthModal();
      renderApp();
      loadFiles(null);
    } else {
      errorEl.textContent = data.error || 'Login failed';
      errorEl.classList.remove('hidden');
    }
  } catch (err) {
    errorEl.textContent = 'Server error during login.';
    errorEl.classList.remove('hidden');
  }
}

async function handleRegister(e) {
  e.preventDefault();
  const form = e.target;
  const username = form.username.value;
  const email = form.email.value;
  const password = form.password.value;
  const errorEl = document.getElementById('auth-error');
  errorEl.classList.add('hidden');

  try {
    const res = await fetch('api/auth.php?action=register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, email, password }),
    });
    const data = await res.json();
    if (data.success) {
      state.user = data.user;
      hideAuthModal();
      renderApp();
      loadFiles(null);
    } else {
      errorEl.textContent = data.error || 'Registration failed';
      errorEl.classList.remove('hidden');
    }
  } catch (err) {
    errorEl.textContent = 'Server error during registration.';
    errorEl.classList.remove('hidden');
  }
}

async function handleLogout() {
  await fetch('api/auth.php?action=logout');
  state.user = null;
  location.reload();
}

// --- RENDER APPLICATION SHELL ---
function renderApp() {
  document.getElementById('user-name-display').textContent = state.user.username;
  document.getElementById('user-email-display').textContent = state.user.email;
  const adminBtn = document.getElementById('admin-panel-btn');
  if (state.user.role === 'admin') {
    adminBtn.classList.remove('hidden');
  } else {
    adminBtn.classList.add('hidden');
  }
}

// --- FILE & FOLDER FETCHING ---
async function loadFiles(folderId = null) {
  state.currentFolderId = folderId;
  const container = document.getElementById('file-explorer-content');
  container.innerHTML = `<div class="p-12 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><p>Loading files...</p></div>`;

  try {
    const query = folderId !== null ? `?action=list&folder_id=${folderId}` : '?action=list';
    const res = await fetch(`api/files.php${query}`);
    const data = await res.json();

    if (data.success) {
      state.folders = data.folders || [];
      state.files = data.files || [];
      state.breadcrumbs = data.breadcrumbs || [];
      state.totalUserBytes = data.total_user_bytes || 0;
      updateStorageDisplay();
      renderBreadcrumbs();
      renderFileExplorer();
    }
  } catch (err) {
    console.error('Error loading files:', err);
    container.innerHTML = `<div class="p-8 text-center text-red-500">Failed to load files.</div>`;
  }
}

function updateStorageDisplay() {
  const mb = (state.totalUserBytes / (1024 * 1024)).toFixed(1);
  const gb = (state.totalUserBytes / (1024 * 1024 * 1024)).toFixed(2);
  const display = state.totalUserBytes > 1024 * 1024 * 1024 ? `${gb} GB` : `${mb} MB`;
  const el = document.getElementById('user-storage-text');
  if (el) el.textContent = `${display} used`;
}

function renderBreadcrumbs() {
  const nav = document.getElementById('breadcrumbs-bar');
  let html = `
    <button onclick="loadFiles(null)" class="hover:text-blue-600 transition flex items-center font-medium">
      <i class="fa-solid fa-house mr-1.5 text-xs"></i> My Drive
    </button>
  `;

  state.breadcrumbs.forEach((b, idx) => {
    const isLast = idx === state.breadcrumbs.length - 1;
    html += `
      <i class="fa-solid fa-chevron-right text-xs text-slate-300 mx-2"></i>
      <button onclick="loadFiles(${b.id})" class="${isLast ? 'text-slate-900 font-semibold' : 'hover:text-blue-600'} transition">
        ${escapeHtml(b.name)}
      </button>
    `;
  });

  nav.innerHTML = html;
}

function renderFileExplorer() {
  const container = document.getElementById('file-explorer-content');
  
  // Filter by search query if any
  let filteredFolders = state.folders;
  let filteredFiles = state.files;
  if (state.searchQuery.trim()) {
    const q = state.searchQuery.toLowerCase();
    filteredFolders = filteredFolders.filter(f => f.name.toLowerCase().includes(q));
    filteredFiles = filteredFiles.filter(f => f.name.toLowerCase().includes(q));
  }

  if (filteredFolders.length === 0 && filteredFiles.length === 0) {
    container.innerHTML = `
      <div class="py-16 text-center text-slate-400">
        <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-2xl">
          <i class="fa-regular fa-folder-open"></i>
        </div>
        <p class="font-medium text-slate-600">No files or folders here</p>
        <p class="text-sm text-slate-400 mt-1">Take the shot above to upload files or create a new folder!</p>
      </div>
    `;
    return;
  }

  let html = '';

  // Folders section
  if (filteredFolders.length > 0) {
    html += `
      <div class="mb-6">
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Folders</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
          ${filteredFolders.map(f => `
            <div ondblclick="loadFiles(${f.id})" onclick="selectItem(this)" class="group bg-white hover:bg-blue-50/50 border border-slate-200/80 hover:border-blue-300 rounded-xl p-3 flex items-center justify-between cursor-pointer transition shadow-sm hover:shadow">
              <div class="flex items-center space-x-2.5 truncate">
                <i class="fa-solid fa-folder text-amber-400 text-lg"></i>
                <span class="text-sm font-medium text-slate-700 group-hover:text-blue-600 truncate">${escapeHtml(f.name)}</span>
              </div>
              <button onclick="showContextMenu(event, 'folder', ${f.id})" class="text-slate-400 hover:text-slate-600 p-1 opacity-0 group-hover:opacity-100 transition">
                <i class="fa-solid fa-ellipsis-vertical"></i>
              </button>
            </div>
          `).join('')}
        </div>
      </div>
    `;
  }

  // Files section
  if (filteredFiles.length > 0) {
    html += `
      <div>
        <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Files</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
          ${filteredFiles.map(file => {
            const iconInfo = getFileIcon(file.name, file.mime_type);
            const sizeStr = formatBytes(file.size_bytes);
            return `
              <div ondblclick="openFilePreview(${file.id})" class="group bg-white hover:bg-slate-50 border border-slate-200 rounded-2xl p-4 flex flex-col justify-between cursor-pointer transition shadow-sm hover:shadow-md relative">
                <div class="flex items-start justify-between mb-3">
                  <div class="w-10 h-10 rounded-xl ${iconInfo.bg} ${iconInfo.color} flex items-center justify-center text-xl">
                    <i class="${iconInfo.icon}"></i>
                  </div>
                  <button onclick="showContextMenu(event, 'file', ${file.id})" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition">
                    <i class="fa-solid fa-ellipsis-vertical"></i>
                  </button>
                </div>
                <div>
                  <h4 class="text-sm font-medium text-slate-800 truncate mb-1" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</h4>
                  <div class="flex items-center justify-between text-xs text-slate-400">
                    <span>${sizeStr}</span>
                    ${file.is_public ? '<span class="text-emerald-600 font-medium flex items-center"><i class="fa-solid fa-globe mr-1 text-[10px]"></i> Public</span>' : ''}
                  </div>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    `;
  }

  container.innerHTML = html;
}

// --- BASKETBALL HOOP DROPZONE & SHOT ANIMATION ---
function setupEventListeners() {
  const dropzone = document.getElementById('shot-dropzone');
  const fileInput = document.getElementById('cloud-file-input');

  // Drag and Drop
  ['dragenter', 'dragover'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
      e.preventDefault();
      dropzone.classList.add('drag-active');
    }, false);
  });

  ['dragleave', 'drop'].forEach(eventName => {
    dropzone.addEventListener(eventName, (e) => {
      e.preventDefault();
      dropzone.classList.remove('drag-active');
    }, false);
  });

  dropzone.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    const files = dt.files;
    if (files && files.length > 0) {
      handleIncomingFiles(Array.from(files));
    }
  });

  fileInput.addEventListener('change', (e) => {
    if (e.target.files && e.target.files.length > 0) {
      handleIncomingFiles(Array.from(e.target.files));
      e.target.value = ''; // reset
    }
  });

  // Search input
  const searchInput = document.getElementById('search-input');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      state.searchQuery = e.target.value;
      renderFileExplorer();
    });
  }

  // Close context menu on window click or touch outside
  ['click', 'touchstart'].forEach(evt => {
    window.addEventListener(evt, (e) => {
      const cm = document.getElementById('context-menu');
      if (cm && !cm.contains(e.target) && !e.target.closest('button')) {
        hideContextMenu();
      }
    });
  });
}

/**
 * Executes the Parabolic Arc Shot into the Basketball Hoop!
 * Draws the dotted trajectory trail and swooshes the file into the net.
 */
function animateHoopShot(fileName, callback) {
  const container = document.getElementById('shot-dropzone');
  const rect = container.getBoundingClientRect();
  const hoop = document.querySelector('.hoop-container');

  // Start position: bottom-left of the dropzone
  const startX = 60;
  const startY = rect.height - 90;
  
  // Target position: center of the hoop rim
  const targetX = rect.width / 2;
  const targetY = 120;
  const arcHeight = 160;

  // Create trajectory dots container
  const dotsContainer = document.createElement('div');
  dotsContainer.className = 'absolute inset-0 pointer-events-none';
  container.appendChild(dotsContainer);

  // Generate 9 parabolic trajectory dots
  const totalDots = 9;
  const dots = [];
  for (let i = 1; i <= totalDots; i++) {
    const t = i / (totalDots + 1);
    const dotX = startX + (targetX - startX) * t;
    const dotY = startY + (targetY - startY) * t - Math.sin(t * Math.PI) * arcHeight;

    const dot = document.createElement('div');
    dot.className = 'absolute w-2 h-2 rounded-full bg-rose-400/80 transition-all duration-300';
    dot.style.left = `${dotX}px`;
    dot.style.top = `${dotY}px`;
    dot.style.opacity = '0';
    dot.style.transform = 'scale(0.5)';
    dotsContainer.appendChild(dot);
    dots.push(dot);
  }

  // Create flight icon element
  const icon = document.createElement('div');
  icon.className = 'flight-icon flex flex-col items-center justify-center p-2 bg-white border border-slate-200 rounded-xl shadow-xl';
  icon.style.width = '48px';
  icon.style.height = '56px';
  icon.innerHTML = `
    <i class="fa-solid fa-file-pdf text-red-500 text-xl mb-0.5"></i>
    <span class="text-[8px] font-bold text-slate-700 truncate max-w-[38px]">${escapeHtml(fileName.slice(0, 8))}</span>
  `;

  icon.style.left = `${startX - 24}px`;
  icon.style.top = `${startY - 28}px`;
  container.appendChild(icon);

  // Animate arc using requestAnimationFrame
  const duration = 850; // ms
  const startTime = performance.now();

  function step(currentTime) {
    const elapsed = currentTime - startTime;
    const progress = Math.min(elapsed / duration, 1);

    // Light up trajectory dots sequentially
    dots.forEach((dot, idx) => {
      const dotThreshold = (idx + 1) / (totalDots + 1);
      if (progress >= dotThreshold * 0.75) {
        dot.style.opacity = '0.9';
        dot.style.transform = 'scale(1)';
      }
    });

    const currentX = startX + (targetX - startX) * progress;
    const currentY = startY + (targetY - startY) * progress - Math.sin(progress * Math.PI) * arcHeight;

    icon.style.left = `${currentX - 24}px`;
    icon.style.top = `${currentY - 28}px`;
    icon.style.transform = `scale(${1 - progress * 0.25}) rotate(${progress * 20}deg)`;

    if (progress < 1) {
      requestAnimationFrame(step);
    } else {
      // Swish through the net!
      icon.remove();
      triggerSwishEffect();

      // Fade out dots
      setTimeout(() => {
        dotsContainer.style.transition = 'opacity 0.4s';
        dotsContainer.style.opacity = '0';
        setTimeout(() => dotsContainer.remove(), 400);
      }, 300);

      if (callback) callback();
    }
  }

  requestAnimationFrame(step);
}

function triggerSwishEffect() {
  const net = document.querySelector('.hoop-net');
  const scorePop = document.querySelector('.score-pop');
  const counter = document.getElementById('uploaded-counter-badge');

  // Net swish animation
  net.classList.remove('net-swish');
  void net.offsetWidth; // trigger reflow
  net.classList.add('net-swish');

  // +1 Score Pop
  scorePop.classList.remove('active');
  void scorePop.offsetWidth;
  scorePop.classList.add('active');

  // Increment counter
  state.uploadedCount++;
  counter.textContent = `Uploaded ${state.uploadedCount}`;
}

// --- FILE UPLOAD PROCESSING (DIRECT TO GOOGLE DRIVE) ---
async function handleIncomingFiles(fileList) {
  for (const file of fileList) {
    // 1. Play Basketball Arc Shot Animation
    animateHoopShot(file.name, () => {
      // Start upload pipeline
      startDirectUpload(file);
    });
  }
}

/**
 * Direct Browser-to-Google Drive Chunked Resumable Upload
 * Completely bypasses InfinityFree limits and works in background on mobile!
 */
async function startDirectUpload(file) {
  const uploadId = 'upload_' + Math.random().toString(36).substr(2, 9);
  
  // Render upload card in bottom dock
  createUploadDockItem(uploadId, file);

  try {
    // Step 1: Request Google Resumable Upload Session URI from PHP backend
    const initRes = await fetch('api/upload_init.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: file.name,
        size: file.size,
        mimeType: file.type || 'application/octet-stream',
        folder_id: state.currentFolderId,
        origin: window.location.origin,
      }),
    });

    const initData = await initRes.json();
    if (!initData.success) {
      throw new Error(initData.error || 'Failed to start upload session');
    }

    const uploadUrl = initData.upload_url;
    const googleAccountId = initData.google_account_id;
    const shareToken = initData.share_token;

    // Step 2: Stream file in chunks directly or via relay
    const CHUNK_SIZE = 2 * 1024 * 1024;
    let start = 0;
    const total = file.size;

    while (start < total) {
      const end = Math.min(start + CHUNK_SIZE, total);
      const chunk = file.slice(start, end);
      const contentRange = `bytes ${start}-${end - 1}/${total}`;

      let response;
      try {
        // Attempt 1: Direct browser-to-Google
        response = await fetch(uploadUrl, {
          method: 'PUT',
          headers: {
            'Content-Range': contentRange,
          },
          body: chunk,
        });
      } catch (corsErr) {
        // Attempt 2: Relay chunk through server if browser blocks CORS
        const relayUrl = `api/upload_chunk.php?upload_url=${encodeURIComponent(uploadUrl)}`;
        response = await fetch(relayUrl, {
          method: 'POST',
          headers: {
            'Content-Range': contentRange,
          },
          body: chunk,
        });
      }

      // Update progress
      start = end;
      const percent = Math.round((start / total) * 100);
      updateUploadDockProgress(uploadId, percent, 'Uploading...');

      // Google returns 200 or 201 when upload is completely finished
      if (response.status === 200 || response.status === 201) {
        const googleFileInfo = await response.json();
        const googleFileId = googleFileInfo.id;

        // Step 3: Notify PHP to record file in database and update Google Account storage quota
        await fetch('api/upload_finish.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            google_account_id: googleAccountId,
            google_file_id: googleFileId,
            name: file.name,
            size: file.size,
            mime_type: file.type || 'application/octet-stream',
            folder_id: state.currentFolderId,
            share_token: shareToken,
          }),
        });

        // Mark as uploaded
        finishUploadDockItem(uploadId);
        loadFiles(state.currentFolderId);
        break;
      }
    }
  } catch (err) {
    console.error('Upload error:', err);
    markUploadDockError(uploadId, err.message);
  }
}

// --- FLOATING UPLOAD PROGRESS DOCK ---
function createUploadDockItem(id, file) {
  const dock = document.getElementById('upload-dock');
  const itemsContainer = document.getElementById('upload-dock-items');
  dock.classList.remove('hidden');

  const item = document.createElement('div');
  item.id = id;
  item.className = 'bg-white border border-slate-200 rounded-xl p-3 shadow-sm flex flex-col space-y-2';
  item.innerHTML = `
    <div class="flex items-center justify-between text-xs">
      <div class="flex items-center space-x-2 truncate max-w-[200px]">
        <i class="fa-regular fa-file text-blue-500"></i>
        <span class="font-medium text-slate-800 truncate">${escapeHtml(file.name)}</span>
      </div>
      <span class="text-slate-400">${formatBytes(file.size)}</span>
    </div>
    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
      <div class="upload-bar bg-blue-600 h-1.5 rounded-full transition-all duration-200" style="width: 0%"></div>
    </div>
    <div class="flex items-center justify-between text-[11px] text-slate-500">
      <span class="upload-status">Connecting...</span>
      <span class="upload-percent font-medium text-blue-600">0%</span>
    </div>
  `;
  itemsContainer.appendChild(item);
}

function updateUploadDockProgress(id, percent, status) {
  const item = document.getElementById(id);
  if (!item) return;
  item.querySelector('.upload-bar').style.width = `${percent}%`;
  item.querySelector('.upload-percent').textContent = `${percent}%`;
  item.querySelector('.upload-status').textContent = status;
}

function finishUploadDockItem(id) {
  const item = document.getElementById(id);
  if (!item) return;
  item.querySelector('.upload-bar').style.width = '100%';
  item.querySelector('.upload-bar').className = 'upload-bar bg-emerald-500 h-1.5 rounded-full';
  item.querySelector('.upload-percent').innerHTML = '<i class="fa-solid fa-check text-emerald-600"></i>';
  item.querySelector('.upload-status').innerHTML = '<span class="text-emerald-600 font-semibold">Uploaded ✓</span>';

  setTimeout(() => {
    item.classList.add('opacity-0', 'transition', 'duration-500');
    setTimeout(() => {
      item.remove();
      const itemsContainer = document.getElementById('upload-dock-items');
      if (itemsContainer.children.length === 0) {
        document.getElementById('upload-dock').classList.add('hidden');
      }
    }, 500);
  }, 3500);
}

function markUploadDockError(id, msg) {
  const item = document.getElementById(id);
  if (!item) return;
  item.querySelector('.upload-bar').className = 'upload-bar bg-red-500 h-1.5 rounded-full';
  item.querySelector('.upload-status').innerHTML = `<span class="text-red-500 font-semibold truncate max-w-[180px]">${escapeHtml(msg)}</span>`;
}

// --- CONTEXT MENU (DELETE, RENAME, MOVE, COPY, SHARE) ---
let currentContextItem = null;

function hideContextMenu() {
  const menu = document.getElementById('context-menu');
  if (menu) menu.classList.add('hidden');
  currentContextItem = null;
}

function showContextMenu(e, type, id) {
  e.stopPropagation();
  e.preventDefault();

  let item = null;
  if (type === 'folder') {
    const folder = state.folders.find(f => f.id === id);
    if (!folder) return;
    item = { type: 'folder', id: folder.id, name: folder.name };
  } else {
    const file = state.files.find(f => f.id === id);
    if (!file) return;
    item = {
      type: 'file',
      id: file.id,
      name: file.name,
      shareToken: file.share_token,
      isPublic: file.is_public,
    };
  }

  currentContextItem = item;
  const menu = document.getElementById('context-menu');
  if (!menu) return;

  // Position menu safely at click coordinates
  const menuWidth = 190;
  const menuHeight = 240;
  const x = Math.min(e.clientX, window.innerWidth - menuWidth - 10);
  const y = Math.min(e.clientY, window.innerHeight - menuHeight - 10);

  menu.style.left = `${Math.max(10, x)}px`;
  menu.style.top = `${Math.max(10, y)}px`;
  menu.classList.remove('hidden');

  // Toggle file-specific actions
  const fileActions = document.querySelectorAll('.ctx-file-only');
  fileActions.forEach(el => {
    if (type === 'file') el.classList.remove('hidden');
    else el.classList.add('hidden');
  });
}

async function ctxOpen() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();
  if (target.type === 'folder') {
    loadFiles(target.id);
  } else {
    openFilePreview(target.id);
  }
}

function ctxRename() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();
  const newName = prompt('Enter new name:', target.name);
  if (newName && newName.trim() && newName !== target.name) {
    fetch('api/files.php?action=rename', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        type: target.type,
        id: target.id,
        new_name: newName.trim(),
      }),
    }).then(() => loadFiles(state.currentFolderId));
  }
}

async function ctxDelete() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();

  if (!confirm(`Are you sure you want to delete "${target.name}"?`)) return;

  // Optimistic UI update: immediately remove from screen so user sees instant deletion
  if (target.type === 'file') {
    state.files = state.files.filter(f => f.id !== target.id);
  } else {
    state.folders = state.folders.filter(f => f.id !== target.id);
  }
  renderFileExplorer();

  try {
    const res = await fetch('api/files.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        type: target.type,
        id: target.id,
      }),
    });
    const data = await res.json();
    if (!data.success) {
      alert(data.error || 'Failed to delete file');
    }
  } catch (err) {
    console.error('Delete error:', err);
  } finally {
    loadFiles(state.currentFolderId);
  }
}

async function ctxCopy() {
  if (!currentContextItem || currentContextItem.type !== 'file') return;
  const target = { ...currentContextItem };
  hideContextMenu();
  await fetch('api/files.php?action=copy', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file_id: target.id }),
  });
  loadFiles(state.currentFolderId);
}

function ctxShare() {
  if (!currentContextItem || currentContextItem.type !== 'file') return;
  const target = { ...currentContextItem };
  hideContextMenu();
  const modal = document.getElementById('share-modal');
  const linkInput = document.getElementById('share-link-input');
  const toggle = document.getElementById('share-public-toggle');
  
  const shareUrl = `${window.location.origin}/share.php?token=${target.shareToken}`;
  linkInput.value = shareUrl;
  toggle.checked = !!target.isPublic;

  currentContextItem = target; // preserve for handleShareToggle
  modal.classList.remove('hidden');
}

async function handleShareToggle() {
  if (!currentContextItem) return;
  const toggle = document.getElementById('share-public-toggle');
  const isPublic = toggle.checked ? 1 : 0;

  await fetch('api/files.php?action=toggle_share', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      file_id: currentContextItem.id,
      is_public: isPublic,
    }),
  });
  currentContextItem.isPublic = isPublic;
  loadFiles(state.currentFolderId);
}

function copyShareLink() {
  const linkInput = document.getElementById('share-link-input');
  linkInput.select();
  navigator.clipboard.writeText(linkInput.value);
  alert('Share link copied to clipboard!');
}

function ctxDownload() {
  if (!currentContextItem || currentContextItem.type !== 'file') return;
  window.location.href = `stream.php?id=${currentContextItem.id}&download=1`;
}

// --- NEW FOLDER MODAL ---
function promptNewFolder() {
  const modal = document.getElementById('new-folder-modal');
  modal.classList.remove('hidden');
  document.getElementById('new-folder-name').value = '';
  document.getElementById('new-folder-name').focus();
}

async function handleCreateFolder(e) {
  e.preventDefault();
  const name = document.getElementById('new-folder-name').value.trim();
  if (!name) return;

  await fetch('api/files.php?action=create_folder', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      name,
      parent_id: state.currentFolderId,
    }),
  });

  document.getElementById('new-folder-modal').classList.add('hidden');
  loadFiles(state.currentFolderId);
}

// --- INTEGRATED IN-APP PREVIEWERS ---
function openFilePreview(fileId) {
  const file = state.files.find(f => f.id === fileId);
  if (!file) return;

  const modal = document.getElementById('preview-modal');
  const content = document.getElementById('preview-modal-content');
  const title = document.getElementById('preview-modal-title');
  title.textContent = file.name;

  const mime = file.mime_type || '';
  const streamUrl = `stream.php?id=${file.id}`;

  const ext = file.name.split('.').pop().toLowerCase();
  const videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
  const audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
  const codeExts  = ['txt', 'json', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'py', 'php', 'md', 'csv', 'xml', 'sql', 'sh', 'yaml', 'yml', 'c', 'cpp', 'java', 'log'];

  const isVideo = videoExts.includes(ext) || mime.startsWith('video/');
  const isImage = imageExts.includes(ext) || mime.startsWith('image/');
  const isAudio = audioExts.includes(ext) || mime.startsWith('audio/');
  const isPdf   = ext === 'pdf' || mime === 'application/pdf';
  const isHtml  = ['html', 'htm'].includes(ext);
  const isCode  = codeExts.includes(ext) || mime.startsWith('text/');

  if (isVideo) {
    content.innerHTML = `
      <div class="w-full max-w-4xl flex flex-col items-center">
        <div class="relative w-full rounded-2xl overflow-hidden shadow-2xl bg-black group">
          <video id="modal-video-player" controls autoplay class="w-full max-h-[75vh] bg-black" preload="auto">
            <source src="${streamUrl}&quality=auto" type="${mime}">
            Your browser does not support video playback.
          </video>

          <!-- YouTube-style Quality Selector -->
          <div class="absolute top-4 right-4 z-20">
            <div class="relative">
              <button type="button" id="modal-quality-btn" onclick="toggleModalQualityMenu()" class="px-3 py-1.5 bg-black/75 hover:bg-black/90 backdrop-blur-md text-white text-xs font-semibold rounded-xl border border-white/20 transition flex items-center space-x-1.5 shadow-lg">
                <i class="fa-solid fa-gear text-amber-400"></i>
                <span id="modal-quality-label">Auto</span>
              </button>
              <div id="modal-quality-menu" class="hidden absolute right-0 mt-2 w-40 bg-slate-900/95 backdrop-blur-md border border-slate-700/80 rounded-xl shadow-2xl p-1.5 text-xs text-slate-200 z-30">
                <div class="text-[10px] uppercase font-bold text-slate-400 px-2 py-1 border-b border-slate-800 flex items-center justify-between">
                  <span>Resolution</span>
                  <i class="fa-solid fa-sliders"></i>
                </div>
                <div id="modal-quality-options" class="mt-1 space-y-0.5 max-h-60 overflow-y-auto">
                  <div class="p-2 text-center text-slate-500">Loading...</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
    loadModalVideoQualities(file.id);
  } else if (isImage) {
    content.innerHTML = `
      <div class="flex items-center justify-center p-4">
        <img src="${streamUrl}" alt="${escapeHtml(file.name)}" class="max-h-[75vh] max-w-full rounded-2xl shadow-2xl object-contain">
      </div>
    `;
  } else if (isPdf) {
    content.innerHTML = `
      <iframe src="${streamUrl}" class="w-full h-[75vh] rounded-2xl border-0 shadow-2xl"></iframe>
    `;
  } else if (isAudio) {
    content.innerHTML = `
      <div class="p-12 text-center w-full max-w-md mx-auto space-y-4">
        <div class="w-24 h-24 mx-auto rounded-full bg-amber-100 text-amber-600 flex items-center justify-center text-4xl mb-4">
          <i class="fa-solid fa-music"></i>
        </div>
        <audio controls autoplay class="w-full">
          <source src="${streamUrl}" type="${mime}">
        </audio>
      </div>
    `;
  } else if (isHtml) {
    content.innerHTML = `
      <div class="w-full h-[75vh] bg-white rounded-2xl overflow-hidden shadow-2xl">
        <iframe src="${streamUrl}" class="w-full h-full border-0"></iframe>
      </div>
    `;
  } else if (isCode) {
    content.innerHTML = `<div class="p-8 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i> Loading code...</div>`;
    fetch(streamUrl)
      .then(r => r.text())
      .then(text => {
        content.innerHTML = `
          <div class="w-full max-h-[75vh] bg-slate-900 text-slate-100 p-6 rounded-2xl font-mono text-xs overflow-auto whitespace-pre-wrap shadow-2xl border border-slate-800">
            <div class="flex justify-end pb-3 mb-3 border-b border-slate-800">
              <button onclick="navigator.clipboard.writeText(this.closest('.bg-slate-900').querySelector('pre').innerText); alert('Copied code to clipboard!');" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
                <i class="fa-solid fa-copy mr-1"></i> Copy
              </button>
            </div>
            <pre class="m-0">${escapeHtml(text)}</pre>
          </div>
        `;
      });
  } else {
    content.innerHTML = `
      <div class="text-center p-12 space-y-3">
        <i class="fa-regular fa-file text-5xl text-slate-400 mb-4"></i>
        <p class="text-slate-600 font-semibold mb-2">${escapeHtml(file.name)}</p>
        <p class="text-xs text-slate-400 mb-4">No direct in-browser preview available for this format.</p>
        <a href="${streamUrl}&download=1" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-medium rounded-xl transition inline-flex items-center space-x-2">
          <i class="fa-solid fa-download"></i>
          <span>Download File</span>
        </a>
      </div>
    `;
  }

  modal.classList.remove('hidden');
}

function closePreviewModal() {
  const modal = document.getElementById('preview-modal');
  const content = document.getElementById('preview-modal-content');
  content.innerHTML = '';
  modal.classList.add('hidden');
}

// --- MODAL VIDEO QUALITY RESOLUTION SWITCHER ---
let currentModalQuality = 'auto';
let currentModalVideoFileId = null;

function loadModalVideoQualities(fileId) {
  currentModalVideoFileId = fileId;
  currentModalQuality = 'auto';
  fetch(`api/files.php?action=video_qualities&file_id=${fileId}`)
    .then(r => r.json())
    .then(data => {
      if (data.success && data.qualities) {
        renderModalQualityOptions(data.qualities);
      }
    })
    .catch(() => {});
}

function renderModalQualityOptions(qualities) {
  const container = document.getElementById('modal-quality-options');
  if (!container) return;

  container.innerHTML = qualities.map(q => `
    <button type="button" onclick="changeModalQuality('${q.value}', '${q.label}')" class="w-full text-left px-2.5 py-1.5 hover:bg-slate-800 rounded-lg flex items-center justify-between transition ${currentModalQuality === q.value ? 'text-blue-400 font-bold bg-blue-500/10' : 'text-slate-200'}">
      <span>${q.label}</span>
      ${currentModalQuality === q.value ? '<i class="fa-solid fa-check text-xs text-blue-400"></i>' : ''}
    </button>
  `).join('');
}

function toggleModalQualityMenu() {
  const menu = document.getElementById('modal-quality-menu');
  if (menu) menu.classList.toggle('hidden');
}

function changeModalQuality(quality, label) {
  currentModalQuality = quality;
  const labelEl = document.getElementById('modal-quality-label');
  if (labelEl) labelEl.textContent = label;

  toggleModalQualityMenu();

  const video = document.getElementById('modal-video-player');
  if (!video || !currentModalVideoFileId) return;

  const currentTime = video.currentTime;
  const isPaused = video.paused;

  video.src = `stream.php?id=${currentModalVideoFileId}&quality=${encodeURIComponent(quality)}`;
  video.load();

  video.onloadedmetadata = () => {
    video.currentTime = currentTime;
    if (!isPaused) {
      video.play();
    }
  };

  loadModalVideoQualities(currentModalVideoFileId);
}

document.addEventListener('click', (e) => {
  const btn = document.getElementById('modal-quality-btn');
  const menu = document.getElementById('modal-quality-menu');
  if (btn && menu && !btn.contains(e.target) && !menu.contains(e.target)) {
    menu.classList.add('hidden');
  }
});

// --- HELPER FUNCTIONS ---
function formatBytes(bytes) {
  if (bytes === 0) return '0 B';
  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function getFileIcon(filename, mime) {
  const ext = filename.split('.').pop().toLowerCase();
  if (['mp4', 'mkv', 'webm', 'mov', 'avi'].includes(ext) || (mime && mime.startsWith('video/'))) {
    return { icon: 'fa-regular fa-file-video', color: 'text-purple-600', bg: 'bg-purple-50' };
  }
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext) || (mime && mime.startsWith('image/'))) {
    return { icon: 'fa-regular fa-file-image', color: 'text-emerald-600', bg: 'bg-emerald-50' };
  }
  if (['mp3', 'wav', 'ogg', 'm4a'].includes(ext) || (mime && mime.startsWith('audio/'))) {
    return { icon: 'fa-regular fa-file-audio', color: 'text-amber-600', bg: 'bg-amber-50' };
  }
  if (ext === 'pdf' || mime === 'application/pdf') {
    return { icon: 'fa-regular fa-file-pdf', color: 'text-rose-600', bg: 'bg-rose-50' };
  }
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
    return { icon: 'fa-regular fa-file-zipper', color: 'text-orange-600', bg: 'bg-orange-50' };
  }
  if (['js', 'py', 'php', 'html', 'css', 'json', 'sql', 'sh'].includes(ext)) {
    return { icon: 'fa-regular fa-file-code', color: 'text-blue-600', bg: 'bg-blue-50' };
  }
  return { icon: 'fa-regular fa-file', color: 'text-slate-600', bg: 'bg-slate-50' };
}

// --- AIR-GAPPED OPTICAL TRANSFER (QR FILES) ---
function openQRFilesModal() {
  const modal = document.getElementById('qr-files-modal');
  const iframe = document.getElementById('qr-files-iframe');
  if (modal && iframe) {
    if (!iframe.src || iframe.src === 'about:blank' || !iframe.src.includes('index.html')) {
      iframe.src = '/dist/index.html#qr';
    } else {
      // Trigger hash refresh
      iframe.contentWindow?.postMessage({ action: 'open_qr' }, '*');
    }
    modal.classList.remove('hidden');
  }
}

function closeQRFilesModal() {
  const modal = document.getElementById('qr-files-modal');
  if (modal) {
    modal.classList.add('hidden');
  }
}

