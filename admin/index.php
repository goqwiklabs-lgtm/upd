<?php
session_start();
require_once __DIR__ . '/../config/db.php';

$pdo = getDBConnection();
$isAdmin = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
$adminEmail = $_SESSION['email'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Control Panel - CloudDrive</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 text-slate-900 font-sans antialiased min-h-screen flex flex-col">

<?php if (!$isAdmin): ?>
  <!-- ADMIN LOGIN VIEW -->
  <div class="flex-1 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-xl border border-slate-200 space-y-6">
      <div class="text-center">
        <div class="w-14 h-14 rounded-2xl bg-amber-500 text-white flex items-center justify-center mx-auto mb-3 shadow-lg shadow-amber-500/25 text-2xl">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">Admin Portal Login</h1>
        <p class="text-xs text-slate-400 mt-1">CloudDrive Multi-Account Storage Controller</p>
      </div>

      <div id="login-error" class="hidden p-3 bg-red-50 text-red-600 text-xs rounded-xl border border-red-200"></div>

      <form onsubmit="handleAdminLogin(event)" class="space-y-4 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Admin Email</label>
          <input type="text" id="admin-identifier" required value="omkumar.working@gmail.com" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-amber-500 text-sm">
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Password</label>
          <input type="password" id="admin-password" required placeholder="••••••••" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-amber-500 text-sm">
        </div>

        <button type="submit" id="btn-login" class="w-full py-3.5 bg-amber-500 hover:bg-amber-600 text-white font-bold text-sm rounded-xl transition shadow-md shadow-amber-500/20">
          Sign In to Admin Panel
        </button>
      </form>

      <div class="pt-3 border-t border-slate-100 text-center">
        <a href="../" class="text-xs text-slate-400 hover:text-slate-600 transition">
          <i class="fa-solid fa-arrow-left mr-1"></i> Back to CloudDrive Home
        </a>
      </div>
    </div>
  </div>

  <script>
    async function handleAdminLogin(e) {
      e.preventDefault();
      const identifier = document.getElementById('admin-identifier').value.trim();
      const password = document.getElementById('admin-password').value;
      const btn = document.getElementById('btn-login');
      const err = document.getElementById('login-error');
      err.classList.add('hidden');

      btn.disabled = true;
      btn.textContent = 'Verifying...';

      try {
        const res = await fetch('../api/auth.php?action=login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ identifier, password }),
        });
        const data = await res.json();
        if (data.success && data.user.role === 'admin') {
          window.location.reload();
        } else if (data.success) {
          err.textContent = 'Logged in user is not an administrator.';
          err.classList.remove('hidden');
        } else {
          err.textContent = data.error || 'Invalid admin credentials.';
          err.classList.remove('hidden');
        }
      } catch (e) {
        err.textContent = 'Server connection error.';
        err.classList.remove('hidden');
      } finally {
        btn.disabled = false;
        btn.textContent = 'Sign In to Admin Panel';
      }
    }
  </script>

