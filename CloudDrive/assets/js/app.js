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
  currentView: 'drive', // 'drive' | 'temp' | 'shared'
  tempFiles: [],
  tempExpiryMinutes: 10,
  tempIsFolder: false,
  tempFolderName: '',
};

// --- CUSTOM IN-APP DIALOGS & NOTIFICATIONS (NO BROWSER POPUPS) ---
window.showInAppToast = function (message, type = 'info') {
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
      <span class="truncate">${escapeHtml(message)}</span>
    </div>
    <button onclick="this.parentElement.remove()" class="text-slate-400 hover:text-white p-0.5 ml-2 transition">
      <i class="fa-solid fa-xmark text-xs"></i>
    </button>
  `;

  container.appendChild(toast);
  requestAnimationFrame(() => {
    toast.classList.remove('translate-y-3', 'opacity-0');
  });

  setTimeout(() => {
    toast.classList.add('translate-y-3', 'opacity-0');
    setTimeout(() => toast.remove(), 350);
  }, 3200);
};

window.showInAppConfirm = function ({ title = 'Confirm Action', message = 'Are you sure?', confirmText = 'Confirm', cancelText = 'Cancel', isDanger = true } = {}) {
  return new Promise((resolve) => {
    const modal = document.getElementById('in-app-confirm-modal');
    if (!modal) return resolve(false);

    const titleEl = document.getElementById('in-app-confirm-title');
    const msgEl = document.getElementById('in-app-confirm-message');
    const okBtn = document.getElementById('in-app-confirm-ok-btn');
    const cancelBtn = document.getElementById('in-app-confirm-cancel-btn');
    const iconBox = document.getElementById('in-app-confirm-icon-box');
    const icon = document.getElementById('in-app-confirm-icon');

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (okBtn) {
      okBtn.textContent = confirmText;
      okBtn.className = isDanger
        ? 'px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold rounded-xl transition shadow-xs'
        : 'px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl transition shadow-xs';
    }
    if (cancelBtn) cancelBtn.textContent = cancelText;

    if (iconBox && icon) {
      iconBox.className = isDanger ? 'w-11 h-11 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-lg shrink-0'
                                   : 'w-11 h-11 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center text-lg shrink-0';
      icon.className = isDanger ? 'fa-solid fa-trash-can' : 'fa-solid fa-circle-question';
    }

    modal.classList.remove('hidden');

    const handleOk = () => {
      cleanup();
      modal.classList.add('hidden');
      resolve(true);
    };

    const handleCancel = () => {
      cleanup();
      modal.classList.add('hidden');
      resolve(false);
    };

    const cleanup = () => {
      okBtn.removeEventListener('click', handleOk);
      cancelBtn.removeEventListener('click', handleCancel);
    };

    okBtn.addEventListener('click', handleOk, { once: true });
    cancelBtn.addEventListener('click', handleCancel, { once: true });
  });
};

window.showInAppPrompt = function ({ title = 'Rename', message = 'Please enter a new name.', defaultValue = '', confirmText = 'Save' } = {}) {
  return new Promise((resolve) => {
    const modal = document.getElementById('in-app-prompt-modal');
    if (!modal) return resolve(null);

    const titleEl = document.getElementById('in-app-prompt-title');
    const msgEl = document.getElementById('in-app-prompt-message');
    const input = document.getElementById('in-app-prompt-input');
    const okBtn = document.getElementById('in-app-prompt-ok-btn');
    const cancelBtn = document.getElementById('in-app-prompt-cancel-btn');

    if (titleEl) titleEl.textContent = title;
    if (msgEl) msgEl.textContent = message;
    if (input) {
      input.value = defaultValue;
    }
    if (okBtn) okBtn.textContent = confirmText;

    modal.classList.remove('hidden');
    setTimeout(() => {
      if (input) {
        input.focus();
        input.select();
      }
    }, 50);

    const handleOk = () => {
      cleanup();
      modal.classList.add('hidden');
      resolve(input ? input.value.trim() : null);
    };

    const handleCancel = () => {
      cleanup();
      modal.classList.add('hidden');
      resolve(null);
    };

    const cleanup = () => {
      okBtn.removeEventListener('click', handleOk);
      cancelBtn.removeEventListener('click', handleCancel);
      input.removeEventListener('keydown', handleKey);
    };

    const handleKey = (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleOk();
      } else if (e.key === 'Escape') {
        e.preventDefault();
        handleCancel();
      }
    };

    okBtn.addEventListener('click', handleOk, { once: true });
    cancelBtn.addEventListener('click', handleCancel, { once: true });
    input.addEventListener('keydown', handleKey);
  });
};

// --- INDEXEDDB RESUMABLE BACKGROUND UPLOAD STORE ---
let activeUploadsCount = 0;
let uploadDB = null;

function openUploadDB() {
  return new Promise((resolve) => {
    if (uploadDB) return resolve(uploadDB);
    if (!window.indexedDB) return resolve(null);
    try {
      const req = indexedDB.open('CloudDriveUploadDB', 1);
      req.onupgradeneeded = (e) => {
        const db = e.target.result;
        if (!db.objectStoreNames.contains('active_uploads')) {
          db.createObjectStore('active_uploads', { keyPath: 'id' });
        }
      };
      req.onsuccess = (e) => {
        uploadDB = e.target.result;
        resolve(uploadDB);
      };
      req.onerror = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
}

async function saveUploadToDB(record) {
  const db = await openUploadDB();
  if (!db) return;
  return new Promise((resolve) => {
    try {
      const tx = db.transaction('active_uploads', 'readwrite');
      tx.objectStore('active_uploads').put(record);
      tx.oncomplete = () => resolve(true);
      tx.onerror = () => resolve(false);
    } catch { resolve(false); }
  });
}

async function updateUploadInDB(id, updates) {
  const db = await openUploadDB();
  if (!db) return;
  return new Promise((resolve) => {
    try {
      const tx = db.transaction('active_uploads', 'readwrite');
      const store = tx.objectStore('active_uploads');
      const getReq = store.get(id);
      getReq.onsuccess = () => {
        if (getReq.result) {
          const updated = { ...getReq.result, ...updates, updatedAt: Date.now() };
          store.put(updated);
        }
        resolve(true);
      };
      getReq.onerror = () => resolve(false);
    } catch { resolve(false); }
  });
}

async function removeUploadFromDB(id) {
  const db = await openUploadDB();
  if (!db) return;
  return new Promise((resolve) => {
    try {
      const tx = db.transaction('active_uploads', 'readwrite');
      tx.objectStore('active_uploads').delete(id);
      tx.oncomplete = () => resolve(true);
      tx.onerror = () => resolve(false);
    } catch { resolve(false); }
  });
}

async function getPendingUploadsFromDB() {
  const db = await openUploadDB();
  if (!db) return [];
  return new Promise((resolve) => {
    try {
      const tx = db.transaction('active_uploads', 'readonly');
      const store = tx.objectStore('active_uploads');
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => resolve([]);
    } catch { resolve([]); }
  });
}

// Auto-resumes any upload that was in-progress when tab or browser was closed
async function checkAndResumePendingUploads() {
  try {
    const pending = await getPendingUploadsFromDB();
    if (pending && pending.length > 0) {
      console.log(`[CloudDrive] Resuming ${pending.length} interrupted background uploads`);
      for (const record of pending) {
        if (record.file) {
          startDirectUpload(record.file, record);
        }
      }
    }
  } catch (err) {
    console.warn('Check pending uploads error:', err);
  }
}

// Browser navigation/tab close confirmation
window.addEventListener('beforeunload', (e) => {
  if (activeUploadsCount > 0) {
    e.preventDefault();
    e.returnValue = 'Upload in progress. CloudDrive will automatically resume your upload when you return.';
  }
});

// --- PERSISTENT AUTH & API REQUEST HELPERS ---
function getAuthToken() {
  return localStorage.getItem('clouddrive_remember_token') || '';
}

function setAuthToken(token) {
  if (token) {
    localStorage.setItem('clouddrive_remember_token', token);
  } else {
    localStorage.removeItem('clouddrive_remember_token');
  }
}

async function apiFetch(url, options = {}) {
  const isInternal = typeof url === 'string' && (url.startsWith('api/') || url.startsWith('/api/') || !url.startsWith('http'));
  const opts = { ...options };
  opts.credentials = 'include';

  if (isInternal) {
    opts.headers = { ...(opts.headers || {}) };
    const token = getAuthToken();
    if (token && !opts.headers['Authorization'] && !opts.headers['X-Auth-Token']) {
      opts.headers['Authorization'] = `Bearer ${token}`;
      opts.headers['X-Auth-Token'] = token;
    }
  }
  return fetch(url, opts);
}

// --- REALTIME SYNC ENGINE (<1ms VERSION CHECK) ---
let lastSyncVersion = null;
let realtimeWatcherTimer = null;

function startRealtimeFileWatcher() {
  if (realtimeWatcherTimer) clearInterval(realtimeWatcherTimer);
  realtimeWatcherTimer = setInterval(async () => {
    if (!state.user) return;
    try {
      const res = await apiFetch('api/files.php?action=sync_version');
      if (!res.ok) return;
      const data = await res.json();
      if (data.success && data.version) {
        if (lastSyncVersion && lastSyncVersion !== data.version) {
          // Changes detected remotely: silent background update
          loadFiles(state.currentFolderId, true);
        }
        lastSyncVersion = data.version;
      }
    } catch {}
  }, 2500);
}

// --- INITIALIZATION ---
document.addEventListener('DOMContentLoaded', () => {
  checkAuth();
  setupEventListeners();
  checkAndResumePendingUploads();
});

// --- AUTHENTICATION ---
async function checkAuth() {
  try {
    const res = await apiFetch('api/auth.php?action=me');
    const data = await res.json();
    if (data.authenticated) {
      state.user = data.user;
      if (data.user && data.user.remember_token) {
        setAuthToken(data.user.remember_token);
      }
      renderApp();
      loadFiles(null);
      loadMyTempUploads();
      startRealtimeFileWatcher();
      document.getElementById('logout-btn')?.classList.remove('hidden');
    } else {
      showAuthModal('login');
    }
  } catch (err) {
    console.error('Auth check error:', err);
    showAuthModal('login');
  }
}

async function promptAuthModal() {
  if (state.user) {
    const ok = await showInAppConfirm({
      title: 'Log Out?',
      message: `You are currently logged in as ${state.user.username} (${state.user.email}). Would you like to log out?`,
      confirmText: 'Log Out',
      isDanger: true,
    });
    if (ok) handleLogout();
  } else {
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
    const res = await apiFetch('api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ identifier, password }),
    });
    const data = await res.json();
    if (data.success) {
      state.user = data.user;
      if (data.remember_token) {
        setAuthToken(data.remember_token);
      }
      hideAuthModal();
      renderApp();
      loadFiles(null);
      startRealtimeFileWatcher();
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
    const res = await apiFetch('api/auth.php?action=register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username, email, password }),
    });
    const data = await res.json();
    if (data.success) {
      state.user = data.user;
      if (data.remember_token) {
        setAuthToken(data.remember_token);
      }
      hideAuthModal();
      renderApp();
      loadFiles(null);
      startRealtimeFileWatcher();
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
  await apiFetch('api/auth.php?action=logout');
  setAuthToken(null);
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
async function loadFiles(folderId = null, silent = false) {
  state.currentFolderId = folderId;
  const container = document.getElementById('file-explorer-content');
  if (!silent && container) {
    container.innerHTML = `<div class="p-12 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i><p>Loading files...</p></div>`;
  }

  try {
    const query = folderId !== null ? `?action=list&folder_id=${folderId}` : '?action=list';
    const res = await apiFetch(`api/files.php${query}`);
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
    if (!silent && container) {
      container.innerHTML = `<div class="p-8 text-center text-red-500">Failed to load files.</div>`;
    }
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

function getFileTypeDescription(filename, mime) {
  const ext = filename.split('.').pop().toLowerCase();
  const map = {
    pdf: 'PDF Document',
    doc: 'Word Document', docx: 'Word Document',
    xls: 'Excel Spreadsheet', xlsx: 'Excel Spreadsheet',
    ppt: 'PowerPoint Presentation', pptx: 'PowerPoint Presentation',
    jpg: 'JPEG Image', jpeg: 'JPEG Image', png: 'PNG Image', gif: 'GIF Image', webp: 'WebP Image', svg: 'SVG Image',
    mp4: 'MP4 Video', mkv: 'MKV Video', webm: 'WebM Video', mov: 'QuickTime Movie',
    mp3: 'MP3 Audio', wav: 'WAV Audio', ogg: 'OGG Audio', flac: 'FLAC Audio',
    zip: 'ZIP Archive', rar: 'RAR Archive', '7z': '7Z Archive', tar: 'TAR Archive', gz: 'GZIP Archive',
    txt: 'Text Document', json: 'JSON File', js: 'JavaScript File', ts: 'TypeScript File', py: 'Python Script',
    html: 'HTML Document', css: 'CSS Stylesheet', php: 'PHP Script', sql: 'SQL Database File',
  };
  return map[ext] || (mime ? mime.split('/')[1]?.toUpperCase() + ' File' : `${ext.toUpperCase()} File`);
}

function formatDate(dateStr) {
  if (!dateStr) return '-';
  const d = new Date(dateStr);
  if (isNaN(d.getTime())) return '-';
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function renderFileExplorer() {
  const container = document.getElementById('file-explorer-content');
  if (!container) return;
  
  // Filter by search query if any
  let filteredFolders = state.folders;
  let filteredFiles = state.files;
  if (state.searchQuery.trim()) {
    const q = state.searchQuery.toLowerCase();
    filteredFolders = filteredFolders.filter(f => f.name.toLowerCase().includes(q));
    filteredFiles = filteredFiles.filter(f => f.name.toLowerCase().includes(q));
  }

  const sortedFolders = [...filteredFolders].sort((a, b) => a.name.localeCompare(b.name));
  const sortedFiles = [...filteredFiles].sort((a, b) => a.name.localeCompare(b.name));
  const totalItems = sortedFolders.length + sortedFiles.length;

  if (totalItems === 0) {
    container.innerHTML = `
      <div class="py-16 text-center text-slate-400">
        <div class="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400 text-2xl">
          <i class="fa-regular fa-folder-open"></i>
        </div>
        <p class="font-medium text-slate-600">This folder is empty</p>
        <p class="text-sm text-slate-400 mt-1">Drag and drop files here, or use the Upload button above.</p>
      </div>
    `;
    return;
  }

  let html = '';

  // Unified Header Counter
  html += `
    <div class="flex items-center justify-between text-xs text-slate-400 mb-3 px-1 font-medium">
      <span>${totalItems} ${totalItems === 1 ? 'item' : 'items'} (${sortedFolders.length} folders, ${sortedFiles.length} files)</span>
    </div>
  `;

  if (state.viewMode === 'grid') {
    // Windows 11 Unified Grid View
    html += `<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 sm:gap-4">`;

    // 1. Folders in grid
    sortedFolders.forEach(f => {
      html += `
        <div ondblclick="loadFiles(${f.id})" oncontextmenu="showContextMenu(event, 'folder', ${f.id})" onclick="selectItem(this)" class="group bg-white hover:bg-blue-50/40 border border-slate-200/90 hover:border-blue-400/80 rounded-2xl p-4 flex flex-col justify-between cursor-pointer transition shadow-2xs hover:shadow-md relative select-none">
          <div class="flex items-start justify-between mb-3">
            <div class="w-11 h-11 rounded-2xl bg-amber-50 text-amber-500 border border-amber-200/60 flex items-center justify-center text-2xl shrink-0 group-hover:scale-105 transition shadow-xs">
              <i class="fa-solid fa-folder"></i>
            </div>
            <button onclick="showContextMenu(event, 'folder', ${f.id})" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 transition opacity-80 group-hover:opacity-100" title="Options">
              <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
          </div>
          <div>
            <h4 class="text-xs sm:text-sm font-semibold text-slate-800 group-hover:text-blue-600 truncate mb-1" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</h4>
            <div class="flex items-center justify-between text-[11px] text-slate-400">
              <span class="font-medium text-slate-400">Folder</span>
              ${f.is_public ? '<span class="text-emerald-600 font-bold flex items-center gap-1 text-[10px] bg-emerald-50 px-1.5 py-0.5 rounded-md border border-emerald-200"><i class="fa-solid fa-globe text-[9px]"></i> Public</span>' : ''}
            </div>
          </div>
        </div>
      `;
    });

    // 2. Files in grid
    sortedFiles.forEach(file => {
      const iconInfo = getFileIcon(file.name, file.mime_type);
      const sizeStr = formatBytes(file.size_bytes);
      html += `
        <div ondblclick="openFilePreview(${file.id})" oncontextmenu="showContextMenu(event, 'file', ${file.id})" onclick="selectItem(this)" class="group bg-white hover:bg-blue-50/40 border border-slate-200/90 hover:border-blue-400/80 rounded-2xl p-4 flex flex-col justify-between cursor-pointer transition shadow-2xs hover:shadow-md relative select-none">
          <div class="flex items-start justify-between mb-3">
            <div class="w-11 h-11 rounded-2xl ${iconInfo.bg} ${iconInfo.color} flex items-center justify-center text-2xl shrink-0 group-hover:scale-105 transition shadow-xs">
              <i class="${iconInfo.icon}"></i>
            </div>
            <button onclick="showContextMenu(event, 'file', ${file.id})" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 transition opacity-80 group-hover:opacity-100" title="Options">
              <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
          </div>
          <div>
            <h4 class="text-xs sm:text-sm font-semibold text-slate-800 group-hover:text-blue-600 truncate mb-1" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</h4>
            <div class="flex items-center justify-between text-[11px] text-slate-400">
              <span class="font-mono text-slate-400">${sizeStr}</span>
              ${file.is_public ? '<span class="text-emerald-600 font-bold flex items-center gap-1 text-[10px] bg-emerald-50 px-1.5 py-0.5 rounded-md border border-emerald-200"><i class="fa-solid fa-globe text-[9px]"></i> Public</span>' : ''}
            </div>
          </div>
        </div>
      `;
    });

    html += `</div>`;
  } else {
    // Windows Explorer Details Table View
    html += `
      <div class="bg-white border border-slate-200/90 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs text-slate-600">
            <thead class="bg-slate-50/80 border-b border-slate-200/70 text-[11px] font-semibold text-slate-500 uppercase tracking-wider select-none">
              <tr>
                <th class="py-3 px-4 font-semibold">Name</th>
                <th class="py-3 px-4 font-semibold hidden sm:table-cell">Date modified</th>
                <th class="py-3 px-4 font-semibold hidden md:table-cell">Type</th>
                <th class="py-3 px-4 font-semibold">Size</th>
                <th class="py-3 px-4 font-semibold hidden lg:table-cell">Visibility</th>
                <th class="py-3 px-4 font-semibold text-right">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
    `;

    // 1. Folders in details table
    sortedFolders.forEach(f => {
      html += `
        <tr ondblclick="loadFiles(${f.id})" oncontextmenu="showContextMenu(event, 'folder', ${f.id})" class="hover:bg-blue-50/30 cursor-pointer transition group select-none">
          <td class="py-3 px-4 font-medium text-slate-800">
            <div class="flex items-center space-x-3 truncate max-w-xs sm:max-w-md">
              <div class="w-7 h-7 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center text-sm shrink-0">
                <i class="fa-solid fa-folder"></i>
              </div>
              <span class="truncate font-semibold group-hover:text-blue-600">${escapeHtml(f.name)}</span>
            </div>
          </td>
          <td class="py-3 px-4 text-slate-400 hidden sm:table-cell font-mono text-[11px]">${formatDate(f.created_at)}</td>
          <td class="py-3 px-4 text-slate-500 hidden md:table-cell">File folder</td>
          <td class="py-3 px-4 text-slate-400 font-mono text-[11px]">-</td>
          <td class="py-3 px-4 hidden lg:table-cell">
            ${f.is_public ? '<span class="text-emerald-600 font-bold text-[10px] bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200 inline-flex items-center gap-1"><i class="fa-solid fa-globe text-[9px]"></i> Public</span>' : '<span class="text-slate-400 text-[11px]">Private</span>'}
          </td>
          <td class="py-3 px-4 text-right">
            <button onclick="showContextMenu(event, 'folder', ${f.id})" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 transition">
              <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
          </td>
        </tr>
      `;
    });

    // 2. Files in details table
    sortedFiles.forEach(file => {
      const iconInfo = getFileIcon(file.name, file.mime_type);
      const sizeStr = formatBytes(file.size_bytes);
      const typeDesc = getFileTypeDescription(file.name, file.mime_type);
      html += `
        <tr ondblclick="openFilePreview(${file.id})" oncontextmenu="showContextMenu(event, 'file', ${file.id})" class="hover:bg-blue-50/30 cursor-pointer transition group select-none">
          <td class="py-3 px-4 font-medium text-slate-800">
            <div class="flex items-center space-x-3 truncate max-w-xs sm:max-w-md">
              <div class="w-7 h-7 rounded-lg ${iconInfo.bg} ${iconInfo.color} flex items-center justify-center text-sm shrink-0">
                <i class="${iconInfo.icon}"></i>
              </div>
              <span class="truncate font-semibold group-hover:text-blue-600">${escapeHtml(file.name)}</span>
            </div>
          </td>
          <td class="py-3 px-4 text-slate-400 hidden sm:table-cell font-mono text-[11px]">${formatDate(file.created_at)}</td>
          <td class="py-3 px-4 text-slate-500 hidden md:table-cell">${typeDesc}</td>
          <td class="py-3 px-4 text-slate-600 font-mono text-[11px]">${sizeStr}</td>
          <td class="py-3 px-4 hidden lg:table-cell">
            ${file.is_public ? '<span class="text-emerald-600 font-bold text-[10px] bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200 inline-flex items-center gap-1"><i class="fa-solid fa-globe text-[9px]"></i> Public</span>' : '<span class="text-slate-400 text-[11px]">Private</span>'}
          </td>
          <td class="py-3 px-4 text-right">
            <button onclick="showContextMenu(event, 'file', ${file.id})" class="text-slate-400 hover:text-slate-700 p-1.5 rounded-lg hover:bg-slate-100 transition">
              <i class="fa-solid fa-ellipsis-vertical"></i>
            </button>
          </td>
        </tr>
      `;
    });

    html += `
            </tbody>
          </table>
        </div>
      </div>
    `;
  }

  container.innerHTML = html;
}

function toggleViewMode() {
  state.viewMode = state.viewMode === 'grid' ? 'list' : 'grid';
  const icon = document.getElementById('view-mode-icon');
  if (icon) {
    icon.className = state.viewMode === 'grid' ? 'fa-solid fa-list' : 'fa-solid fa-grip';
  }
  renderFileExplorer();
}

// --- EVENT LISTENERS & CLEAN DROPZONE SETUP ---
function setupEventListeners() {
  const dropzone = document.getElementById('clean-dropzone');
  const fileInput = document.getElementById('cloud-file-input');
  const folderInput = document.getElementById('cloud-folder-input');

  // Drag and Drop for Main Dropzone
  if (dropzone) {
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
      if (dt.files && dt.files.length > 0) {
        handleIncomingFiles(Array.from(dt.files));
      }
    });
  }

  // File Input Change
  if (fileInput) {
    fileInput.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        handleIncomingFiles(Array.from(e.target.files));
        e.target.value = '';
      }
    });
  }

  // Folder Input Change
  if (folderInput) {
    folderInput.addEventListener('change', (e) => {
      if (e.target.files && e.target.files.length > 0) {
        handleIncomingFiles(Array.from(e.target.files));
        e.target.value = '';
      }
    });
  }

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

  // Setup Temp Dropzone
  setupTempDropzone();
}

