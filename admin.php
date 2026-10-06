<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Panel - CloudDrive Management</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-slate-100 text-slate-900 font-sans antialiased min-h-screen flex flex-col">

  <!-- Admin Navbar -->
  <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between sticky top-0 z-40 shadow-xs">
    <div class="flex items-center space-x-3">
      <div class="w-9 h-9 rounded-xl bg-amber-500 text-white flex items-center justify-center shadow-md shadow-amber-500/20">
        <i class="fa-solid fa-shield-halved"></i>
      </div>
      <div>
        <h1 class="font-bold text-base text-slate-800">CloudDrive Admin Panel</h1>
        <p class="text-[11px] text-slate-400">Multi-Account Google Storage Pool Controller</p>
      </div>
    </div>

    <div class="flex items-center space-x-3">
      <a href="index.php" class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-house text-slate-400"></i>
        <span>User Dashboard</span>
      </a>
      <button onclick="adminLogout()" class="px-3.5 py-2 bg-red-50 hover:bg-red-100 text-red-600 font-medium text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
        <span>Logout</span>
      </button>
    </div>
  </header>

  <!-- Main Admin Layout -->
  <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-8 space-y-8">

    <!-- OVERVIEW STATS -->
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
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Total Uploads</span>
          <i class="fa-solid fa-cloud-arrow-up text-emerald-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-files">0</div>
        <div class="text-[11px] text-slate-400 mt-1">Files across all users</div>
      </div>

      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Registered Users</span>
          <i class="fa-solid fa-users text-indigo-500 text-lg"></i>
        </div>
        <div class="text-2xl font-bold text-slate-800" id="stat-users">0</div>
        <div class="text-[11px] text-slate-400 mt-1">Active platform accounts</div>
      </div>
    </section>

    <!-- TABS -->
    <div class="flex border-b border-slate-200 space-x-6 text-sm font-semibold">
      <button onclick="switchAdminTab('accounts')" id="tab-btn-accounts" class="pb-3 border-b-2 border-blue-600 text-blue-600 transition flex items-center space-x-2">
        <i class="fa-solid fa-cloud"></i>
        <span>Google Storage Pool</span>
      </button>
      <button onclick="switchAdminTab('files')" id="tab-btn-files" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition flex items-center space-x-2">
        <i class="fa-solid fa-folder-tree"></i>
        <span>Global File Manager</span>
      </button>
      <button onclick="switchAdminTab('users')" id="tab-btn-users" class="pb-3 border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition flex items-center space-x-2">
        <i class="fa-solid fa-users"></i>
        <span>Users List</span>
      </button>
    </div>

    <!-- TAB 1: GOOGLE ACCOUNTS POOL -->
    <section id="tab-content-accounts" class="space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
          <h2 class="text-lg font-bold text-slate-800">Connected Gmail Accounts</h2>
          <p class="text-xs text-slate-500">Each account is capped at 13 GB. Once an account reaches 13 GB, files automatically roll over to the next account.</p>
        </div>
        <button onclick="openAddAccountModal()" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-medium text-xs rounded-xl transition shadow-md shadow-blue-500/20 flex items-center space-x-2">
          <i class="fa-solid fa-plus"></i>
          <span>Connect New Gmail Account</span>
        </button>
      </div>

      <div id="accounts-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <!-- Dynamically loaded accounts -->
      </div>
    </section>

    <!-- TAB 2: GLOBAL FILE MANAGER -->
    <section id="tab-content-files" class="space-y-4 hidden">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800">All Uploaded Files (Global)</h2>
        <span class="text-xs text-slate-400">Admin can access or delete any uploaded file</span>
      </div>

      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">File Name</th>
                <th class="p-3.5">Size</th>
                <th class="p-3.5">Uploaded By</th>
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
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-bold text-slate-800">Registered Platform Users</h2>
      </div>

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

  <!-- ADD GOOGLE ACCOUNT MODAL -->
  <div id="add-account-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-lg w-full p-6 sm:p-8 shadow-2xl border border-slate-100 space-y-6">
      <div class="flex items-center justify-between">
        <h3 class="text-base font-bold text-slate-800 flex items-center">
          <i class="fa-solid fa-cloud text-blue-500 mr-2"></i> Connect Google Storage Account
        </h3>
        <button onclick="closeAddAccountModal()" class="text-slate-400 hover:text-slate-600">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="p-3.5 bg-blue-50 border border-blue-200/80 rounded-2xl text-[11px] text-blue-800 space-y-1">
        <p class="font-bold flex items-center"><i class="fa-solid fa-circle-info mr-1.5"></i> Google Cloud Credentials Required</p>
        <p>1. Create an OAuth 2.0 Web Application in <a href="https://console.cloud.google.com/" target="_blank" class="underline font-semibold">Google Cloud Console</a>.</p>
        <p>2. Enable the <strong>Google Drive API</strong>.</p>
        <p>3. Generate your Refresh Token with scope <code>https://www.googleapis.com/auth/drive</code>.</p>
      </div>

      <div id="modal-error" class="hidden p-3 bg-red-50 text-red-600 text-xs rounded-xl border border-red-200"></div>

      <form onsubmit="handleAddAccount(event)" class="space-y-4 text-xs">
        <div>
          <label class="block font-medium text-slate-700 mb-1">Gmail / Account Email</label>
          <input type="email" name="account_email" placeholder="storage1@gmail.com" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 transition">
        </div>
        <div>
          <label class="block font-medium text-slate-700 mb-1">OAuth 2.0 Client ID</label>
          <input type="text" name="client_id" placeholder="xxxx.apps.googleusercontent.com" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 transition font-mono">
        </div>
        <div>
          <label class="block font-medium text-slate-700 mb-1">OAuth 2.0 Client Secret</label>
          <input type="password" name="client_secret" placeholder="GOCSPX-xxxxxx" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 transition font-mono">
        </div>
        <div>
          <label class="block font-medium text-slate-700 mb-1">OAuth 2.0 Refresh Token</label>
          <textarea name="refresh_token" rows="2" placeholder="1//04xxxxxx" required class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 transition font-mono"></textarea>
        </div>
        <div class="flex items-center justify-end space-x-2 pt-2">
          <button type="button" onclick="closeAddAccountModal()" class="px-4 py-2 font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancel</button>
          <button type="submit" id="btn-save-account" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-xl transition shadow-xs flex items-center space-x-1.5">
            <span>Verify & Connect</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/admin.js"></script>
</body>
</html>