<?php else: ?>
  <!-- AUTHENTICATED ADMIN DASHBOARD -->
  <!-- Admin Navbar -->
  <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between sticky top-0 z-40 shadow-xs">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shadow-md shadow-amber-500/20">
        <i class="fa-solid fa-shield-halved text-lg"></i>
      </div>
      <div>
        <h1 class="font-bold text-base text-slate-800">CloudDrive Admin Control</h1>
        <p class="text-[11px] text-slate-400">Logged in: <strong class="text-slate-600"><?= htmlspecialchars($adminEmail) ?></strong></p>
      </div>
    </div>

    <div class="flex items-center space-x-3">
      <a href="../" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-cloud text-blue-500"></i>
        <span>User App</span>
      </a>
      <button onclick="adminLogout()" class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
        <span>Logout</span>
      </button>
    </div>
  </header>

  <!-- Main Content -->
  <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-8 space-y-8">

    <!-- OVERVIEW METRICS -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Connected Accounts</span>
          <i class="fa-solid fa-envelope text-blue-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-accounts">0</div>
        <div class="text-[11px] text-slate-400 mt-1">13 GB pool limit per account</div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Storage Pool Used</span>
          <i class="fa-solid fa-hard-drive text-amber-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-storage-used">0 GB</div>
        <div class="text-[11px] text-slate-400 mt-1" id="stat-storage-total">of 0 GB pool capacity</div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Files</span>
          <i class="fa-solid fa-cloud-arrow-up text-emerald-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-files">0</div>
        <div class="text-[11px] text-slate-400 mt-1">Uploaded across all users</div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Users</span>
          <i class="fa-solid fa-users text-indigo-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-users">0</div>
        <div class="text-[11px] text-slate-400 mt-1">Registered in database</div>
      </div>
    </section>

    <!-- ⚡ ONE-PASTE KEY CONNECT SECTION -->
    <section class="bg-gradient-to-br from-blue-900 to-indigo-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <span class="px-3 py-1 bg-blue-500/30 text-blue-200 text-xs font-bold rounded-full uppercase tracking-wider">Quick Connect</span>
          <h2 class="text-xl sm:text-2xl font-bold mt-2">Connect Google Drive by Key / Token</h2>
          <p class="text-xs text-blue-200 mt-1">Just paste your Refresh Token, Service Account Key, or OAuth Credentials JSON below to connect a new Gmail account.</p>
        </div>
        <button onclick="toggleMasterSettings()" class="px-3.5 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl text-xs font-semibold transition border border-white/10 shrink-0">
          <i class="fa-solid fa-gear mr-1.5"></i> Master Client ID & Secret
        </button>
      </div>

      <!-- Collapsible Master OAuth Settings -->
      <div id="master-settings-card" class="hidden bg-slate-900/80 border border-white/10 rounded-2xl p-5 space-y-3 text-xs">
        <p class="font-semibold text-amber-300"><i class="fa-solid fa-key mr-1.5"></i> One-Time Master Project Credentials (Optional):</p>
        <p class="text-[11px] text-slate-300">Set your Google Cloud Client ID and Secret once. Afterwards, connecting any Gmail account only requires pasting its <strong>Refresh Token</strong>!</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
          <input type="text" id="master-client-id" placeholder="Client ID (xxxx.apps.googleusercontent.com)" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-white outline-none focus:border-blue-400 font-mono">
          <input type="password" id="master-client-secret" placeholder="Client Secret (GOCSPX-xxxxxx)" class="px-3 py-2 bg-slate-800 border border-slate-700 rounded-xl text-white outline-none focus:border-blue-400 font-mono">
        </div>
        <div class="flex justify-end">
          <button onclick="saveMasterSettings()" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-semibold transition">
            Save Project Credentials
          </button>
        </div>
      </div>

      <!-- Smart Key Paste Box -->
      <div class="space-y-3">
        <div id="key-feedback" class="hidden p-3 rounded-xl text-xs"></div>
        <div class="flex flex-col sm:flex-row gap-3">
          <textarea id="quick-key-input" rows="2" placeholder="Paste your Google Drive Key, Refresh Token (1//04...), or Credentials JSON here..." class="flex-1 px-4 py-3 bg-white/10 border border-white/20 focus:border-white focus:bg-white/15 rounded-2xl text-xs text-white placeholder-blue-200/60 outline-none font-mono transition"></textarea>
          <button onclick="handleConnectKey()" id="btn-connect-key" class="px-6 py-3 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold text-sm rounded-2xl transition shadow-lg shadow-emerald-500/20 shrink-0 flex items-center justify-center space-x-2">
            <i class="fa-solid fa-plug-circle-plus"></i>
            <span>Connect Account</span>
          </button>
        </div>
        <p class="text-[11px] text-blue-200/70">
          💡 The system automatically verifies with Google Drive API, reads the Gmail email address, sets the <strong>13 GB limit</strong>, and adds it to the storage pool.
        </p>
      </div>
    </section>

    <!-- TABS -->
    <div class="flex border-b border-slate-200 space-x-6 text-sm font-semibold">
      <button onclick="switchTab('accounts')" id="tab-btn-accounts" class="pb-3 border-b-2 border-blue-600 text-blue-600 transition flex items-center space-x-2">
        <i class="fa-solid fa-cloud"></i>
        <span>Connected Gmail Pool (13 GB Limit)</span>
      </button>
      <button onclick="switchTab('files')" id="tab-btn-files" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition flex items-center space-x-2">
        <i class="fa-solid fa-folder-tree"></i>
        <span>Global Files Manager</span>
      </button>
      <button onclick="switchTab('users')" id="tab-btn-users" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition flex items-center space-x-2">
        <i class="fa-solid fa-users"></i>
        <span>Users List</span>
      </button>
    </div>

    <!-- TAB 1: ACCOUNTS POOL -->
    <section id="tab-content-accounts" class="space-y-4">
      <div id="accounts-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <!-- Dynamically loaded accounts -->
      </div>
    </section>

    <!-- TAB 2: GLOBAL FILES -->
    <section id="tab-content-files" class="space-y-4 hidden">
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">File Name</th>
                <th class="p-3.5">Size</th>
                <th class="p-3.5">User</th>
                <th class="p-3.5">Stored On Gmail</th>
                <th class="p-3.5">Date</th>
                <th class="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody id="global-files-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically loaded -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- TAB 3: USERS LIST -->
    <section id="tab-content-users" class="space-y-4 hidden">
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">User</th>
                <th class="p-3.5">Email</th>
                <th class="p-3.5">Role</th>
                <th class="p-3.5">Files Count</th>
                <th class="p-3.5">Storage Used</th>
                <th class="p-3.5">Registered</th>
              </tr>
            </thead>
            <tbody id="users-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically loaded -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

  </main>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      loadStats();
      loadAccounts();
    });

    function switchTab(tab) {
      ['accounts', 'files', 'users'].forEach(t => {
        const btn = document.getElementById(`tab-btn-${t}`);
        const content = document.getElementById(`tab-content-${t}`);
        if (t === tab) {
          btn.className = 'pb-3 border-b-2 border-blue-600 text-blue-600 transition flex items-center space-x-2';
          content.classList.remove('hidden');
        } else {
          btn.className = 'pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition flex items-center space-x-2';
          content.classList.add('hidden');
        }
      });
      if (tab === 'files') loadFiles();
      if (tab === 'users') loadUsers();
    }

    async function loadStats() {
      const res = await fetch('../api/admin.php?action=stats');
      const data = await res.json();
      if (data.success) {
        const s = data.stats;
        document.getElementById('stat-accounts').textContent = s.active_google_accounts;
        const usedGb = (s.pool_used_bytes / (1024 * 1024 * 1024)).toFixed(2);
        const totalGb = (s.pool_total_bytes / (1024 * 1024 * 1024)).toFixed(1);
        document.getElementById('stat-storage-used').textContent = `${usedGb} GB`;
        document.getElementById('stat-storage-total').textContent = `of ${totalGb} GB pool capacity`;
        document.getElementById('stat-files').textContent = s.total_files;
        document.getElementById('stat-users').textContent = s.total_users;
      }
    }

    async function loadAccounts() {
      const grid = document.getElementById('accounts-grid');
      const res = await fetch('../api/admin.php?action=accounts_list');
      const data = await res.json();
      if (data.success) {
        if (data.master_settings && data.master_settings.client_id) {
          document.getElementById('master-client-id').value = data.master_settings.client_id;
        }

        const accounts = data.accounts || [];
        if (accounts.length === 0) {
          grid.innerHTML = `
            <div class="col-span-full py-12 text-center text-slate-400 bg-white border border-slate-200 rounded-3xl">
              <i class="fa-solid fa-cloud text-4xl text-blue-400 mb-3"></i>
              <h3 class="font-bold text-slate-700">No Google Accounts Connected Yet</h3>
              <p class="text-xs text-slate-400 mt-1">Paste your Google Drive Key or Refresh Token above to connect your first 13 GB storage pool account.</p>
            </div>
          `;
          return;
        }

        grid.innerHTML = accounts.map(acc => {
          const usedGb = (acc.used_storage_bytes / (1024 * 1024 * 1024)).toFixed(2);
          const percent = Math.min(100, Math.round((acc.used_storage_bytes / acc.storage_limit_bytes) * 100));
          const isFull = percent >= 100;
          return `
            <div class="bg-white border ${isFull ? 'border-amber-300' : 'border-slate-200'} rounded-2xl p-5 shadow-xs space-y-4">
              <div class="flex items-start justify-between">
                <div class="flex items-center space-x-3">
                  <div class="w-10 h-10 rounded-xl ${acc.is_active ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400'} flex items-center justify-center text-lg">
                    <i class="fa-solid fa-envelope"></i>
                  </div>
                  <div>
                    <h3 class="font-bold text-sm text-slate-800 truncate max-w-[170px]" title="${acc.account_email}">${acc.account_email}</h3>
                    <div class="flex items-center space-x-1.5 text-[11px] text-slate-400">
                      <span class="w-2 h-2 rounded-full ${acc.is_active ? (isFull ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-slate-300'}"></span>
                      <span>${acc.is_active ? (isFull ? '13 GB Cap Reached' : 'Active') : 'Disabled'}</span>
                    </div>
                  </div>
                </div>
                <div class="flex items-center space-x-1 text-slate-400">
                  <button onclick="syncQuota(${acc.id})" title="Sync quota with Google" class="p-1.5 hover:text-blue-600 rounded-lg transition">
                    <i class="fa-solid fa-rotate"></i>
                  </button>
                  <button onclick="deleteAccount(${acc.id})" title="Disconnect" class="p-1.5 hover:text-red-500 rounded-lg transition">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </div>

              <!-- 13 GB Progress Meter -->
              <div class="space-y-1.5">
                <div class="flex items-center justify-between text-xs">
                  <span class="text-slate-500">Storage Used</span>
                  <span class="font-semibold text-slate-800">${usedGb} GB / 13 GB (${percent}%)</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                  <div class="h-2 rounded-full transition-all duration-300 ${percent > 90 ? 'bg-amber-500' : 'bg-blue-600'}" style="width: ${percent}%"></div>
                </div>
                <div class="flex items-center justify-between text-[10px] text-slate-400">
                  <span>${acc.files_count} files stored</span>
                  <span>2 GB safety buffer reserved</span>
                </div>
              </div>

              <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-500">Enable in Pool</span>
                <input type="checkbox" ${acc.is_active ? 'checked' : ''} onchange="toggleActive(${acc.id}, this.checked)" class="toggle cursor-pointer">
              </div>
            </div>
          `;
        }).join('');
      }
    }

    // Connect with Key
    async function handleConnectKey() {
      const key = document.getElementById('quick-key-input').value.trim();
      const btn = document.getElementById('btn-connect-key');
      const fb = document.getElementById('key-feedback');
      fb.classList.add('hidden');

      if (!key) {
        fb.className = 'p-3 rounded-xl text-xs bg-red-500/20 text-red-200 border border-red-500/30';
        fb.textContent = 'Please paste your Google Drive Key, Refresh Token, or Credentials JSON.';
        fb.classList.remove('hidden');
        return;
      }

      btn.disabled = true;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Verifying Key with Google...</span>`;

      try {
        const res = await fetch('../api/admin.php?action=account_add_key', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ key }),
        });
        const data = await res.json();
        if (data.success) {
          fb.className = 'p-3 rounded-xl text-xs bg-emerald-500/20 text-emerald-200 border border-emerald-500/30';
          fb.innerHTML = `✓ Successfully connected <strong>${data.account_email}</strong> to the 13 GB storage pool!`;
          fb.classList.remove('hidden');
          document.getElementById('quick-key-input').value = '';
          loadStats();
          loadAccounts();
        } else {
          fb.className = 'p-3 rounded-xl text-xs bg-red-500/20 text-red-200 border border-red-500/30';
          fb.textContent = data.error || 'Failed to verify key.';
          fb.classList.remove('hidden');
        }
      } catch (e) {
        fb.className = 'p-3 rounded-xl text-xs bg-red-500/20 text-red-200 border border-red-500/30';
        fb.textContent = 'Server connection error.';
        fb.classList.remove('hidden');
      } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-plug-circle-plus"></i> <span>Connect Account</span>`;
      }
    }

    function toggleMasterSettings() {
      const el = document.getElementById('master-settings-card');
      el.classList.toggle('hidden');
    }

    async function saveMasterSettings() {
      const clientId = document.getElementById('master-client-id').value.trim();
      const clientSecret = document.getElementById('master-client-secret').value.trim();
      await fetch('../api/admin.php?action=save_master_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ client_id: clientId, client_secret: clientSecret }),
      });
      alert('Project credentials saved successfully! Now you can paste Refresh Tokens directly.');
      toggleMasterSettings();
    }

    async function syncQuota(id) {
      await fetch('../api/admin.php?action=account_sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      loadStats();
      loadAccounts();
    }

    async function toggleActive(id, isActive) {
      await fetch('../api/admin.php?action=account_toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, is_active: isActive ? 1 : 0 }),
      });
      loadStats();
      loadAccounts();
    }

    async function deleteAccount(id) {
      if (!confirm('Are you sure you want to disconnect this Google Account?')) return;
      const res = await fetch('../api/admin.php?action=account_delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!data.success) alert(data.error);
      loadStats();
      loadAccounts();
    }

    async function loadFiles() {
      const tbody = document.getElementById('global-files-tbody');
      tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400">Loading files...</td></tr>`;
      const res = await fetch('../api/admin.php?action=all_files');
      const data = await res.json();
      if (data.success) {
        const files = data.files || [];
        if (files.length === 0) {
          tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400">No files uploaded yet.</td></tr>`;
          return;
        }
        tbody.innerHTML = files.map(f => `
          <tr class="hover:bg-slate-50 transition">
            <td class="p-3.5 font-medium text-slate-800 flex items-center space-x-2">
              <i class="fa-regular fa-file text-blue-500"></i>
              <span class="truncate max-w-[200px]">${f.name}</span>
            </td>
            <td class="p-3.5 text-slate-500">${(f.size_bytes / (1024 * 1024)).toFixed(1)} MB</td>
            <td class="p-3.5 text-slate-600 font-medium">${f.username}</td>
            <td class="p-3.5 text-slate-500 font-mono text-[11px]">${f.google_email}</td>
            <td class="p-3.5 text-slate-400">${new Date(f.created_at).toLocaleDateString()}</td>
            <td class="p-3.5 text-right space-x-2">
              <a href="../stream.php?id=${f.id}" target="_blank" class="text-blue-600 font-semibold">Preview</a>
            </td>
          </tr>
        `).join('');
      }
    }

    async function loadUsers() {
      const tbody = document.getElementById('users-tbody');
      tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400">Loading users...</td></tr>`;
      const res = await fetch('../api/admin.php?action=all_users');
      const data = await res.json();
      if (data.success) {
        tbody.innerHTML = (data.users || []).map(u => `
          <tr class="hover:bg-slate-50 transition">
            <td class="p-3.5 font-bold text-slate-800">${u.username}</td>
            <td class="p-3.5 text-slate-500">${u.email}</td>
            <td class="p-3.5"><span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${u.role === 'admin' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100'}">${u.role}</span></td>
            <td class="p-3.5 text-slate-600">${u.files_count}</td>
            <td class="p-3.5 text-slate-600">${(u.total_bytes / (1024 * 1024)).toFixed(1)} MB</td>
            <td class="p-3.5 text-slate-400">${new Date(u.created_at).toLocaleDateString()}</td>
          </tr>
        `).join('');
      }
    }

    async function adminLogout() {
      await fetch('../api/auth.php?action=logout');
      window.location.reload();
    }
  </script>
<?php endif; ?>

</body>
</html>