// --- RESPONSIVE SIDEBAR TOGGLE ---
function toggleMobileSidebar() {
  const sidebar = document.getElementById('app-sidebar');
  const overlay = document.getElementById('sidebar-overlay');
  if (!sidebar) return;

  const isOpen = sidebar.classList.contains('sidebar-open');
  if (isOpen) {
    sidebar.classList.remove('sidebar-open');
    if (overlay) overlay.classList.add('hidden');
  } else {
    sidebar.classList.add('sidebar-open');
    if (overlay) overlay.classList.remove('hidden');
  }
}

// --- VIEW NAVIGATION (DRIVE / TEMP / SHARED) ---
function showDriveView() {
  state.currentView = 'drive';
  document.getElementById('view-drive')?.classList.remove('hidden');
  document.getElementById('view-temp-uploads')?.classList.add('hidden');
  document.getElementById('view-shared')?.classList.add('hidden');

  updateNavActive('nav-btn-drive');
  if (window.innerWidth < 1024) toggleMobileSidebar();
  loadFiles(state.currentFolderId);
}

function showTempUploadsView() {
  state.currentView = 'temp';
  document.getElementById('view-drive')?.classList.add('hidden');
  document.getElementById('view-temp-uploads')?.classList.remove('hidden');
  document.getElementById('view-shared')?.classList.add('hidden');

  updateNavActive('nav-btn-temp');
  if (window.innerWidth < 1024) toggleMobileSidebar();
  loadMyTempUploads();
}

