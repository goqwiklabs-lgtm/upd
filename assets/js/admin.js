// ========================================================
// Admin Panel Management Controller
// ========================================================

document.addEventListener('DOMContentLoaded', () => {
  loadAdminStats();
  loadGoogleAccounts();
});

function switchAdminTab(tab) {
  const tabs = ['accounts', 'files', 'users'];
  tabs.forEach(t => {
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

  if (tab === 'files') loadGlobalFiles();
  if (tab === 'users') loadUsersList();
}

async function loadAdminStats() {
  try {
    const res = await fetch('api/admin.php?action=stats');
    if (res.status === 403) {
      alert('Access denied. Administrator privileges required.');
      window.location.href = 'index.php';
      return;
    }
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
  } catch (err) {
    console.error('Failed to load admin stats:', err);
  }
}

async function loadGoogleAccounts() {
  const grid = document.getElementById('accounts-grid');
  grid.innerHTML = `<div class="col-span-full py-8 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin text-xl mb-2"></i><p>Loading accounts...</p></div>`;

  try {
    const res = await fetch('api/admin.php?action=accounts_list');
    const data = await res.json();
    if (data.success) {
      const accounts = data.accounts || [];
      if (accounts.length === 0) {
        grid.innerHTML = `
          <div class="col-span-full py-12 text-center text-slate-400 bg-white border border-slate-200 rounded-2xl">
            <i class="fa-solid fa-triangle-exclamation text-3xl text-amber-500 mb-2"></i>
            <p class="font-bold text-slate-700">No Google Storage Accounts Connected</p>
            <p class="text-xs text-slate-400 mt-1">Users cannot upload files until you connect at least one Gmail account.</p>
            <button onclick="openAddAccountModal()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-semibold">
              Connect First Gmail Account
            </button>
          </div>
        `;
        return;
      }

      grid.innerHTML = accounts.map(acc => {
        const usedGb = (acc.used_storage_bytes / (1024 * 1024 * 1024)).toFixed(2);
        const limitGb = (acc.storage_limit_bytes / (1024 * 1024 * 1024)).toFixed(0);
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
                  <h3 class="font-bold text-sm text-slate-800 truncate max-w-[170px]" title="${escapeHtml(acc.account_email)}">${escapeHtml(acc.account_email)}</h3>
                  <div class="flex items-center space-x-1.5 text-[11px] text-slate-400">
                    <span class="w-2 h-2 rounded-full ${acc.is_active ? (isFull ? 'bg-amber-500' : 'bg-emerald-500') : 'bg-slate-300'}"></span>
                    <span>${acc.is_active ? (isFull ? 'Storage Full (13 GB Limit)' : 'Active') : 'Disabled'}</span>
                  </div>
                </div>
              </div>
              <div class="flex items-center space-x-1">
                <button onclick="syncAccountQuota(${acc.id})" title="Sync quota with Google Drive" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg transition">
                  <i class="fa-solid fa-rotate"></i>
                </button>
                <button onclick="deleteAccount(${acc.id})" title="Disconnect account" class="p-1.5 text-slate-400 hover:text-red-500 rounded-lg transition">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
            </div>

            <!-- Storage Progress Meter (Towards 13 GB) -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between text-xs">
                <span class="text-slate-500">Storage Used</span>
                <span class="font-semibold text-slate-800">${usedGb} GB / ${limitGb} GB (${percent}%)</span>
              </div>
              <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                <div class="h-2 rounded-full transition-all duration-300 ${percent > 90 ? 'bg-amber-500' : 'bg-blue-600'}" style="width: ${percent}%"></div>
              </div>
              <div class="flex items-center justify-between text-[10px] text-slate-400">
                <span>${acc.files_count} files stored</span>
                <span>2 GB safety buffer reserved</span>
              </div>
            </div>

            <!-- Toggle Button -->
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
              <span class="text-xs text-slate-500">Enable in Pool</span>
              <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" ${acc.is_active ? 'checked' : ''} onchange="toggleAccountActive(${acc.id}, this.checked)" class="sr-only peer">
                <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:width-4 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>
          </div>
        `;
      }).join('');
    }
  } catch (err) {
    grid.innerHTML = `<div class="col-span-full py-8 text-center text-red-500">Failed to load accounts.</div>`;
  }
}

// --- ACCOUNT ACTIONS ---
function openAddAccountModal() {
  document.getElementById('add-account-modal').classList.remove('hidden');
  document.getElementById('modal-error').classList.add('hidden');
}

function closeAddAccountModal() {
  document.getElementById('add-account-modal').classList.add('hidden');
}

async function handleAddAccount(e) {
  e.preventDefault();
  const form = e.target;
  const btn = document.getElementById('btn-save-account');
  const errEl = document.getElementById('modal-error');
  errEl.classList.add('hidden');

  const payload = {
    account_email: form.account_email.value.trim(),
    client_id: form.client_id.value.trim(),
    client_secret: form.client_secret.value.trim(),
    refresh_token: form.refresh_token.value.trim(),
  };

  btn.disabled = true;
  btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> <span>Verifying with Google...</span>`;

  try {
    const res = await fetch('api/admin.php?action=account_add', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const data = await res.json();

    if (data.success) {
      closeAddAccountModal();
      form.reset();
      loadAdminStats();
      loadGoogleAccounts();
    } else {
      errEl.textContent = data.error || 'Failed to add account.';
      errEl.classList.remove('hidden');
    }
  } catch (err) {
    errEl.textContent = 'Server error connecting to Google.';
    errEl.classList.remove('hidden');
  } finally {
    btn.disabled = false;
    btn.innerHTML = `<span>Verify & Connect</span>`;
  }
}

async function toggleAccountActive(id, isActive) {
  await fetch('api/admin.php?action=account_toggle', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, is_active: isActive ? 1 : 0 }),
  });
  loadAdminStats();
  loadGoogleAccounts();
}

async function syncAccountQuota(id) {
  await fetch('api/admin.php?action=account_sync', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  });
  loadAdminStats();
  loadGoogleAccounts();
}

async function deleteAccount(id) {
  if (!confirm('Are you sure you want to disconnect this Google Account?')) return;
  const res = await fetch('api/admin.php?action=account_delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  });
  const data = await res.json();
  if (!data.success) {
    alert(data.error);
    return;
  }
  loadAdminStats();
  loadGoogleAccounts();
}

// --- GLOBAL FILES TAB ---
async function loadGlobalFiles() {
  const tbody = document.getElementById('global-files-tbody');
  tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400">Loading all files...</td></tr>`;

  try {
    const res = await fetch('api/admin.php?action=all_files');
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
            <span class="truncate max-w-[200px]" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span>
          </td>
          <td class="p-3.5 text-slate-500">${formatBytes(f.size_bytes)}</td>
          <td class="p-3.5 text-slate-600 font-medium">${escapeHtml(f.username)}</td>
          <td class="p-3.5 text-slate-500 font-mono text-[11px]">${escapeHtml(f.google_email)}</td>
          <td class="p-3.5 text-slate-400">${new Date(f.created_at).toLocaleDateString()}</td>
          <td class="p-3.5 text-right space-x-2">
            <a href="stream.php?id=${f.id}" target="_blank" class="text-blue-600 hover:text-blue-800 font-semibold">Preview</a>
            <button onclick="adminDeleteFile(${f.id})" class="text-red-500 hover:text-red-700 font-semibold">Delete</button>
          </td>
        </tr>
      `).join('');
    }
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-red-500">Failed to load files.</td></tr>`;
  }
}

async function adminDeleteFile(fileId) {
  if (!confirm('Are you sure you want to delete this file permanently from Google Drive and database?')) return;
  await fetch('api/files.php?action=delete', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type: 'file', id: fileId }),
  });
  loadGlobalFiles();
  loadAdminStats();
}

// --- USERS LIST TAB ---
async function loadUsersList() {
  const tbody = document.getElementById('users-tbody');
  tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-slate-400">Loading users...</td></tr>`;

  try {
    const res = await fetch('api/admin.php?action=all_users');
    const data = await res.json();
    if (data.success) {
      const users = data.users || [];
      tbody.innerHTML = users.map(u => `
        <tr class="hover:bg-slate-50 transition">
          <td class="p-3.5 font-semibold text-slate-800 flex items-center space-x-2">
            <div class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-[10px] uppercase">${escapeHtml(u.username[0])}</div>
            <span>${escapeHtml(u.username)}</span>
          </td>
          <td class="p-3.5 text-slate-500">${escapeHtml(u.email)}</td>
          <td class="p-3.5">
            <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${u.role === 'admin' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}">
              ${u.role}
            </span>
          </td>
          <td class="p-3.5 text-slate-600">${u.files_count}</td>
          <td class="p-3.5 text-slate-600">${formatBytes(u.total_bytes)}</td>
          <td class="p-3.5 text-slate-400">${new Date(u.created_at).toLocaleDateString()}</td>
        </tr>
      `).join('');
    }
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-red-500">Failed to load users.</td></tr>`;
  }
}

async function adminLogout() {
  await fetch('api/auth.php?action=logout');
  window.location.href = 'index.php';
}

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