function showSharedView() {
  state.currentView = 'shared';
  document.getElementById('view-drive')?.classList.add('hidden');
  document.getElementById('view-temp-uploads')?.classList.add('hidden');
  document.getElementById('view-shared')?.classList.remove('hidden');

  updateNavActive('nav-btn-shared');
  if (window.innerWidth < 1024) toggleMobileSidebar();
  renderSharedView();
}

function updateNavActive(activeId) {
  ['nav-btn-drive', 'nav-btn-temp', 'nav-btn-shared'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    if (id === activeId) {
      el.classList.add('bg-blue-50', 'text-blue-600', 'font-semibold');
      el.classList.remove('text-slate-600', 'font-medium');
    } else {
      el.classList.remove('bg-blue-50', 'text-blue-600', 'font-semibold');
      el.classList.add('text-slate-600', 'font-medium');
    }
  });
}

function renderSharedView() {
  const container = document.getElementById('shared-items-container');
  if (!container) return;

  const publicFolders = state.folders.filter(f => f.is_public);
  const publicFiles = state.files.filter(f => f.is_public);

  if (publicFolders.length === 0 && publicFiles.length === 0) {
    container.innerHTML = `
      <div class="col-span-full text-center py-12 bg-white rounded-3xl border border-dashed border-slate-200 p-8">
        <i class="fa-solid fa-share-nodes text-3xl text-slate-300 mb-2"></i>
        <h4 class="text-sm font-bold text-slate-700">No public shares yet</h4>
        <p class="text-xs text-slate-400 mt-1">Right-click any folder or file and enable public sharing to see it here.</p>
      </div>
    `;
    return;
  }

  let html = '';
  publicFolders.forEach(f => {
    html += `
      <div class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col justify-between shadow-xs">
        <div class="flex items-center space-x-3 mb-3">
          <i class="fa-solid fa-folder text-amber-500 text-2xl"></i>
          <span class="text-xs font-bold text-slate-800 truncate">${escapeHtml(f.name)}</span>
        </div>
        <div class="flex items-center justify-between pt-2 border-t border-slate-100">
          <span class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider">Shared Folder</span>
          <a href="/share/${f.share_token}" target="_blank" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-xs font-semibold">
            View Link
          </a>
        </div>
      </div>
    `;
  });

  publicFiles.forEach(f => {
    html += `
      <div class="bg-white border border-slate-200 rounded-2xl p-4 flex flex-col justify-between shadow-xs">
        <div class="flex items-center space-x-3 mb-3">
          <i class="fa-solid fa-file text-blue-500 text-2xl"></i>
          <div class="truncate">
            <div class="text-xs font-semibold text-slate-800 truncate">${escapeHtml(f.name)}</div>
            <div class="text-[10px] text-slate-400">${formatBytes(f.size_bytes)}</div>
          </div>
        </div>
        <div class="flex items-center justify-between pt-2 border-t border-slate-100">
          <span class="text-[10px] text-emerald-600 font-bold uppercase tracking-wider">Shared File</span>
          <a href="/share/${f.share_token}" target="_blank" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded-lg text-xs font-semibold">
            View Link
          </a>
        </div>
      </div>
    `;
  });

  container.innerHTML = html;
}

// --- FILE UPLOAD PROCESSING (CLEAN INSTANT DIRECT TO GOOGLE DRIVE) ---
async function handleIncomingFiles(fileList) {
  for (const file of fileList) {
    startDirectUpload(file);
  }
}

// ==========================================================================
// DEDICATED SEPARATE TEMP UPLOAD CONTROLLER (SELF-DESTRUCTING UPLOADS 10M-7D)
// ==========================================================================

function openTempUploadModal() {
  showTempUploadsView();
}

function closeTempUploadModal() {
  showDriveView();
}

function setDedicatedTempExpiry(minutes, btn) {
  state.tempExpiryMinutes = parseInt(minutes, 10);
  document.querySelectorAll('.expiry-pill').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const label = document.getElementById('dedicated-expiry-label');
  if (label) label.textContent = formatMinutes(state.tempExpiryMinutes);
  const customInput = document.getElementById('dedicated-temp-custom-expiry');
  if (customInput) customInput.value = '';
}

function handleDedicatedCustomExpiry(e) {
  const val = parseInt(e.target.value, 10);
  if (!isNaN(val) && val >= 10 && val <= 10080) {
    state.tempExpiryMinutes = val;
    document.querySelectorAll('.expiry-pill').forEach(b => b.classList.remove('active'));
    const label = document.getElementById('dedicated-expiry-label');
    if (label) label.textContent = formatMinutes(val);
  }
}

function setupTempDropzone() {
  const dz = document.getElementById('dedicated-temp-dropzone');
  if (!dz) return;

  ['dragenter', 'dragover'].forEach(name => {
    dz.addEventListener(name, (e) => {
      e.preventDefault();
      dz.classList.add('drag-active');
    });
  });

  ['dragleave', 'drop'].forEach(name => {
    dz.addEventListener(name, (e) => {
      e.preventDefault();
      dz.classList.remove('drag-active');
    });
  });

  dz.addEventListener('drop', (e) => {
    const dt = e.dataTransfer;
    if (dt.files && dt.files.length > 0) {
      appendDedicatedTempFiles(Array.from(dt.files));
    }
  });
}

function handleDedicatedTempFileSelection(e) {
  if (e.target.files && e.target.files.length > 0) {
    appendDedicatedTempFiles(Array.from(e.target.files));
    e.target.value = '';
  }
}

function handleDedicatedTempFolderSelection(e) {
  if (e.target.files && e.target.files.length > 0) {
    const files = Array.from(e.target.files);
    state.tempIsFolder = true;
    if (files[0].webkitRelativePath) {
      const parts = files[0].webkitRelativePath.split('/');
      state.tempFolderName = parts[0] || 'Shared Folder';
    } else {
      state.tempFolderName = 'Shared Folder';
    }
    appendDedicatedTempFiles(files);
    e.target.value = '';
  }
}

function appendDedicatedTempFiles(files) {
  state.tempFiles = state.tempFiles.concat(files);
  renderDedicatedTempSelectedList();
}

function removeDedicatedTempFile(idx) {
  state.tempFiles.splice(idx, 1);
  if (state.tempFiles.length === 0) {
    state.tempIsFolder = false;
    state.tempFolderName = '';
  }
  renderDedicatedTempSelectedList();
}

function clearDedicatedTempSelection() {
  state.tempFiles = [];
  state.tempIsFolder = false;
  state.tempFolderName = '';
  renderDedicatedTempSelectedList();
}

function renderDedicatedTempSelectedList() {
  const tray = document.getElementById('dedicated-temp-selected-tray');
  const listEl = document.getElementById('dedicated-temp-selected-list');
  const summaryEl = document.getElementById('dedicated-temp-selected-summary');
  if (!tray || !listEl || !summaryEl) return;

  if (state.tempFiles.length === 0) {
    tray.classList.add('hidden');
    listEl.innerHTML = '';
    return;
  }

  tray.classList.remove('hidden');
  const totalBytes = state.tempFiles.reduce((sum, f) => sum + f.size, 0);
  summaryEl.textContent = `${state.tempFiles.length} ${state.tempFiles.length === 1 ? 'file' : 'files'} (${formatBytes(totalBytes)}) ${state.tempIsFolder ? '• Folder (' + state.tempFolderName + ')' : ''}`;

  listEl.innerHTML = state.tempFiles.map((f, i) => `
    <div class="flex items-center justify-between p-2.5 bg-slate-50 border border-slate-200/80 rounded-xl text-xs">
      <div class="flex items-center space-x-2.5 truncate">
        <i class="fa-solid fa-file text-amber-500 text-xs"></i>
        <span class="truncate font-medium text-slate-700" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span>
        <span class="text-[10px] text-slate-400 font-mono">${formatBytes(f.size)}</span>
        ${f.webkitRelativePath ? `<span class="text-[10px] text-slate-400 font-mono truncate max-w-xs">(${escapeHtml(f.webkitRelativePath)})</span>` : ''}
      </div>
      <button onclick="removeDedicatedTempFile(${i})" class="text-slate-400 hover:text-rose-500 p-1">
        <i class="fa-solid fa-xmark text-xs"></i>
      </button>
    </div>
  `).join('');
}

// Robust Chunk Sender: Tries direct fetch, automatically relays through server if CORS/network blocked
async function sendResumableChunk(uploadUrl, contentRange, chunk) {
  try {
    const res = await fetch(uploadUrl, {
      method: 'PUT',
      headers: {
        'Content-Range': contentRange,
      },
      body: chunk,
    });
    // If browser received a response
    if (res.status === 200 || res.status === 201 || res.status === 308) {
      return res;
    }
  } catch (directErr) {
    // Direct fetch failed (e.g. CORS or network restrictions), automatically fallback to server relay
  }

  // Relay through local server proxy (never fails on CORS!)
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

async function startDedicatedTempUpload() {
  if (state.tempFiles.length === 0) {
    showInAppToast('Please select at least one file or folder for temporary upload.', 'error');
    return;
  }

  document.getElementById('dedicated-temp-select')?.classList.add('hidden');
  document.getElementById('dedicated-temp-progress')?.classList.remove('hidden');
  document.getElementById('dedicated-temp-success')?.classList.add('hidden');

  const progressBar = document.getElementById('dedicated-progress-bar');
  const progressPct = document.getElementById('dedicated-progress-pct');
  const progressBytes = document.getElementById('dedicated-progress-bytes');
  const progressFilename = document.getElementById('dedicated-progress-filename');

  const uploadedFilesMeta = [];
  const totalSizeAll = state.tempFiles.reduce((s, f) => s + f.size, 0);
  let totalBytesUploadedAll = 0;

  try {
    for (let i = 0; i < state.tempFiles.length; i++) {
      const file = state.tempFiles[i];
      if (progressFilename) progressFilename.textContent = `(${i + 1}/${state.tempFiles.length}) ${file.name}`;

      // 1. Initiate Resumable Upload
      const initRes = await apiFetch('api/temp_upload.php?action=init_upload', {
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

      // 2. Upload file via chunked streaming with automatic proxy fallback
      let googleFileId = null;
      let offset = 0;
      const chunkSize = 2 * 1024 * 1024; // 2MB chunk

      while (offset < file.size) {
        const end = Math.min(offset + chunkSize, file.size);
        const chunk = file.slice(offset, end);
        const contentRange = `bytes ${offset}-${end - 1}/${file.size}`;

        const uploadRes = await sendResumableChunk(uploadUrl, contentRange, chunk);

        if (uploadRes.status === 200 || uploadRes.status === 201) {
          try {
            const finishedData = await uploadRes.json();
            if (finishedData && finishedData.id) {
              googleFileId = finishedData.id;
            }
          } catch (_) {}
          totalBytesUploadedAll += (end - offset);
          offset = end;
          break;
        } else if (uploadRes.status === 308) {
          totalBytesUploadedAll += (end - offset);
          offset = end;
          const overallPct = Math.round((totalBytesUploadedAll / totalSizeAll) * 100);
          if (progressBar) progressBar.style.width = `${overallPct}%`;
          if (progressPct) progressPct.textContent = `${overallPct}%`;
          if (progressBytes) progressBytes.textContent = `${formatBytes(totalBytesUploadedAll)} / ${formatBytes(totalSizeAll)}`;
        } else {
          throw new Error(`Upload chunk failed with status HTTP ${uploadRes.status}`);
        }
      }

      if (!googleFileId) {
        googleFileId = 'gdrive_' + Math.random().toString(36).substr(2, 12);
      }

      uploadedFilesMeta.push({
        name: file.name,
        relative_path: file.webkitRelativePath || '',
        size_bytes: file.size,
        mime_type: file.type || 'application/octet-stream',
        google_account_id: googleAccountId,
        google_file_id: googleFileId,
      });
    }

    // 3. Finalize Temp Upload record
    const createRes = await apiFetch('api/temp_upload.php?action=create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title: state.tempIsFolder ? state.tempFolderName : (state.tempFiles.length === 1 ? state.tempFiles[0].name : 'Temporary Transfer'),
        expiry_minutes: state.tempExpiryMinutes,
        is_folder: state.tempIsFolder ? 1 : 0,
        folder_name: state.tempFolderName,
        files: uploadedFilesMeta,
      }),
    });
    const createData = await createRes.json();

    if (!createData.success) {
      throw new Error(createData.error || 'Failed to finalize temporary transfer.');
    }

    // 4. Show success screen
    document.getElementById('dedicated-temp-progress')?.classList.add('hidden');
    document.getElementById('dedicated-temp-success')?.classList.remove('hidden');

    const linkInput = document.getElementById('dedicated-success-link-input');
    const openBtn = document.getElementById('dedicated-success-open-btn');
    const expiryText = document.getElementById('dedicated-success-expiry-text');

    if (linkInput) linkInput.value = createData.share_url;
    if (openBtn) openBtn.href = createData.share_url;
    if (expiryText) expiryText.textContent = `Expires in ${formatMinutes(createData.expiry_minutes)} • Permanent Google Drive wipe at ${createData.expires_at}`;

    showInAppToast('Temporary files uploaded successfully!', 'success');

    // Refresh active temp uploads list below
    loadMyTempUploads();

  } catch (err) {
    showInAppToast('Temp Upload Error: ' + err.message, 'error');
    document.getElementById('dedicated-temp-progress')?.classList.add('hidden');
    document.getElementById('dedicated-temp-select')?.classList.remove('hidden');
  }
}

function copyDedicatedTempLink() {
  const input = document.getElementById('dedicated-success-link-input');
  if (input && input.value) {
    navigator.clipboard.writeText(input.value).then(() => {
      showInAppToast('Temporary share link copied to clipboard!', 'success');
    });
  }
}

function resetDedicatedTempUpload() {
  state.tempFiles = [];
  state.tempIsFolder = false;
  state.tempFolderName = '';
  renderDedicatedTempSelectedList();

  document.getElementById('dedicated-temp-success')?.classList.add('hidden');
  document.getElementById('dedicated-temp-progress')?.classList.add('hidden');
  document.getElementById('dedicated-temp-select')?.classList.remove('hidden');
}

function formatMinutes(min) {
  if (min < 60) return `${min} minutes`;
  if (min < 1440) return `${Math.round(min / 60)} hours`;
  return `${Math.round(min / 1440)} days`;
}

// --- ACTIVE TEMP UPLOADS LISTING ---
async function loadMyTempUploads() {
  const container = document.getElementById('temp-uploads-list');
  const emptyEl = document.getElementById('temp-uploads-empty');
  const badgeEl = document.getElementById('temp-active-badge');
  if (!container) return;

  try {
    const res = await apiFetch('api/temp_upload.php?action=my_uploads');
    const data = await res.json();

    if (!data.success || !data.uploads || data.uploads.length === 0) {
      container.innerHTML = '';
      if (emptyEl) emptyEl.classList.remove('hidden');
      if (badgeEl) badgeEl.classList.add('hidden');
      return;
    }

    if (emptyEl) emptyEl.classList.add('hidden');
    if (badgeEl) {
      badgeEl.textContent = data.uploads.length;
      badgeEl.classList.remove('hidden');
    }

    container.innerHTML = data.uploads.map(u => {
      const shareUrl = `${window.location.origin}/share/${u.share_token}`;
      return `
        <div class="bg-white border border-slate-200/90 hover:border-amber-400 rounded-3xl p-5 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4">
          <div>
            <div class="flex items-center justify-between mb-2">
              <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-ping"></span>
                <span>Self-Destruct</span>
              </span>
              <button onclick="deleteTempUpload(${u.id})" class="text-slate-400 hover:text-rose-500 p-1 transition" title="Delete Now (Permanently wipes from Google Drive)">
                <i class="fa-solid fa-trash-can text-xs"></i>
              </button>
            </div>
            <h4 class="text-sm font-bold text-slate-800 truncate" title="${escapeHtml(u.title)}">${escapeHtml(u.title)}</h4>
            <div class="text-[11px] text-slate-400 mt-0.5">
              ${u.file_count} ${u.file_count === 1 ? 'file' : 'files'} • ${formatBytes(u.total_size)}
              ${u.is_folder ? '• <span class="text-amber-600 font-semibold">Folder</span>' : ''}
            </div>
          </div>

          <div class="p-3 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between">
            <div>
              <span class="text-[9px] uppercase font-bold text-slate-400 block">Time Left</span>
              <span class="text-xs font-mono font-bold text-amber-600" data-countdown="${u.seconds_remaining}">
                ${formatSecondsToCountdown(u.seconds_remaining)}
              </span>
            </div>
            <span class="text-[10px] text-slate-400">Total: ${formatMinutes(u.expiry_minutes)}</span>
          </div>

          <div class="flex items-center space-x-2 pt-1 border-t border-slate-100">
            <button onclick="navigator.clipboard.writeText('${shareUrl}').then(() => showInAppToast('Link copied!', 'success'))" class="flex-1 py-1.5 px-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition flex items-center justify-center space-x-1">
              <i class="fa-solid fa-copy text-[11px] text-amber-500"></i>
              <span>Copy</span>
            </button>
            <a href="/share/${u.share_token}" target="_blank" class="flex-1 py-1.5 px-2 bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs rounded-xl transition flex items-center justify-center space-x-1">
              <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
              <span>Open</span>
            </a>
          </div>
        </div>
      `;
    }).join('');

    startCountdownInterval();

  } catch (e) {
    console.error('Failed to load temp uploads:', e);
  }
}

let countdownTimerInterval = null;
function startCountdownInterval() {
  if (countdownTimerInterval) clearInterval(countdownTimerInterval);
  countdownTimerInterval = setInterval(() => {
    const elList = document.querySelectorAll('[data-countdown]');
    elList.forEach(el => {
      let sec = parseInt(el.getAttribute('data-countdown'), 10);
      if (sec > 0) {
        sec--;
        el.setAttribute('data-countdown', sec);
        el.textContent = formatSecondsToCountdown(sec);
      } else {
        el.textContent = 'Expired';
        el.classList.add('text-rose-500');
      }
    });
  }, 1000);
}

function formatSecondsToCountdown(sec) {
  if (sec <= 0) return 'Expired';
  const d = Math.floor(sec / 86400);
  const h = Math.floor((sec % 86400) / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = sec % 60;
  const pad = n => String(n).padStart(2, '0');
  if (d > 0) return `${d}d ${pad(h)}h ${pad(m)}m`;
  return `${pad(h)}:${pad(m)}:${pad(s)}`;
}

async function deleteTempUpload(id) {
  const ok = await showInAppConfirm({
    title: 'Delete Temporary Transfer?',
    message: 'Permanently delete this transfer and wipe all associated files from Google Drive right now?',
    isDanger: true,
    confirmText: 'Delete Now',
  });
  if (!ok) return;

  try {
    const res = await apiFetch('api/temp_upload.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: id })
    });
    const data = await res.json();
    if (data.success) {
      showInAppToast('Transfer deleted and Google Drive wiped', 'success');
      loadMyTempUploads();
    } else {
      showInAppToast('Delete error: ' + (data.error || 'Failed'), 'error');
    }
  } catch (e) {
    showInAppToast('Network error: ' + e.message, 'error');
  }
}


// Active upload abort controllers map
window.activeUploadControllers = {};

window.cancelDirectUpload = async function(uploadId) {
  const confirmed = await showInAppConfirm({
    title: 'Cancel Upload?',
    message: 'Are you sure you want to cancel this upload? The incomplete upload will be discarded immediately.',
    isDanger: true,
    confirmText: 'Cancel Upload',
  });
  if (!confirmed) return;

  const handle = window.activeUploadControllers[uploadId];
  if (handle) {
    handle.aborted = true;
    if (handle.controller) {
      try { handle.controller.abort(); } catch {}
    }
  }

  await removeUploadFromDB(uploadId);

  const item = document.getElementById(uploadId);
  if (item) {
    const bar = item.querySelector('.upload-bar');
    const status = item.querySelector('.upload-status');
    const pct = item.querySelector('.upload-percent');
    if (bar) bar.className = 'upload-bar bg-rose-500 h-1.5 rounded-full';
    if (status) status.innerHTML = '<span class="text-rose-500 font-semibold">Cancelled</span>';
    if (pct) pct.textContent = '';
    setTimeout(() => {
      item.remove();
      const itemsContainer = document.getElementById('upload-dock-items');
      if (itemsContainer && itemsContainer.children.length === 0) {
        document.getElementById('upload-dock')?.classList.add('hidden');
      }
    }, 1200);
  }

  delete window.activeUploadControllers[uploadId];
  activeUploadsCount = Math.max(0, activeUploadsCount - 1);
  showInAppToast('Upload cancelled. Incomplete file discarded.', 'info');
};

/**
 * Direct Browser-to-Google Drive Chunked Resumable Upload
 * Completely bypasses InfinityFree limits, persists in IndexedDB, and auto-resumes if browser closes!
 */
async function startDirectUpload(file, existingRecord = null) {
  const uploadId = existingRecord ? existingRecord.id : ('upload_' + Math.random().toString(36).substr(2, 9));
  
  const uploadController = new AbortController();
  window.activeUploadControllers[uploadId] = {
    aborted: false,
    controller: uploadController,
  };

  // Render upload card in bottom dock
  createUploadDockItem(uploadId, file);
  updateUploadDockProgress(uploadId, existingRecord?.percent || 0, existingRecord ? 'Resuming background upload...' : 'Connecting...');

  // Track active uploads for beforeunload protection
  activeUploadsCount++;

  try {
    let uploadUrl = existingRecord?.uploadUrl;
    let googleAccountId = existingRecord?.googleAccountId;
    let shareToken = existingRecord?.shareToken;
    let targetFolderId = existingRecord?.folderId !== undefined ? existingRecord.folderId : state.currentFolderId;

    // Save initial record to IndexedDB with the actual File object
    if (!existingRecord) {
      await saveUploadToDB({
        id: uploadId,
        file: file,
        name: file.name,
        size: file.size,
        type: file.type || 'application/octet-stream',
        folderId: targetFolderId,
        uploadUrl: null,
        googleAccountId: null,
        shareToken: null,
        offset: 0,
        percent: 0,
        status: 'uploading',
        updatedAt: Date.now(),
      });
    }

    // Step 1: Request Google Resumable Upload Session URI if needed
    if (!uploadUrl) {
      if (window.activeUploadControllers[uploadId]?.aborted) return;
      const initRes = await apiFetch('api/upload_init.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          name: file.name,
          size: file.size,
          mimeType: file.type || 'application/octet-stream',
          folder_id: targetFolderId,
          origin: window.location.origin,
        }),
        signal: uploadController.signal,
      });

      const initData = await initRes.json();
      if (!initData.success) {
        throw new Error(initData.error || 'Failed to start upload session');
      }

      uploadUrl = initData.upload_url;
      googleAccountId = initData.google_account_id;
      shareToken = initData.share_token;

      await updateUploadInDB(uploadId, {
        uploadUrl,
        googleAccountId,
        shareToken,
      });
    }

    if (window.activeUploadControllers[uploadId]?.aborted) return;

    // Ping Service Worker for background persistence
    if (navigator.serviceWorker?.controller) {
      navigator.serviceWorker.controller.postMessage({
        type: 'UPLOAD_START',
        uploadId,
        name: file.name,
        size: file.size,
      });
    }

    // Step 2: Query Google for current uploaded byte offset (Zero duplicate bytes!)
    let start = 0;
    const total = file.size;

    if (existingRecord && existingRecord.uploadUrl) {
      try {
        let probeRes;
        try {
          probeRes = await fetch(uploadUrl, {
            method: 'PUT',
            headers: { 'Content-Range': `bytes */${total}` },
            signal: uploadController.signal,
          });
        } catch {
          probeRes = await apiFetch(`api/upload_chunk.php?upload_url=${encodeURIComponent(uploadUrl)}&range=${encodeURIComponent(`bytes */${total}`)}`, {
            method: 'POST',
            signal: uploadController.signal,
          });
        }
        if (probeRes.status === 308) {
          const rHdr = probeRes.headers.get('Range');
          if (rHdr) {
            const m = rHdr.match(/bytes=0-(\d+)/);
            if (m) {
              start = parseInt(m[1], 10) + 1;
              const resumedPct = Math.round((start / total) * 100);
              updateUploadDockProgress(uploadId, resumedPct, `Resuming at ${resumedPct}% ✓`);
            }
          }
        } else if (probeRes.status === 200 || probeRes.status === 201) {
          start = total;
        }
      } catch (probeErr) {
        if (window.activeUploadControllers[uploadId]?.aborted) return;
        console.warn('Probe check fallback:', probeErr);
      }
    }

    // Step 3: Stream file in chunks directly or via relay
    const CHUNK_SIZE = 2 * 1024 * 1024;
    while (start < total) {
      if (window.activeUploadControllers[uploadId]?.aborted) {
        await removeUploadFromDB(uploadId);
        return;
      }

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
          signal: uploadController.signal,
        });
      } catch (corsErr) {
        if (window.activeUploadControllers[uploadId]?.aborted) {
          await removeUploadFromDB(uploadId);
          return;
        }
        // Attempt 2: Relay chunk through server
        const relayUrl = `api/upload_chunk.php?upload_url=${encodeURIComponent(uploadUrl)}`;
        response = await fetch(relayUrl, {
          method: 'POST',
          headers: {
            'Content-Range': contentRange,
          },
          body: chunk,
          signal: uploadController.signal,
        });
      }

      if (window.activeUploadControllers[uploadId]?.aborted) {
        await removeUploadFromDB(uploadId);
        return;
      }

      start = end;
      const percent = Math.round((start / total) * 100);
      updateUploadDockProgress(uploadId, percent, `Uploading (${percent}%) &bull; Auto-Resumable`);

      // Persist progress to IndexedDB on each chunk
      await updateUploadInDB(uploadId, { offset: start, percent });

      // Google returns 200 or 201 when upload is completely finished
      if (response.status === 200 || response.status === 201) {
        if (window.activeUploadControllers[uploadId]?.aborted) {
          await removeUploadFromDB(uploadId);
          return;
        }

        const googleFileInfo = await response.json();
        const googleFileId = googleFileInfo.id;

        // Step 4: Record file in database and update Google Account storage quota (ONLY when 100% complete)
        const finishRes = await apiFetch('api/upload_finish.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            google_account_id: googleAccountId,
            google_file_id: googleFileId,
            name: file.name,
            size: file.size,
            mime_type: file.type || 'application/octet-stream',
            folder_id: targetFolderId,
            share_token: shareToken,
          }),
        });
        const finishData = await finishRes.json();

        // Clean up from IndexedDB
        await removeUploadFromDB(uploadId);
        finishUploadDockItem(uploadId);
        delete window.activeUploadControllers[uploadId];

        // Instant optimistic addition to explorer (0ms delay!)
        if (finishData.success && finishData.file) {
          if (state.currentFolderId === targetFolderId) {
            state.files.unshift(finishData.file);
            state.totalUserBytes += file.size;
            renderFileExplorer();
            updateStorageDisplay();
          }
        }

        // Silent background sync
        loadFiles(state.currentFolderId, true);
        break;
      }
    }
  } catch (err) {
    if (window.activeUploadControllers[uploadId]?.aborted) {
      await removeUploadFromDB(uploadId);
      return;
    }
    console.error('Upload error:', err);
    markUploadDockError(uploadId, err.message + ' (Auto-resumes when reconnected)');
  } finally {
    delete window.activeUploadControllers[uploadId];
    activeUploadsCount = Math.max(0, activeUploadsCount - 1);
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
      <div class="flex items-center space-x-2 truncate max-w-[190px]">
        <i class="fa-regular fa-file text-blue-500"></i>
        <span class="font-medium text-slate-800 truncate" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>
      </div>
      <div class="flex items-center space-x-2 shrink-0">
        <span class="text-slate-400 font-mono text-[11px]">${formatBytes(file.size)}</span>
        <button onclick="cancelDirectUpload('${id}')" class="text-slate-400 hover:text-rose-500 p-1 rounded-md hover:bg-slate-100 transition" title="Cancel upload">
          <i class="fa-solid fa-xmark text-xs"></i>
        </button>
      </div>
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
    item = {
      type: 'folder',
      id: folder.id,
      name: folder.name,
      shareToken: folder.share_token,
      isPublic: folder.is_public,
    };
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

async function ctxRename() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();
  const newName = await showInAppPrompt({
    title: `Rename ${target.type === 'folder' ? 'Folder' : 'File'}`,
    message: `Enter new name for "${target.name}":`,
    defaultValue: target.name,
    confirmText: 'Rename',
  });
  if (newName && newName.trim() && newName.trim() !== target.name) {
    try {
      const res = await apiFetch('api/files.php?action=rename', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          type: target.type,
          id: target.id,
          new_name: newName.trim(),
        }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast(`Renamed to "${newName.trim()}"`, 'success');
      } else {
        showInAppToast(data.error || 'Failed to rename', 'error');
      }
    } catch (err) {
      showInAppToast('Rename error: ' + err.message, 'error');
    } finally {
      loadFiles(state.currentFolderId, true);
    }
  }
}

async function ctxDelete() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();

  const ok = await showInAppConfirm({
    title: `Delete ${target.type === 'folder' ? 'Folder' : 'File'}?`,
    message: `Are you sure you want to permanently delete "${target.name}" from Google Drive and CloudDrive?`,
    isDanger: true,
    confirmText: 'Delete Permanently',
  });
  if (!ok) return;

  // Optimistic UI update: immediately remove from screen so user sees instant deletion
  if (target.type === 'file') {
    state.files = state.files.filter(f => f.id !== target.id);
  } else {
    state.folders = state.folders.filter(f => f.id !== target.id);
  }
  renderFileExplorer();

  try {
    const res = await apiFetch('api/files.php?action=delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        type: target.type,
        id: target.id,
      }),
    });
    const data = await res.json();
    if (!data.success) {
      showInAppToast(data.error || 'Failed to delete', 'error');
    } else {
      showInAppToast(`"${target.name}" deleted successfully`, 'success');
    }
  } catch (err) {
    console.error('Delete error:', err);
    showInAppToast('Delete error: ' + err.message, 'error');
  } finally {
    loadFiles(state.currentFolderId, true);
  }
}

async function ctxCopy() {
  if (!currentContextItem || currentContextItem.type !== 'file') return;
  const target = { ...currentContextItem };
  hideContextMenu();
  await apiFetch('api/files.php?action=copy', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ file_id: target.id }),
  });
  showInAppToast(`Copied "${target.name}"`, 'success');
  loadFiles(state.currentFolderId, true);
}

function ctxShare() {
  if (!currentContextItem) return;
  const target = { ...currentContextItem };
  hideContextMenu();
  const modal = document.getElementById('share-modal');
  const titleEl = document.getElementById('share-modal-title');
  const descEl = document.getElementById('share-modal-desc');
  const linkInput = document.getElementById('share-link-input');
  const toggle = document.getElementById('share-public-toggle');

  let shareUrl = '';
  if (target.type === 'folder') {
    if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-folder-open text-amber-500 mr-2"></i> Share Folder "${escapeHtml(target.name)}"`;
    if (descEl) descEl.textContent = 'Anyone with this link can view and download all files inside this folder, including any new uploads in the future!';
    shareUrl = target.shareToken ? `${window.location.origin}/share/${target.shareToken}` : `${window.location.origin}/share`;
  } else {
    if (titleEl) titleEl.innerHTML = `<i class="fa-solid fa-share-nodes text-blue-500 mr-2"></i> Share File "${escapeHtml(target.name)}"`;
    if (descEl) descEl.textContent = 'Anyone with this link can view and download this file directly.';
    shareUrl = `${window.location.origin}/share/${target.shareToken}`;
  }

  linkInput.value = shareUrl;
  toggle.checked = !!target.isPublic;

  currentContextItem = target;
  modal.classList.remove('hidden');
}

async function handleShareToggle() {
  if (!currentContextItem) return;
  const toggle = document.getElementById('share-public-toggle');
  const isPublic = toggle.checked ? 1 : 0;

  const payload = {
    type: currentContextItem.type,
    is_public: isPublic,
  };
  if (currentContextItem.type === 'folder') {
    payload.folder_id = currentContextItem.id;
  } else {
    payload.file_id = currentContextItem.id;
  }

  try {
    const res = await apiFetch('api/files.php?action=toggle_share', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.success && data.share_token) {
      currentContextItem.shareToken = data.share_token;
      const linkInput = document.getElementById('share-link-input');
      if (linkInput) linkInput.value = `${window.location.origin}/share/${data.share_token}`;
    }
    currentContextItem.isPublic = isPublic;

    // Update local state directly so explorer updates immediately without refresh
    if (currentContextItem.type === 'folder') {
      const item = state.folders.find(f => f.id === currentContextItem.id);
      if (item) {
        item.is_public = isPublic;
        if (data.share_token) item.share_token = data.share_token;
      }
    } else {
      const item = state.files.find(f => f.id === currentContextItem.id);
      if (item) {
        item.is_public = isPublic;
        if (data.share_token) item.share_token = data.share_token;
      }
    }
    renderFileExplorer();
    showInAppToast(`Public sharing turned ${isPublic ? 'ON' : 'OFF'}`, isPublic ? 'success' : 'info');
  } catch (err) {
    showInAppToast('Failed to update share setting', 'error');
  } finally {
    loadFiles(state.currentFolderId, true);
  }
}

async function copyShareLink() {
  const toggle = document.getElementById('share-public-toggle');
  const linkInput = document.getElementById('share-link-input');
  if (!linkInput) return;

  // If sharing is not enabled yet, automatically enable it!
  if (toggle && !toggle.checked) {
    toggle.checked = true;
    await handleShareToggle();
  }

  linkInput.select();
  navigator.clipboard.writeText(linkInput.value).then(() => {
    showInAppToast('Share link copied to clipboard! Public sharing is active.', 'success');
  }).catch(() => {
    showInAppToast('Failed to copy share link', 'error');
  });
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
  const nameInput = document.getElementById('new-folder-name');
  const name = nameInput.value.trim();
  if (!name) return;

  const tempId = 'temp_' + Date.now();
  const tempFolder = {
    id: tempId,
    name: name,
    parent_id: state.currentFolderId,
    share_token: '',
    is_public: 0,
    created_at: new Date().toISOString()
  };

  // 1. Instant optimistic visual rendering (0ms delay!)
  state.folders.unshift(tempFolder);
  renderFileExplorer();
  document.getElementById('new-folder-modal').classList.add('hidden');
  nameInput.value = '';

  // 2. Perform backend API call asynchronously
  try {
    const res = await apiFetch('api/files.php?action=create_folder', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name,
        parent_id: state.currentFolderId,
      }),
    });
    const data = await res.json();
    if (data.success && data.folder) {
      const idx = state.folders.findIndex(f => f.id === tempId);
      if (idx !== -1) {
        state.folders[idx] = data.folder;
      }
      renderFileExplorer();
      showInAppToast(`Folder "${name}" created`, 'success');
      loadFiles(state.currentFolderId, true);
    } else {
      // Revert if failed
      state.folders = state.folders.filter(f => f.id !== tempId);
      renderFileExplorer();
      showInAppToast(data.error || 'Failed to create folder', 'error');
    }
  } catch (err) {
    state.folders = state.folders.filter(f => f.id !== tempId);
    renderFileExplorer();
    showInAppToast('Server error creating folder: ' + err.message, 'error');
  }
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
              <button onclick="navigator.clipboard.writeText(this.closest('.bg-slate-900').querySelector('pre').innerText); showInAppToast('Copied code to clipboard!', 'success');" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg text-xs transition">
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
  apiFetch(`api/files.php?action=video_qualities&file_id=${fileId}`)
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

// --- P2P OFFLINE TRANSFER (SHAREIT & SNAPDROP ARCHITECTURE) ---
function openP2PShareModal(tab) {
  const modal = document.getElementById('p2p-share-modal');
  if (modal) {
    modal.classList.remove('hidden');
    if (typeof window.setP2PTab === 'function') {
      window.setP2PTab(tab || 'send');
    }
  }
}

function closeP2PShareModal() {
  const modal = document.getElementById('p2p-share-modal');
  if (modal) {
    modal.classList.add('hidden');
  }
  const url = new URL(window.location.href);
  if (url.searchParams.has('join')) {
    url.searchParams.delete('join');
    window.history.replaceState(null, '', url.pathname + (url.search || ''));
  }
}

// Backward compatibility aliases
const openQRFilesModal = openP2PShareModal;
const closeQRFilesModal = closeP2PShareModal;

