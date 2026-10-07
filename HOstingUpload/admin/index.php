<?php
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
  </style>
</head>
<body class="bg-slate-100 text-slate-900 font-sans antialiased min-h-screen flex flex-col">

<?php if (!$isAdmin): ?>
  <!-- ==================== ADMIN LOGIN VIEW ==================== -->
  <div class="flex-1 flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-8 shadow-xl border border-slate-200 space-y-6">
      <div class="text-center">
        <div class="w-14 h-14 rounded-2xl bg-amber-500 text-white flex items-center justify-center mx-auto mb-3 shadow-lg shadow-amber-500/25 text-2xl">
          <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">Admin Portal Login</h1>
        <p class="text-xs text-slate-400 mt-1">CloudDrive Multi-Account Storage & Management Console</p>
      </div>

      <div id="login-error" class="hidden p-3 bg-red-50 text-red-600 text-xs rounded-xl border border-red-200"></div>

      <form onsubmit="handleAdminLogin(event)" class="space-y-4 text-xs">
        <div>
          <label class="block font-semibold text-slate-700 mb-1">Admin Email / Username</label>
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
  <!-- ==================== AUTHENTICATED ADMIN DASHBOARD ==================== -->
  
  <!-- Header / Navbar -->
  <header class="bg-white border-b border-slate-200 px-4 sm:px-8 py-3.5 flex items-center justify-between sticky top-0 z-30 shadow-xs">
    <div class="flex items-center space-x-3">
      <div class="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shadow-md shadow-amber-500/20 text-lg">
        <i class="fa-solid fa-shield-halved"></i>
      </div>
      <div>
        <div class="flex items-center space-x-2">
          <h1 class="font-bold text-base text-slate-800">CloudDrive Admin Control</h1>
          <span class="px-2 py-0.5 bg-amber-100 text-amber-700 text-[10px] font-bold rounded-md uppercase tracking-wider">SuperAdmin</span>
        </div>
        <p class="text-[11px] text-slate-400">Logged in: <strong class="text-slate-600"><?= htmlspecialchars($adminEmail) ?></strong></p>
      </div>
    </div>

    <div class="flex items-center space-x-2 sm:space-x-3">
      <a href="../" target="_blank" class="px-3 sm:px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-cloud text-blue-500"></i>
        <span class="hidden sm:inline">User App</span>
      </a>
      <a href="../temp.php" target="_blank" class="px-3 sm:px-4 py-2 bg-amber-50 hover:bg-amber-100 text-amber-700 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-stopwatch text-amber-500"></i>
        <span class="hidden sm:inline">Temp Area</span>
      </a>
      <button onclick="adminLogout()" class="px-3 sm:px-4 py-2 bg-red-50 hover:bg-red-100 text-red-600 font-semibold text-xs rounded-xl transition flex items-center space-x-1.5">
        <i class="fa-solid fa-arrow-right-from-bracket"></i>
        <span>Logout</span>
      </button>
    </div>
  </header>

  <!-- Main Container -->
  <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8 space-y-6">

    <!-- METRIC CARDS / INFOGRAPHICS TILES -->
    <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3.5">
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Storage Pool</span>
          <i class="fa-solid fa-hard-drive text-amber-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-slate-800 truncate" id="stat-storage-used">0 GB</div>
        <div class="text-[10px] text-slate-400 truncate" id="stat-storage-total">of 0 GB pool</div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Gmails</span>
          <i class="fa-solid fa-envelope text-blue-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-slate-800" id="stat-accounts">0</div>
        <div class="text-[10px] text-slate-400 truncate">Connected drives</div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Total Files</span>
          <i class="fa-solid fa-cloud-arrow-up text-emerald-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-slate-800" id="stat-files">0</div>
        <div class="text-[10px] text-slate-400 truncate">Uploaded files</div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Users</span>
          <i class="fa-solid fa-users text-indigo-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-slate-800" id="stat-users">0</div>
        <div class="text-[10px] text-slate-400 truncate" id="stat-users-active">0 Active</div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Shared Links</span>
          <i class="fa-solid fa-link text-purple-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-slate-800" id="stat-links">0</div>
        <div class="text-[10px] text-slate-400 truncate">Public links</div>
      </div>

      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Blocked IPs</span>
          <i class="fa-solid fa-ban text-rose-500"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-rose-600" id="stat-blocked-ips">0</div>
        <div class="text-[10px] text-slate-400 truncate">Blacklist</div>
      </div>

      <!-- LIVE VISITORS CARD -->
      <div onclick="switchTab('live')" class="bg-white hover:bg-emerald-50/50 cursor-pointer p-4 rounded-2xl border border-emerald-200 shadow-xs transition group">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider flex items-center">
            <span class="relative flex h-2 w-2 mr-1.5">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
            </span>
            Live On Site
          </span>
          <i class="fa-solid fa-tower-broadcast text-emerald-500 group-hover:scale-110 transition"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-emerald-600" id="stat-live-users">0</div>
        <div class="text-[10px] text-emerald-700 font-medium truncate" id="stat-live-sub">Real-time visitors</div>
      </div>

      <!-- QUARANTINED FILES CARD -->
      <div onclick="switchTab('content')" class="bg-white hover:bg-rose-50/50 cursor-pointer p-4 rounded-2xl border border-rose-200 shadow-xs transition group">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Quarantine</span>
          <i class="fa-solid fa-shield-cat text-rose-500 group-hover:scale-110 transition"></i>
        </div>
        <div class="text-base sm:text-lg font-black text-rose-600" id="stat-quarantined-files">0</div>
        <div class="text-[10px] text-rose-700 font-medium truncate">Blocked visibility</div>
      </div>
    </section>

    <!-- NAVIGATION TABS -->
    <div class="bg-white rounded-2xl border border-slate-200 p-1.5 shadow-xs flex flex-wrap gap-1 text-xs font-bold">
      <button onclick="switchTab('stats')" id="tab-btn-stats" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 bg-blue-600 text-white shadow-xs">
        <i class="fa-solid fa-chart-pie"></i>
        <span>Analytics & Stats</span>
      </button>
      <button onclick="switchTab('live')" id="tab-btn-live" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <span class="relative flex h-2 w-2">
          <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
          <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
        </span>
        <span>Live Visitors</span>
        <span id="live-tab-badge" class="px-1.5 py-0.5 text-[10px] bg-emerald-100 text-emerald-800 rounded-md font-bold">0</span>
      </button>
      <button onclick="switchTab('content')" id="tab-btn-content" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-wand-magic-sparkles text-amber-500"></i>
        <span>Content Analyzer</span>
        <span id="content-tab-badge" class="px-1.5 py-0.5 text-[10px] bg-rose-100 text-rose-700 rounded-md font-bold">0</span>
      </button>
      <button onclick="switchTab('accounts')" id="tab-btn-accounts" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-cloud"></i>
        <span>Connected Gmails</span>
      </button>
      <button onclick="switchTab('files')" id="tab-btn-files" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-folder-tree"></i>
        <span>Global Files & Inspector</span>
      </button>
      <button onclick="switchTab('users')" id="tab-btn-users" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-users-gear"></i>
        <span>Users Management</span>
      </button>
      <button onclick="switchTab('links')" id="tab-btn-links" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-share-nodes"></i>
        <span>Shared Links Audit</span>
      </button>
      <button onclick="switchTab('security')" id="tab-btn-security" class="px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100">
        <i class="fa-solid fa-shield-halved"></i>
        <span>IP Blacklist & Security</span>
      </button>
    </div>

    <!-- ==================== TAB 1: ANALYTICS & STATS ==================== -->
    <section id="tab-content-stats" class="space-y-6">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Storage per Gmail Account Bar Chart -->
        <div class="lg:col-span-2 bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="font-bold text-slate-800 text-base">Connected Gmail Drive Capacities</h3>
              <p class="text-xs text-slate-400 mt-0.5">Used storage vs allocated CloudDrive quota per account</p>
            </div>
            <span class="px-3 py-1 bg-blue-50 text-blue-600 text-[11px] font-bold rounded-full">Live Breakdown</span>
          </div>
          <div class="h-64 relative">
            <canvas id="chart-account-storage"></canvas>
          </div>
        </div>

        <!-- File Types Donut Chart -->
        <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="font-bold text-slate-800 text-base">File Types Distribution</h3>
              <p class="text-xs text-slate-400 mt-0.5">Categorized files in database</p>
            </div>
          </div>
          <div class="h-64 relative flex items-center justify-center">
            <canvas id="chart-file-types"></canvas>
          </div>
        </div>

      </div>

      <!-- Upload Activity Over Time -->
      <div class="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs space-y-4">
        <div class="flex items-center justify-between">
          <div>
            <h3 class="font-bold text-slate-800 text-base">Upload Volume (Recent 7 Days)</h3>
            <p class="text-xs text-slate-400 mt-0.5">Files uploaded per day</p>
          </div>
        </div>
        <div class="h-56 relative">
          <canvas id="chart-activity"></canvas>
        </div>
      </div>
    </section>

    <!-- ==================== TAB: LIVE VISITORS ON SITE ==================== -->
    <section id="tab-content-live" class="space-y-6 hidden">
      <!-- Live Monitoring Header Card -->
      <div class="bg-gradient-to-r from-slate-900 via-emerald-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl space-y-4 border border-emerald-900/40">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div class="flex items-center space-x-2">
              <span class="relative flex h-3 w-3">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
              </span>
              <span class="px-3 py-1 bg-emerald-500/20 text-emerald-300 text-xs font-bold rounded-full uppercase tracking-wider border border-emerald-500/30">Admin Telemetry Engine</span>
            </div>
            <h2 class="text-xl sm:text-2xl font-black mt-2">Real-Time Live Visitors On Site</h2>
            <p class="text-xs text-slate-300 mt-1">Live traffic monitor displaying active users and guests, their device types, OS, browsers, IP addresses, and active pages in real-time.</p>
          </div>
          
          <div class="flex items-center space-x-3 shrink-0">
            <label class="flex items-center space-x-2 bg-white/10 hover:bg-white/15 px-3 py-2 rounded-xl text-xs font-semibold cursor-pointer transition border border-white/10">
              <input type="checkbox" id="live-auto-refresh-check" checked onchange="toggleLiveAutoRefresh()" class="rounded accent-emerald-500">
              <span>Auto-refresh (5s)</span>
            </label>
            <button onclick="loadLiveVisitors()" class="px-4 py-2 bg-emerald-500 hover:bg-emerald-400 text-slate-900 font-bold rounded-xl text-xs transition flex items-center space-x-2 shadow-lg shadow-emerald-500/20">
              <i class="fa-solid fa-rotate"></i>
              <span>Refresh Now</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Live Devices & Audience Stats -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Active Visitors</span>
            <i class="fa-solid fa-users text-emerald-500 text-base"></i>
          </div>
          <div class="text-2xl font-black text-slate-800" id="live-total-count">0</div>
          <div class="text-xs text-slate-500 mt-1" id="live-breakdown-auth">0 Registered · 0 Guests</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Desktop Users</span>
            <i class="fa-solid fa-desktop text-blue-500 text-base"></i>
          </div>
          <div class="text-2xl font-black text-blue-600" id="live-desktop-count">0</div>
          <div class="text-xs text-slate-400 mt-1">Windows, Mac, Linux</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Mobile Phones</span>
            <i class="fa-solid fa-mobile-screen-button text-purple-500 text-base"></i>
          </div>
          <div class="text-2xl font-black text-purple-600" id="live-mobile-count">0</div>
          <div class="text-xs text-slate-400 mt-1">Android & iOS devices</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Tablets</span>
            <i class="fa-solid fa-tablet-screen-button text-amber-500 text-base"></i>
          </div>
          <div class="text-2xl font-black text-amber-600" id="live-tablet-count">0</div>
          <div class="text-xs text-slate-400 mt-1">iPads & Tablets</div>
        </div>
      </div>

      <!-- Live Visitors Table -->
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <span class="relative flex h-2.5 w-2.5">
              <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
            </span>
            <h4 class="font-bold text-xs text-slate-700 uppercase tracking-wider">Active User Sessions Telemetry</h4>
          </div>
          <span class="text-xs font-semibold text-emerald-600" id="live-last-updated-label">Updated just now</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">User / Visitor</th>
                <th class="p-3.5">IP Address</th>
                <th class="p-3.5">Device & Platform</th>
                <th class="p-3.5">Browser</th>
                <th class="p-3.5">Active Location / Page</th>
                <th class="p-3.5">Last Seen</th>
                <th class="p-3.5 text-right">Security Actions</th>
              </tr>
            </thead>
            <tbody id="live-visitors-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ==================== TAB: CONTENT ANALYZER & MODERATION ==================== -->
    <section id="tab-content-content" class="space-y-6 hidden">
      <!-- Content Analyzer Header Card -->
      <div class="bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl space-y-4 border border-indigo-900/40">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <span class="px-3 py-1 bg-indigo-500/30 text-indigo-200 text-xs font-bold rounded-full uppercase tracking-wider border border-indigo-500/30">
                <i class="fa-solid fa-wand-magic-sparkles mr-1.5 text-amber-400"></i> Informational Content Inspector
              </span>
              <span class="px-2.5 py-0.5 bg-emerald-500/20 text-emerald-300 text-[11px] font-bold rounded-full border border-emerald-500/30">
                Advisory Only · No Auto-Block
              </span>
            </div>
            <h2 class="text-xl sm:text-2xl font-black mt-2">Content Analyzer & Inspection Dashboard</h2>
            <p class="text-xs text-slate-300 mt-1 max-w-2xl">
              The system analyzes and tells the administrator what kind of content each file contains (AI-generated, Adult 18+, Suspicious, or Clean). The system <strong>never automatically filters or quarantines files</strong> — all files remain fully active and accessible to users unless the administrator manually decides to block visibility.
            </p>
          </div>

          <div class="flex flex-wrap items-center gap-2 shrink-0">
            <button onclick="batchScanFiles(false)" id="btn-batch-scan" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl text-xs transition flex items-center space-x-2 shadow-lg shadow-indigo-600/30">
              <i class="fa-solid fa-magnifying-glass-chart"></i>
              <span>Scan Pending Files</span>
            </button>
            <button onclick="batchScanFiles(true)" id="btn-batch-rescan-all" class="px-3.5 py-2.5 bg-white/10 hover:bg-white/20 text-white font-semibold rounded-xl text-xs transition flex items-center space-x-1.5 border border-white/10">
              <i class="fa-solid fa-rotate"></i>
              <span>Deep Rescan All</span>
            </button>
          </div>
        </div>

        <!-- Advisory Notice Banner -->
        <div class="bg-indigo-500/15 border border-indigo-400/30 rounded-2xl p-3.5 text-xs text-indigo-200 flex items-start space-x-3">
          <i class="fa-solid fa-circle-info text-amber-400 text-base mt-0.5 shrink-0"></i>
          <div>
            <span class="font-bold text-white block mb-0.5">Informational Classification Only (System Does Not Auto-Block):</span>
            <span>The automated scanner identifies content markers (such as AI prompts or adult keywords) to inform the administrator. It does <strong>not</strong> censor, filter, or block files automatically. Only the administrator can choose to manually block visibility using the button below.</span>
          </div>
        </div>

        <div id="content-scan-feedback" class="hidden p-3 rounded-xl text-xs"></div>
      </div>

      <!-- Moderation Summary Cards -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
        <div onclick="setContentCategory('ai_generated')" class="bg-white hover:bg-purple-50/50 cursor-pointer p-4 rounded-2xl border border-purple-200 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-purple-600 uppercase tracking-wider">AI Generated</span>
            <i class="fa-solid fa-robot text-purple-500 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-purple-700" id="cnt-stat-ai">0</div>
          <div class="text-[10px] text-slate-400 truncate">Prompts & AI metadata</div>
        </div>

        <div onclick="setContentCategory('adult_18')" class="bg-white hover:bg-rose-50/50 cursor-pointer p-4 rounded-2xl border border-rose-200 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-rose-600 uppercase tracking-wider">Adult 18+ / NSFW</span>
            <i class="fa-solid fa-triangle-exclamation text-rose-500 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-rose-700" id="cnt-stat-adult">0</div>
          <div class="text-[10px] text-slate-400 truncate">Adult tags & signatures</div>
        </div>

        <div onclick="setContentCategory('suspicious')" class="bg-white hover:bg-amber-50/50 cursor-pointer p-4 rounded-2xl border border-amber-200 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-amber-600 uppercase tracking-wider">Suspicious</span>
            <i class="fa-solid fa-biohazard text-amber-500 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-amber-700" id="cnt-stat-suspicious">0</div>
          <div class="text-[10px] text-slate-400 truncate">Scripts & disguised EXEs</div>
        </div>

        <div onclick="setContentCategory('safe')" class="bg-white hover:bg-emerald-50/50 cursor-pointer p-4 rounded-2xl border border-emerald-200 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider">Clean / Safe</span>
            <i class="fa-solid fa-shield-heart text-emerald-500 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-emerald-700" id="cnt-stat-safe">0</div>
          <div class="text-[10px] text-slate-400 truncate">Verified clean media</div>
        </div>

        <div onclick="setContentCategory('unclassified')" class="bg-white hover:bg-slate-100 cursor-pointer p-4 rounded-2xl border border-slate-200 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Unclassified</span>
            <i class="fa-solid fa-clock text-slate-400 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-slate-700" id="cnt-stat-unclassified">0</div>
          <div class="text-[10px] text-slate-400 truncate">Pending scan</div>
        </div>

        <div onclick="setContentCategory('blocked')" class="bg-white hover:bg-red-50/50 cursor-pointer p-4 rounded-2xl border border-red-300 shadow-xs transition group">
          <div class="flex items-center justify-between mb-1.5">
            <span class="text-[10px] font-bold text-red-600 uppercase tracking-wider">Quarantined</span>
            <i class="fa-solid fa-lock text-red-600 group-hover:scale-110 transition"></i>
          </div>
          <div class="text-xl font-black text-red-700" id="cnt-stat-quarantined">0</div>
          <div class="text-[10px] text-red-600 font-semibold truncate">Visibility blocked</div>
        </div>
      </div>

      <!-- Filters & Trace Bar -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <!-- Category Filter Pills -->
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-xs font-bold text-slate-500 mr-1">Category:</span>
          <button onclick="setContentCategory('all')" id="btn-cat-all" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-blue-600 text-white shadow-xs">
            All Files
          </button>
          <button onclick="setContentCategory('ai_generated')" id="btn-cat-ai_generated" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            <i class="fa-solid fa-robot mr-1 text-purple-500"></i> AI Generated
          </button>
          <button onclick="setContentCategory('adult_18')" id="btn-cat-adult_18" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            <i class="fa-solid fa-triangle-exclamation mr-1 text-rose-500"></i> Adult 18+
          </button>
          <button onclick="setContentCategory('suspicious')" id="btn-cat-suspicious" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            <i class="fa-solid fa-biohazard mr-1 text-amber-500"></i> Suspicious Scripts
          </button>
          <button onclick="setContentCategory('blocked')" id="btn-cat-blocked" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            <i class="fa-solid fa-lock mr-1 text-red-600"></i> Quarantined Only
          </button>
          <button onclick="setContentCategory('safe')" id="btn-cat-safe" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            <i class="fa-solid fa-shield-heart mr-1 text-emerald-500"></i> Clean / Safe
          </button>
          <button onclick="setContentCategory('unclassified')" id="btn-cat-unclassified" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700">
            Unclassified
          </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs pt-1 border-t border-slate-100">
          <!-- User Trace Filter -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1 flex items-center space-x-1">
              <i class="fa-solid fa-user-tag text-indigo-500"></i>
              <span>Trace Uploading User</span>
            </label>
            <select id="content-filter-user" onchange="loadContentFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500">
              <option value="0">All Uploading Users</option>
            </select>
          </div>

          <!-- Search file name -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Search File Name / Details</label>
            <div class="relative">
              <i class="fa-solid fa-magnifying-glass absolute left-3 top-2.5 text-slate-400"></i>
              <input type="text" id="content-filter-search" oninput="debounceLoadContentFiles()" placeholder="Search..." class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500">
            </div>
          </div>

          <!-- Quick reset -->
          <div class="flex items-end">
            <button onclick="resetContentFilters()" class="w-full py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition flex items-center justify-center space-x-1.5">
              <i class="fa-solid fa-rotate-left"></i>
              <span>Reset Content Filters</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Content Moderation Table -->
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <i class="fa-solid fa-list-check text-indigo-600"></i>
            <h4 class="font-bold text-xs text-slate-700 uppercase tracking-wider">Scanned Uploads & Moderation Control</h4>
          </div>
          <span class="text-xs text-slate-400" id="content-count-label">0 items displayed</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">File Details</th>
                <th class="p-3.5">Uploaded By</th>
                <th class="p-3.5">Detected Classification</th>
                <th class="p-3.5">Inspection Details</th>
                <th class="p-3.5">User Visibility</th>
                <th class="p-3.5 text-right">Moderator Actions</th>
              </tr>
            </thead>
            <tbody id="content-files-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ==================== TAB 2: CONNECTED GMAIL ACCOUNTS ==================== -->
    <section id="tab-content-accounts" class="space-y-6 hidden">
      <!-- One-Paste Quick Connect Card -->
      <div class="bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 rounded-3xl p-6 sm:p-8 text-white shadow-xl space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div>
            <span class="px-3 py-1 bg-blue-500/30 text-blue-200 text-xs font-bold rounded-full uppercase tracking-wider">Storage Pool Scaling</span>
            <h2 class="text-xl sm:text-2xl font-bold mt-2">Connect Google Drive by Key / Token</h2>
            <p class="text-xs text-blue-200 mt-1">Paste your Refresh Token, Service Account Key, or OAuth Credentials JSON below to expand the storage pool.</p>
          </div>
          <button onclick="toggleMasterSettings()" class="px-3.5 py-2 bg-white/10 hover:bg-white/20 text-white rounded-xl text-xs font-semibold transition border border-white/10 shrink-0">
            <i class="fa-solid fa-gear mr-1.5"></i> Master Client ID & Secret
          </button>
        </div>

        <!-- Master OAuth Settings -->
        <div id="master-settings-card" class="hidden bg-slate-900/90 border border-white/10 rounded-2xl p-5 space-y-3 text-xs">
          <p class="font-semibold text-amber-300"><i class="fa-solid fa-key mr-1.5"></i> One-Time Master Project Credentials (Optional):</p>
          <p class="text-[11px] text-slate-300">Set your Google Cloud Client ID and Secret once. Connecting any Gmail account then only requires pasting its <strong>Refresh Token</strong>!</p>
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
            💡 The system automatically detects total Google Drive capacity (15 GB, 2 TB, etc.), deducts previous user usage, reserves a <strong>2 GB safety buffer</strong>, and creates a dedicated <strong>CloudDrive_files</strong> folder!
          </p>
        </div>
      </div>

      <!-- Accounts Grid -->
      <div id="accounts-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <!-- Dynamically rendered -->
      </div>
    </section>

    <!-- ==================== TAB 3: GLOBAL FILES & INSPECTOR ==================== -->
    <section id="tab-content-files" class="space-y-4 hidden">
      <!-- Multi-Criteria Filter Bar -->
      <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="flex items-center space-x-2">
            <i class="fa-solid fa-filter text-blue-600"></i>
            <h3 class="font-bold text-sm text-slate-800">Files Filter & Search Controls</h3>
          </div>
          <button onclick="resetFilesFilter()" class="text-xs text-blue-600 hover:underline font-semibold flex items-center space-x-1">
            <i class="fa-solid fa-rotate-left"></i>
            <span>Reset All Filters</span>
          </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-xs">
          <!-- Search -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Search File Name</label>
            <div class="relative">
              <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
              <input type="text" id="filter-files-search" placeholder="File name..." oninput="debounceLoadFiles()" class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
            </div>
          </div>

          <!-- User Owner -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Filter by User</label>
            <select id="filter-files-user" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="0">All Users</option>
            </select>
          </div>

          <!-- Stored Gmail Drive -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Filter by Gmail Drive (Inspector)</label>
            <select id="filter-files-account" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="0">All Connected Gmails</option>
            </select>
          </div>

          <!-- File Type -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">File Type</label>
            <select id="filter-files-type" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="all">All File Types</option>
              <option value="image">Images (PNG, JPG, etc.)</option>
              <option value="video">Videos (MP4, MKV, etc.)</option>
              <option value="audio">Audio (MP3, WAV, etc.)</option>
              <option value="document">Documents (PDF, Word, Text)</option>
              <option value="archive">Archives (ZIP, RAR, 7z)</option>
            </select>
          </div>

          <!-- File Size -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">File Size Range</label>
            <select id="filter-files-size" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="all">Any Size</option>
              <option value="small">&lt; 10 MB (Small)</option>
              <option value="medium">10 MB – 100 MB (Medium)</option>
              <option value="large">100 MB – 1 GB (Large)</option>
              <option value="huge">&gt; 1 GB (Huge)</option>
            </select>
          </div>

          <!-- Upload Date -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Upload Date</label>
            <select id="filter-files-date" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="all">Any Time</option>
              <option value="today">Today</option>
              <option value="week">Last 7 Days</option>
              <option value="month">Last 30 Days</option>
            </select>
          </div>

          <!-- Sort -->
          <div>
            <label class="block text-slate-500 font-semibold mb-1">Sort By</label>
            <select id="filter-files-sort" onchange="loadFiles()" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
              <option value="newest">Newest First</option>
              <option value="oldest">Oldest First</option>
              <option value="largest">Largest Size</option>
              <option value="smallest">Smallest Size</option>
              <option value="name">File Name (A-Z)</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Files Table -->
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
                <th class="p-3.5">Access</th>
                <th class="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody id="global-files-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ==================== TAB 4: USERS MANAGEMENT ==================== -->
    <section id="tab-content-users" class="space-y-4 hidden">
      <!-- Users Filter Bar -->
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
        <div class="relative flex-1 w-full sm:max-w-md">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i>
          <input type="text" id="filter-users-search" placeholder="Search by username, email, or IP address..." oninput="debounceLoadUsers()" class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
        </div>

        <div class="flex items-center space-x-3 w-full sm:w-auto">
          <select id="filter-users-status" onchange="loadUsers()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
            <option value="all">All Statuses</option>
            <option value="active">Active Only</option>
            <option value="blocked">Blocked Only</option>
          </select>

          <select id="filter-users-role" onchange="loadUsers()" class="px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500">
            <option value="all">All Roles</option>
            <option value="user">Regular Users</option>
            <option value="admin">Admins</option>
          </select>
        </div>
      </div>

      <!-- Users Table -->
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">User</th>
                <th class="p-3.5">Email</th>
                <th class="p-3.5">Role</th>
                <th class="p-3.5">Registration & Login IP</th>
                <th class="p-3.5">Storage Quota Limit</th>
                <th class="p-3.5">Status</th>
                <th class="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody id="users-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ==================== TAB 5: SHARED LINKS AUDIT ==================== -->
    <section id="tab-content-links" class="space-y-4 hidden">
      <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex items-center justify-between">
        <div>
          <h3 class="font-bold text-sm text-slate-800">Public & Shareable Links Registry</h3>
          <p class="text-xs text-slate-400 mt-0.5">Audit which user generated which public share link and revoke access with one click.</p>
        </div>
        <button onclick="loadSharedLinks()" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition flex items-center space-x-1.5">
          <i class="fa-solid fa-rotate"></i>
          <span>Refresh Links</span>
        </button>
      </div>

      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">Item Name</th>
                <th class="p-3.5">Type</th>
                <th class="p-3.5">Created By User</th>
                <th class="p-3.5">Share Link URL</th>
                <th class="p-3.5">Created Date</th>
                <th class="p-3.5">Expires / Info</th>
                <th class="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody id="shared-links-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ==================== TAB 6: IP BLACKLIST & SECURITY ==================== -->
    <section id="tab-content-security" class="space-y-6 hidden">
      <!-- Add Blocked IP Box -->
      <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-xs space-y-4">
        <h3 class="font-bold text-sm text-slate-800 flex items-center space-x-2">
          <i class="fa-solid fa-shield-halved text-rose-500"></i>
          <span>Block An IP Address Manually</span>
        </h3>
        <form onsubmit="handleBlockIpSubmit(event)" class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
          <div>
            <label class="block font-semibold text-slate-600 mb-1">IP Address (IPv4 or IPv6)</label>
            <input type="text" id="new-block-ip" required placeholder="e.g. 192.168.1.50" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
          </div>
          <div>
            <label class="block font-semibold text-slate-600 mb-1">Reason (Optional)</label>
            <input type="text" id="new-block-reason" placeholder="e.g. Abuse, spamming, malicious upload" class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-rose-500">
          </div>
          <div class="flex items-end">
            <button type="submit" class="w-full py-2.5 bg-rose-600 hover:bg-rose-700 text-white font-bold rounded-xl transition shadow-xs flex items-center justify-center space-x-2">
              <i class="fa-solid fa-ban"></i>
              <span>Add to Blacklist</span>
            </button>
          </div>
        </form>
      </div>

      <!-- Blocked IPs Table -->
      <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
        <div class="p-4 border-b border-slate-100 flex items-center justify-between">
          <h4 class="font-bold text-xs text-slate-700 uppercase tracking-wider">Currently Blacklisted IP Addresses</h4>
          <span class="text-xs text-slate-400" id="blocked-ips-count-label">0 IPs blocked</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold">
              <tr>
                <th class="p-3.5">IP Address</th>
                <th class="p-3.5">Reason</th>
                <th class="p-3.5">Blocked At</th>
                <th class="p-3.5 text-right">Actions</th>
              </tr>
            </thead>
            <tbody id="blocked-ips-tbody" class="divide-y divide-slate-100 text-slate-700">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>
    </section>

  </main>

  <!-- ==================== MODALS ==================== -->

  <!-- 1. USER FILES DRILLDOWN MODAL -->
  <div id="modal-user-drilldown" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-4xl w-full p-6 max-h-[90vh] flex flex-col shadow-2xl border border-slate-200">
      <div class="flex items-start justify-between pb-4 border-b border-slate-100">
        <div>
          <div class="flex items-center space-x-2">
            <h3 class="font-bold text-lg text-slate-800" id="drilldown-username">User Files</h3>
            <span id="drilldown-status-badge" class="px-2 py-0.5 text-[10px] font-bold rounded-md uppercase"></span>
          </div>
          <p class="text-xs text-slate-400 mt-0.5" id="drilldown-user-info"></p>
        </div>
        <button onclick="closeModal('modal-user-drilldown')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="overflow-y-auto flex-1 my-4 custom-scrollbar">
        <table class="w-full text-left text-xs">
          <thead class="bg-slate-50 text-slate-500 border-b border-slate-200 uppercase font-semibold sticky top-0">
            <tr>
              <th class="p-3">File Name</th>
              <th class="p-3">Size</th>
              <th class="p-3">Stored On Gmail</th>
              <th class="p-3">Date</th>
              <th class="p-3 text-right">Actions</th>
            </tr>
          </thead>
          <tbody id="drilldown-files-tbody" class="divide-y divide-slate-100 text-slate-700">
            <!-- Dynamically populated -->
          </tbody>
        </table>
      </div>

      <div class="pt-3 border-t border-slate-100 flex justify-end">
        <button onclick="closeModal('modal-user-drilldown')" class="px-5 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold text-xs rounded-xl transition">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- 2. MOVE FILE TO ANOTHER GMAIL MODAL -->
  <div id="modal-move-file" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2 text-blue-600">
          <i class="fa-solid fa-arrows-rotate text-lg"></i>
          <h3 class="font-bold text-base text-slate-800">Move File to Another Gmail</h3>
        </div>
        <button onclick="closeModal('modal-move-file')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="space-y-3 text-xs">
        <div class="p-3 bg-slate-50 rounded-xl border border-slate-100 space-y-1">
          <span class="text-slate-400 block font-semibold">Target File:</span>
          <strong class="text-slate-800 text-sm block truncate" id="move-file-name">filename.ext</strong>
          <span class="text-[11px] text-slate-500" id="move-file-current-acc">Currently on: ...</span>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Select Destination Gmail Account:</label>
          <select id="move-target-account" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-blue-500 text-xs">
            <!-- Dynamically populated -->
          </select>
        </div>

        <div id="move-feedback" class="hidden p-3 rounded-xl text-xs"></div>
      </div>

      <div class="flex items-center justify-end space-x-2 pt-2">
        <button onclick="closeModal('modal-move-file')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition">
          Cancel
        </button>
        <button onclick="confirmMoveFile()" id="btn-confirm-move" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl transition shadow-xs flex items-center space-x-2">
          <i class="fa-solid fa-arrow-right-arrow-left"></i>
          <span>Transfer File</span>
        </button>
      </div>
    </div>
  </div>

  <!-- 3. SET USER STORAGE LIMIT MODAL -->
  <div id="modal-storage-limit" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2 text-amber-500">
          <i class="fa-solid fa-database text-lg"></i>
          <h3 class="font-bold text-base text-slate-800">Set Storage Limit Quota</h3>
        </div>
        <button onclick="closeModal('modal-storage-limit')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="space-y-4 text-xs">
        <p class="text-slate-500">Configure maximum storage allowed for <strong id="limit-modal-username" class="text-slate-800">user</strong>. If user reaches this cap, subsequent uploads will be rejected until limit is adjusted.</p>

        <!-- Presets -->
        <div>
          <label class="block font-semibold text-slate-700 mb-1.5">Quick Presets:</label>
          <div class="grid grid-cols-3 gap-2">
            <button type="button" onclick="setLimitPreset(0)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">Unlimited</button>
            <button type="button" onclick="setLimitPreset(500)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">500 MB</button>
            <button type="button" onclick="setLimitPreset(1024)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">1 GB</button>
            <button type="button" onclick="setLimitPreset(2048)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">2 GB</button>
            <button type="button" onclick="setLimitPreset(5120)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">5 GB</button>
            <button type="button" onclick="setLimitPreset(10240)" class="py-2 px-3 bg-slate-100 hover:bg-slate-200 rounded-xl font-bold text-slate-700 transition">10 GB</button>
          </div>
        </div>

        <div>
          <label class="block font-semibold text-slate-700 mb-1">Custom Limit in Megabytes (MB) (0 = Unlimited):</label>
          <input type="number" id="limit-input-mb" min="0" placeholder="e.g. 1024" class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-amber-500 font-mono text-xs">
        </div>
      </div>

      <div class="flex items-center justify-end space-x-2 pt-2">
        <button onclick="closeModal('modal-storage-limit')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition">
          Cancel
        </button>
        <button onclick="confirmStorageLimit()" class="px-5 py-2 bg-amber-500 hover:bg-amber-600 text-white font-bold text-xs rounded-xl transition shadow-xs">
          Save Limit
        </button>
      </div>
    </div>
  </div>

  <!-- 4. SET PASSWORD MODAL -->
  <div id="modal-set-password" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center space-x-2 text-indigo-600">
          <i class="fa-solid fa-key text-lg"></i>
          <h3 class="font-bold text-base text-slate-800">Set User Password</h3>
        </div>
        <button onclick="closeModal('modal-set-password')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>

      <div class="space-y-3 text-xs">
        <p class="text-slate-500">Directly set or reset password for user <strong id="password-modal-username" class="text-slate-800">user</strong>.</p>
        <div>
          <label class="block font-semibold text-slate-700 mb-1">New Password (min 6 chars):</label>
          <input type="password" id="new-password-input" minlength="6" placeholder="Enter new password..." class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:border-indigo-500 text-xs">
        </div>
      </div>

      <div class="flex items-center justify-end space-x-2 pt-2">
        <button onclick="closeModal('modal-set-password')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition">
          Cancel
        </button>
        <button onclick="confirmSetPassword()" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl transition shadow-xs">
          Update Password
        </button>
      </div>
    </div>
  </div>

  <!-- 5. FILE PREVIEW MODAL -->
  <div id="modal-preview" class="hidden fixed inset-0 z-50 bg-slate-900/80 backdrop-blur-xs flex items-center justify-center p-4">
    <div class="bg-white rounded-3xl max-w-3xl w-full p-6 max-h-[90vh] flex flex-col shadow-2xl border border-slate-200">
      <div class="flex items-center justify-between pb-3 border-b border-slate-100">
        <h3 class="font-bold text-sm text-slate-800 truncate" id="preview-title">File Preview</h3>
        <button onclick="closeModal('modal-preview')" class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
      <div id="preview-content" class="my-4 flex-1 flex items-center justify-center overflow-auto min-h-[300px] bg-slate-50 rounded-2xl p-2">
        <!-- Rendered preview -->
      </div>
      <div class="flex justify-between items-center pt-2 border-t border-slate-100 text-xs">
        <a id="preview-download-link" href="#" target="_blank" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl transition">
          <i class="fa-solid fa-download mr-1.5"></i> Download File
        </a>
        <button onclick="closeModal('modal-preview')" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold rounded-xl transition">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- IN-APP CONFIRMATION MODAL (Replaces window.confirm) -->
  <div id="in-app-confirm-modal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-slate-100 space-y-4">
      <div class="flex items-center space-x-3.5">
        <div id="in-app-confirm-icon-box" class="w-11 h-11 rounded-2xl bg-rose-50 text-rose-500 flex items-center justify-center text-lg shrink-0">
          <i id="in-app-confirm-icon" class="fa-solid fa-trash-can"></i>
        </div>
        <div>
          <h3 id="in-app-confirm-title" class="text-base font-bold text-slate-800">Confirm Action</h3>
          <p id="in-app-confirm-message" class="text-xs text-slate-500 mt-0.5 leading-relaxed">Are you sure you want to proceed?</p>
        </div>
      </div>
      <div class="flex items-center justify-end space-x-2 pt-2 border-t border-slate-100">
        <button id="in-app-confirm-cancel-btn" type="button" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancel</button>
        <button id="in-app-confirm-ok-btn" type="button" class="px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold rounded-xl transition shadow-xs">Confirm</button>
      </div>
    </div>
  </div>

  <!-- IN-APP PROMPT MODAL (Replaces window.prompt) -->
  <div id="in-app-prompt-modal" class="fixed inset-0 bg-slate-950/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
    <div class="bg-white rounded-3xl max-w-sm w-full p-6 shadow-2xl border border-slate-100 space-y-4">
      <div class="flex items-center space-x-3.5">
        <div id="in-app-prompt-icon-box" class="w-11 h-11 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center text-lg shrink-0">
          <i id="in-app-prompt-icon" class="fa-solid fa-pen"></i>
        </div>
        <div>
          <h3 id="in-app-prompt-title" class="text-base font-bold text-slate-800">Prompt</h3>
          <p id="in-app-prompt-message" class="text-xs text-slate-500 mt-0.5">Please enter a value.</p>
        </div>
      </div>
      <div>
        <input type="text" id="in-app-prompt-input" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:bg-white focus:border-blue-500 outline-none transition font-medium text-slate-800">
      </div>
      <div class="flex items-center justify-end space-x-2 pt-2 border-t border-slate-100">
        <button id="in-app-prompt-cancel-btn" type="button" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancel</button>
        <button id="in-app-prompt-ok-btn" type="button" class="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold rounded-xl transition shadow-xs">Save</button>
      </div>
    </div>
  </div>

  <!-- FLOATING TOAST NOTIFICATION CONTAINER (Replaces window.alert) -->
  <div id="toast-container" class="fixed bottom-5 right-5 z-50 flex flex-col space-y-2 pointer-events-none max-w-sm w-full px-4"></div>

  <!-- ==================== FRONTEND JAVASCRIPT CONTROLLER ==================== -->
  <script>
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
          <span class="truncate">${escapeHtml(String(message))}</span>
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

    window.alert = function(msg) {
      window.showInAppToast(String(msg), 'info');
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

    window.showInAppPrompt = function ({ title = 'Input Required', message = 'Please enter a value.', defaultValue = '', confirmText = 'Save' } = {}) {
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
        if (input) input.value = defaultValue;
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
        };

        okBtn.addEventListener('click', handleOk, { once: true });
        cancelBtn.addEventListener('click', handleCancel, { once: true });
      });
    };

    let globalAccountsCache = [];
    let globalUsersCache = [];
    let activeMoveFileId = null;
    let activeLimitUserId = null;
    let activePassUserId = null;
    let filesSearchTimeout = null;
    let usersSearchTimeout = null;

    let livePollingTimer = null;
    let liveAutoRefresh = true;
    let activeContentCategory = 'all';
    let contentSearchTimeout = null;

    let chartStorage = null;
    let chartTypes = null;
    let chartActivity = null;

    document.addEventListener('DOMContentLoaded', () => {
      loadStats();
      loadAnalytics();
      loadAccounts();
    });

    // Tab Switching
    function switchTab(tab) {
      const tabs = ['stats', 'live', 'content', 'accounts', 'files', 'users', 'links', 'security'];
      tabs.forEach(t => {
        const btn = document.getElementById(`tab-btn-${t}`);
        const content = document.getElementById(`tab-content-${t}`);
        if (!btn || !content) return;
        if (t === tab) {
          btn.className = 'px-4 py-2.5 rounded-xl transition flex items-center space-x-2 bg-blue-600 text-white shadow-xs';
          content.classList.remove('hidden');
        } else {
          btn.className = 'px-4 py-2.5 rounded-xl transition flex items-center space-x-2 text-slate-600 hover:bg-slate-100';
          content.classList.add('hidden');
        }
      });

      if (tab === 'stats') { loadStats(); loadAnalytics(); }
      if (tab === 'live') { loadLiveVisitors(); startLivePolling(); } else { stopLivePolling(); }
      if (tab === 'content') { loadContentStats(); loadContentFiles(); populateContentUserFilter(); }
      if (tab === 'accounts') loadAccounts();
      if (tab === 'files') loadFiles();
      if (tab === 'users') loadUsers();
      if (tab === 'links') loadSharedLinks();
      if (tab === 'security') loadBlockedIps();
    }

    function closeModal(id) {
      document.getElementById(id).classList.add('hidden');
    }

    function formatBytes(bytes, decimals = 2) {
      if (!bytes || bytes <= 0) return '0 B';
      const k = 1024;
      const dm = decimals < 0 ? 0 : decimals;
      const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    // 1. STATS OVERVIEW
    async function loadStats() {
      try {
        const res = await fetch('../api/admin.php?action=stats');
        const data = await res.json();
        if (data.success) {
          const s = data.stats;
          document.getElementById('stat-accounts').textContent = s.active_google_accounts;
          document.getElementById('stat-storage-used').textContent = formatBytes(s.pool_used_bytes);
          document.getElementById('stat-storage-total').textContent = `of ${formatBytes(s.pool_total_bytes)} pool`;
          document.getElementById('stat-files').textContent = s.total_files;
          document.getElementById('stat-users').textContent = s.total_users;
          document.getElementById('stat-users-active').textContent = `${s.active_users} Active (${s.blocked_users} Blocked)`;
          document.getElementById('stat-links').textContent = s.shared_links_count;
          document.getElementById('stat-blocked-ips').textContent = s.blocked_ips_count;

          if (document.getElementById('stat-live-users')) {
            document.getElementById('stat-live-users').textContent = s.live_visitors_count || 0;
            document.getElementById('live-tab-badge').textContent = s.live_visitors_count || 0;
            document.getElementById('stat-live-sub').textContent = `${s.live_visitors_count || 0} active now`;
          }
          if (document.getElementById('stat-quarantined-files')) {
            document.getElementById('stat-quarantined-files').textContent = s.quarantined_files_count || 0;
            document.getElementById('content-tab-badge').textContent = s.quarantined_files_count || 0;
          }
        }
      } catch (e) {
        console.error('Failed to load stats', e);
      }
    }

    // ========================================================
    // 1B. LIVE VISITORS TELEMETRY ENGINE
    // ========================================================
    function startLivePolling() {
      stopLivePolling();
      if (liveAutoRefresh) {
        livePollingTimer = setInterval(loadLiveVisitors, 5000);
      }
    }

    function stopLivePolling() {
      if (livePollingTimer) {
        clearInterval(livePollingTimer);
        livePollingTimer = null;
      }
    }

    function toggleLiveAutoRefresh() {
      const chk = document.getElementById('live-auto-refresh-check');
      liveAutoRefresh = chk ? chk.checked : true;
      if (liveAutoRefresh) {
        startLivePolling();
      } else {
        stopLivePolling();
      }
    }

    async function loadLiveVisitors() {
      const tbody = document.getElementById('live-visitors-tbody');
      if (!tbody) return;

      try {
        const res = await fetch('../api/admin.php?action=live_visitors');
        const data = await res.json();
        if (!data.success) return;

        const count = data.count || 0;
        const auth = data.auth_count || 0;
        const guest = data.guest_count || 0;
        const dev = data.devices || { Desktop: 0, Mobile: 0, Tablet: 0 };
        const visitors = data.visitors || [];

        document.getElementById('live-total-count').textContent = count;
        document.getElementById('live-breakdown-auth').textContent = `${auth} Registered · ${guest} Guests`;
        document.getElementById('live-desktop-count').textContent = dev.Desktop || 0;
        document.getElementById('live-mobile-count').textContent = dev.Mobile || 0;
        document.getElementById('live-tablet-count').textContent = dev.Tablet || 0;

        if (document.getElementById('stat-live-users')) {
          document.getElementById('stat-live-users').textContent = count;
          document.getElementById('live-tab-badge').textContent = count;
          document.getElementById('stat-live-sub').textContent = `${dev.Desktop || 0} PC · ${dev.Mobile || 0} Mob`;
        }

        const label = document.getElementById('live-last-updated-label');
        if (label) label.textContent = `Updated ${new Date().toLocaleTimeString()}`;

        if (visitors.length === 0) {
          tbody.innerHTML = `<tr><td colspan="7" class="p-8 text-center text-slate-400">
            <i class="fa-solid fa-satellite-dish text-2xl text-slate-300 mb-2 block"></i>
            No active visitors detected on the site in the last 2 minutes.
          </td></tr>`;
          return;
        }

        tbody.innerHTML = visitors.map(v => {
          const isUser = !!v.user_id;
          const userHtml = isUser 
            ? `<div class="flex items-center space-x-2">
                 <div class="w-7 h-7 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center font-bold text-xs">
                   <i class="fa-solid fa-user"></i>
                 </div>
                 <div>
                   <div class="font-bold text-slate-800 cursor-pointer hover:text-blue-600" onclick="drilldownUser(${v.user_id})">${escapeHtml(v.username || 'User')}</div>
                   <div class="text-[10px] text-slate-400">${escapeHtml(v.user_email || '')}</div>
                 </div>
               </div>`
            : `<div class="flex items-center space-x-2">
                 <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center text-xs">
                   <i class="fa-solid fa-user-secret"></i>
                 </div>
                 <div>
                   <div class="font-semibold text-slate-700">Guest Visitor</div>
                   <div class="text-[10px] text-slate-400 font-mono">ID: ${escapeHtml((v.session_id || '').substring(0, 10))}...</div>
                 </div>
               </div>`;

          let devIcon = 'fa-desktop text-blue-500';
          if (v.device_type === 'Mobile') devIcon = 'fa-mobile-screen-button text-purple-500';
          if (v.device_type === 'Tablet') devIcon = 'fa-tablet-screen-button text-amber-500';

          let pageLabel = v.current_page || '/';
          let pageBadge = 'bg-slate-100 text-slate-600';
          if (pageLabel.includes('temp')) {
            pageLabel = 'Temp Upload Area';
            pageBadge = 'bg-amber-100 text-amber-800';
          } else if (pageLabel.includes('share')) {
            pageLabel = 'Shared Public Link';
            pageBadge = 'bg-purple-100 text-purple-800';
          } else if (pageLabel === '/' || pageLabel.includes('index')) {
            pageLabel = 'CloudDrive User App';
            pageBadge = 'bg-blue-100 text-blue-800';
          }

          const lastActiveSec = Math.max(0, Math.round((Date.now() - new Date(v.last_active_at).getTime()) / 1000));
          const timeText = lastActiveSec < 15 ? 'Active now' : `${lastActiveSec}s ago`;

          return `
            <tr class="hover:bg-slate-50 transition">
              <td class="p-3.5">${userHtml}</td>
              <td class="p-3.5">
                <div class="flex items-center space-x-1.5">
                  <span class="font-mono font-bold text-slate-800 text-[11px]">${escapeHtml(v.ip_address)}</span>
                  <button onclick="navigator.clipboard.writeText('${escapeHtml(v.ip_address)}')" title="Copy IP" class="text-slate-400 hover:text-slate-600 p-1">
                    <i class="fa-regular fa-copy"></i>
                  </button>
                </div>
              </td>
              <td class="p-3.5">
                <div class="flex items-center space-x-2">
                  <i class="fa-solid ${devIcon} text-base"></i>
                  <div>
                    <span class="font-bold text-slate-800 block text-[11px]">${escapeHtml(v.device_type || 'Desktop')}</span>
                    <span class="text-[10px] text-slate-400 block">${escapeHtml(v.os_name || 'OS')}</span>
                  </div>
                </div>
              </td>
              <td class="p-3.5">
                <span class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md font-medium text-[11px]">
                  ${escapeHtml(v.browser_name || 'Browser')}
                </span>
              </td>
              <td class="p-3.5">
                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold ${pageBadge}">
                  ${pageLabel}
                </span>
              </td>
              <td class="p-3.5">
                <span class="text-emerald-600 font-bold flex items-center space-x-1">
                  <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                  <span>${timeText}</span>
                </span>
              </td>
              <td class="p-3.5 text-right whitespace-nowrap space-x-1.5">
                ${isUser ? `<button onclick="drilldownUser(${v.user_id})" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition text-[11px]">
                  <i class="fa-solid fa-folder-open mr-1"></i> Files
                </button>` : ''}
                <button onclick="quickBlockIp('${escapeHtml(v.ip_address)}')" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-lg transition text-[11px]">
                  <i class="fa-solid fa-ban mr-1"></i> Block IP
                </button>
              </td>
            </tr>
          `;
        }).join('');
      } catch (e) {
        console.error('Failed to load live visitors', e);
      }
    }

    async function quickBlockIp(ip) {
      const ok = await showInAppConfirm({
        title: 'Block IP Address',
        message: `Are you sure you want to block IP ${ip} from accessing CloudDrive?`,
        confirmText: 'Block IP',
        isDanger: true
      });
      if (!ok) return;
      try {
        const res = await fetch('../api/admin.php?action=ip_action', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subaction: 'block', ip_address: ip, reason: 'Blocked from Live Visitors monitor' }),
        });
        const data = await res.json();
        if (data.success) {
          showInAppToast(`IP ${ip} has been added to blacklist!`, 'success');
          loadStats();
          loadLiveVisitors();
        } else {
          showInAppToast(data.error || 'Failed to block IP.', 'error');
        }
      } catch (e) {
        showInAppToast('Server connection error.', 'error');
      }
    }

    // ========================================================
    // 1C. CONTENT ANALYZER & MODERATION ENGINE
    // ========================================================
    async function loadContentStats() {
      try {
        const res = await fetch('../api/admin.php?action=content_stats');
        const data = await res.json();
        if (!data.success) return;
        const s = data.stats;

        document.getElementById('cnt-stat-ai').textContent = s.ai_generated || 0;
        document.getElementById('cnt-stat-adult').textContent = s.adult_18 || 0;
        document.getElementById('cnt-stat-suspicious').textContent = s.suspicious || 0;
        document.getElementById('cnt-stat-safe').textContent = s.safe || 0;
        document.getElementById('cnt-stat-unclassified').textContent = s.unclassified || 0;
        document.getElementById('cnt-stat-quarantined').textContent = s.quarantined || 0;

        if (document.getElementById('stat-quarantined-files')) {
          document.getElementById('stat-quarantined-files').textContent = s.quarantined || 0;
          document.getElementById('content-tab-badge').textContent = s.quarantined || 0;
        }
      } catch (e) {
        console.error('Failed to load content stats', e);
      }
    }

    function setContentCategory(cat) {
      activeContentCategory = cat;
      const cats = ['all', 'ai_generated', 'adult_18', 'suspicious', 'blocked', 'safe', 'unclassified'];
      cats.forEach(c => {
        const btn = document.getElementById(`btn-cat-${c}`);
        if (!btn) return;
        if (c === cat) {
          btn.className = 'px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-blue-600 text-white shadow-xs';
        } else {
          btn.className = 'px-3.5 py-1.5 rounded-xl text-xs font-bold transition bg-slate-100 hover:bg-slate-200 text-slate-700';
        }
      });
      loadContentFiles();
    }

    function debounceLoadContentFiles() {
      clearTimeout(contentSearchTimeout);
      contentSearchTimeout = setTimeout(loadContentFiles, 300);
    }

    function resetContentFilters() {
      document.getElementById('content-filter-search').value = '';
      document.getElementById('content-filter-user').value = '0';
      setContentCategory('all');
    }

    async function populateContentUserFilter() {
      const select = document.getElementById('content-filter-user');
      if (!select) return;
      if (globalUsersCache.length === 0) {
        try {
          const res = await fetch('../api/admin.php?action=all_users');
          const data = await res.json();
          if (data.success) globalUsersCache = data.users || [];
        } catch (e) {}
      }
      const cur = select.value;
      select.innerHTML = '<option value="0">All Uploading Users</option>' +
        globalUsersCache.map(u => `<option value="${u.id}">${escapeHtml(u.username)} (${escapeHtml(u.email)})</option>`).join('');
      if (cur) select.value = cur;
    }

    async function loadContentFiles() {
      const tbody = document.getElementById('content-files-tbody');
      if (!tbody) return;
      tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Analyzing content repository...</td></tr>`;

      const search = document.getElementById('content-filter-search')?.value.trim() || '';
      const userId = document.getElementById('content-filter-user')?.value || '0';

      const params = new URLSearchParams({
        action: 'content_files',
        category: activeContentCategory,
        user_id: userId,
        search,
      });

      try {
        const res = await fetch(`../api/admin.php?${params.toString()}`);
        const data = await res.json();
        if (!data.success) {
          tbody.innerHTML = `<tr><td colspan="6" class="p-6 text-center text-red-500">Failed to load content files: ${data.error || 'Server error'}</td></tr>`;
          return;
        }

        const files = data.files || [];
        document.getElementById('content-count-label').textContent = `${files.length} items displayed`;

        if (files.length === 0) {
          tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-400">
            <i class="fa-solid fa-filter text-2xl text-slate-300 mb-2 block"></i>
            No files found under category "${activeContentCategory}".
          </td></tr>`;
          return;
        }

        tbody.innerHTML = files.map(f => {
          let tagBadge = '<span class="px-2 py-0.5 bg-slate-100 text-slate-600 rounded-md font-bold text-[10px]">Unclassified</span>';
          if (f.content_tag === 'ai_generated') {
            tagBadge = `<span class="px-2.5 py-1 bg-purple-100 text-purple-800 rounded-lg font-bold text-[10px] inline-flex items-center space-x-1 border border-purple-200">
              <i class="fa-solid fa-robot mr-1"></i><span>AI Generated (${f.content_confidence || 85}%)</span>
            </span>`;
          } else if (f.content_tag === 'adult_18') {
            tagBadge = `<span class="px-2.5 py-1 bg-rose-100 text-rose-800 rounded-lg font-bold text-[10px] inline-flex items-center space-x-1 border border-rose-200">
              <i class="fa-solid fa-triangle-exclamation mr-1"></i><span>Adult 18+ (${f.content_confidence || 90}%)</span>
            </span>`;
          } else if (f.content_tag === 'suspicious') {
            tagBadge = `<span class="px-2.5 py-1 bg-amber-100 text-amber-800 rounded-lg font-bold text-[10px] inline-flex items-center space-x-1 border border-amber-200">
              <i class="fa-solid fa-biohazard mr-1"></i><span>Suspicious Payload (${f.content_confidence || 95}%)</span>
            </span>`;
          } else if (f.content_tag === 'safe') {
            tagBadge = `<span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-lg font-bold text-[10px] inline-flex items-center space-x-1 border border-emerald-200">
              <i class="fa-solid fa-shield-heart mr-1"></i><span>Clean / Safe (${f.content_confidence || 99}%)</span>
            </span>`;
          }

          const isBlocked = !!f.is_visibility_blocked;
          const visBadge = isBlocked
            ? `<div class="inline-flex flex-col">
                 <span class="px-2.5 py-1 bg-red-100 text-red-800 rounded-lg font-black text-[10px] border border-red-300 flex items-center space-x-1">
                   <i class="fa-solid fa-lock"></i>
                   <span>QUARANTINED</span>
                 </span>
                 <span class="text-[9px] text-red-600 font-medium mt-0.5">Admin Locked Visibility</span>
               </div>`
            : `<div class="inline-flex flex-col">
                 <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-lg font-bold text-[10px] border border-emerald-200">
                   Active (Visible)
                 </span>
                 <span class="text-[9px] text-emerald-600 font-medium mt-0.5">User Has Full Access</span>
               </div>`;

          const detailsText = f.content_details || 'Probed file headers and MIME metadata';

          return `
            <tr class="hover:bg-slate-50 transition ${isBlocked ? 'bg-red-50/20' : ''}">
              <td class="p-3.5">
                <div class="flex items-center space-x-2">
                  <i class="fa-regular fa-file text-blue-500 text-base"></i>
                  <div>
                    <div class="font-bold text-slate-800 truncate max-w-[180px] sm:max-w-[240px]" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</div>
                    <div class="text-[10px] text-slate-400 flex items-center space-x-2">
                      <span>${formatBytes(f.size_bytes)}</span>
                      <span>·</span>
                      <span class="font-mono">${escapeHtml(f.google_email)}</span>
                    </div>
                  </div>
                </div>
              </td>
              <td class="p-3.5">
                <div class="font-bold text-slate-800 cursor-pointer hover:text-indigo-600" onclick="filterByThisUser(${f.user_id})">
                  ${escapeHtml(f.username)}
                </div>
                <div class="text-[10px] text-slate-400">${escapeHtml(f.user_email)}</div>
              </td>
              <td class="p-3.5">
                ${tagBadge}
              </td>
              <td class="p-3.5 text-slate-600 max-w-[260px]">
                <div class="text-[11px] truncate" title="${escapeHtml(detailsText)}">${escapeHtml(detailsText)}</div>
                ${f.blocked_reason ? `<div class="text-[10px] text-red-500 mt-0.5 font-medium">Reason: ${escapeHtml(f.blocked_reason)}</div>` : ''}
              </td>
              <td class="p-3.5">
                ${visBadge}
              </td>
              <td class="p-3.5 text-right whitespace-nowrap space-x-1.5">
                <button onclick="previewFile(${f.id}, '${escapeHtml(f.name)}', '${f.mime_type}')" title="Admin Preview" class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition font-semibold">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button onclick="toggleFileQuarantine(${f.id}, ${isBlocked ? 0 : 1})" 
                        title="${isBlocked ? 'Unblock Visibility: Allow uploader to view & manage' : 'Block Visibility: User will NOT be able to view, edit, or delete'}" 
                        class="px-2.5 py-1.5 font-bold rounded-xl transition text-[11px] shadow-xs flex items-center space-x-1 inline-flex ${isBlocked ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-rose-600 hover:bg-rose-700 text-white'}">
                  <i class="fa-solid ${isBlocked ? 'fa-unlock' : 'fa-ban'}"></i>
                  <span>${isBlocked ? 'Unblock' : 'Block Visibility'}</span>
                </button>
                <button onclick="reanalyzeFile(${f.id})" title="Re-scan content" class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                  <i class="fa-solid fa-rotate"></i>
                </button>
                <button onclick="deleteContentFilePermanent(${f.id})" title="Delete permanently" class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>
          `;
        }).join('');
      } catch (e) {
        console.error('Failed to load content files', e);
      }
    }

    function filterByThisUser(userId) {
      const select = document.getElementById('content-filter-user');
      if (select) {
        select.value = userId;
        loadContentFiles();
      }
    }

    async function toggleFileQuarantine(fileId, block) {
      let reason = '';
      if (block) {
        reason = await showInAppPrompt({
          title: 'Quarantine File',
          message: 'Enter quarantine reason (shown to admin):',
          defaultValue: 'Quarantined by Administrator for content policy violation',
          confirmText: 'Quarantine'
        });
        if (reason === null) return; // User cancelled
      } else {
        const ok = await showInAppConfirm({
          title: 'Restore File Visibility',
          message: 'Unblock visibility and restore file access to uploader?',
          confirmText: 'Restore Access',
          isDanger: false
        });
        if (!ok) return;
      }

      try {
        const res = await fetch('../api/admin.php?action=content_action', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            subaction: 'toggle_block_visibility',
            file_id: fileId,
            block: block ? 1 : 0,
            reason: reason || 'Quarantined by Administrator',
          }),
        });
        const data = await res.json();
        if (data.success) {
          showInAppToast(block ? 'File quarantined successfully.' : 'File access restored.', 'success');
          loadContentStats();
          loadContentFiles();
          loadStats();
          if (!document.getElementById('tab-content-files').classList.contains('hidden')) {
            loadFiles();
          }
        } else {
          showInAppToast(data.error || 'Failed to toggle quarantine.', 'error');
        }
      } catch (e) {
        showInAppToast('Server connection error.', 'error');
      }
    }

    async function reanalyzeFile(fileId) {
      try {
        const res = await fetch('../api/admin.php?action=content_action', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subaction: 'reanalyze', file_id: fileId }),
        });
        const data = await res.json();
        if (data.success) {
          showInAppToast(`Analysis Result: ${data.tag.toUpperCase()} (${data.confidence}% confidence)\nDetails: ${data.details}`, 'info');
          loadContentStats();
          loadContentFiles();
        } else {
          showInAppToast(data.error || 'Analysis failed.', 'error');
        }
      } catch (e) {
        showInAppToast('Server connection error.', 'error');
      }
    }

    async function batchScanFiles(forceAll = false) {
      const btn = forceAll ? document.getElementById('btn-batch-rescan-all') : document.getElementById('btn-batch-scan');
      const origText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i> Scanning...`;

      const fb = document.getElementById('content-scan-feedback');
      fb.className = 'p-3 rounded-xl text-xs bg-indigo-500/20 text-indigo-200 border border-indigo-500/30 block';
      fb.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Running HTTP Range Probing on Google Drive files...`;

      try {
        const res = await fetch('../api/admin.php?action=content_action', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subaction: 'batch_scan', force_all: forceAll }),
        });
        const data = await res.json();
        if (data.success) {
          fb.className = 'p-3 rounded-xl text-xs bg-emerald-500/20 text-emerald-200 border border-emerald-500/30 block';
          fb.innerHTML = `✅ <strong>Batch scan complete:</strong> Scanned ${data.scanned} files (${data.results.ai_generated} AI, ${data.results.adult_18} Adult, ${data.results.suspicious} Suspicious, ${data.results.safe} Clean).`;
          loadContentStats();
          loadContentFiles();
        } else {
          fb.className = 'p-3 rounded-xl text-xs bg-red-500/20 text-red-200 border border-red-500/30 block';
          fb.textContent = `Scan error: ${data.error || 'Unknown error'}`;
        }
      } catch (e) {
        fb.className = 'p-3 rounded-xl text-xs bg-red-500/20 text-red-200 border border-red-500/30 block';
        fb.textContent = 'Server connection error during batch scan.';
      } finally {
        btn.disabled = false;
        btn.innerHTML = origText;
      }
    }

    async function deleteContentFilePermanent(fileId) {
      const ok = await showInAppConfirm({
        title: 'Delete File Permanently',
        message: 'Are you sure you want to permanently delete this file from Google Drive? This cannot be undone.',
        confirmText: 'Delete Permanently',
        isDanger: true
      });
      if (!ok) return;
      try {
        const res = await fetch('../api/admin.php?action=file_action', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ subaction: 'delete', file_id: fileId }),
        });
        const data = await res.json();
        if (data.success) {
          showInAppToast('File permanently deleted from Google Drive.', 'success');
          loadContentStats();
          loadContentFiles();
          loadStats();
        } else {
          showInAppToast(data.error || 'Failed to delete file.', 'error');
        }
      } catch (e) {
        showInAppToast('Server error.', 'error');
      }
    }

    // 2. ANALYTICS & CHARTS
    async function loadAnalytics() {
      try {
        const res = await fetch('../api/admin.php?action=analytics_stats');
        const data = await res.json();
        if (!data.success) return;
        const an = data.analytics;

        // A. Account Storage Chart
        const accLabels = (an.accounts || []).map(a => a.account_email);
        const accUsed = (an.accounts || []).map(a => (a.used_storage_bytes / (1024*1024*1024)).toFixed(2));
        const accLimit = (an.accounts || []).map(a => (a.storage_limit_bytes / (1024*1024*1024)).toFixed(2));

        const ctxStorage = document.getElementById('chart-account-storage').getContext('2d');
        if (chartStorage) chartStorage.destroy();
        chartStorage = new Chart(ctxStorage, {
          type: 'bar',
          data: {
            labels: accLabels.length ? accLabels : ['No Accounts Connected'],
            datasets: [
              {
                label: 'Used (GB)',
                data: accUsed.length ? accUsed : [0],
                backgroundColor: '#f59e0b',
                borderRadius: 8,
              },
              {
                label: 'CloudDrive Quota (GB)',
                data: accLimit.length ? accLimit : [0],
                backgroundColor: '#3b82f6',
                borderRadius: 8,
              }
            ]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
              y: { beginAtZero: true, title: { display: true, text: 'Gigabytes (GB)' } }
            }
          }
        });

        // B. File Types Doughnut Chart
        const typeLabels = (an.file_types || []).map(t => t.category);
        const typeCounts = (an.file_types || []).map(t => t.count);

        const ctxTypes = document.getElementById('chart-file-types').getContext('2d');
        if (chartTypes) chartTypes.destroy();
        chartTypes = new Chart(ctxTypes, {
          type: 'doughnut',
          data: {
            labels: typeLabels.length ? typeLabels : ['None'],
            datasets: [{
              data: typeCounts.length ? typeCounts : [1],
              backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#94a3b8'],
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
          }
        });

        // C. Upload Activity Line Chart
        const actLabels = (an.activity || []).map(a => a.upload_date);
        const actCounts = (an.activity || []).map(a => a.file_count);

        const ctxAct = document.getElementById('chart-activity').getContext('2d');
        if (chartActivity) chartActivity.destroy();
        chartActivity = new Chart(ctxAct, {
          type: 'line',
          data: {
            labels: actLabels.length ? actLabels : ['Today'],
            datasets: [{
              label: 'Uploads',
              data: actCounts.length ? actCounts : [0],
              borderColor: '#10b981',
              backgroundColor: 'rgba(16, 185, 129, 0.1)',
              fill: true,
              tension: 0.3,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
          }
        });

      } catch (e) {
        console.error('Failed to load analytics', e);
      }
    }

    // 3. LOAD CONNECTED ACCOUNTS
    async function loadAccounts() {
      const grid = document.getElementById('accounts-grid');
      try {
        const res = await fetch('../api/admin.php?action=accounts_list');
        const data = await res.json();
        if (data.success) {
          globalAccountsCache = data.accounts || [];

          // Populate filter dropdown in files tab
          const filterAcc = document.getElementById('filter-files-account');
          if (filterAcc) {
            const currentVal = filterAcc.value;
            filterAcc.innerHTML = `<option value="0">All Connected Gmails</option>` + 
              globalAccountsCache.map(a => `<option value="${a.id}">${a.account_email}</option>`).join('');
            filterAcc.value = currentVal || '0';
          }

          if (data.master_settings && data.master_settings.client_id) {
            document.getElementById('master-client-id').value = data.master_settings.client_id;
          }

          if (globalAccountsCache.length === 0) {
            grid.innerHTML = `
              <div class="col-span-full py-12 text-center text-slate-400 bg-white border border-slate-200 rounded-3xl">
                <i class="fa-solid fa-cloud text-4xl text-blue-400 mb-3"></i>
                <h3 class="font-bold text-slate-700">No Google Accounts Connected Yet</h3>
                <p class="text-xs text-slate-400 mt-1">Paste your Google Drive Key or Refresh Token above to expand your storage pool.</p>
              </div>
            `;
            return;
          }

          grid.innerHTML = globalAccountsCache.map(acc => {
            const usedBytes = acc.used_storage_bytes || 0;
            const limitBytes = acc.storage_limit_bytes || (13 * 1024 * 1024 * 1024);
            const totalCapacity = acc.total_capacity_bytes || (15 * 1024 * 1024 * 1024);
            const ownerUsed = acc.initial_used_bytes || 0;
            const percent = Math.min(100, Math.round((usedBytes / limitBytes) * 100));
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
                        <span>${acc.is_active ? (isFull ? 'Storage Cap Reached' : 'Active') : 'Disabled'}</span>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center space-x-1 text-slate-400">
                    <button onclick="syncQuota(${acc.id})" title="Sync live quota with Google" class="p-1.5 hover:text-blue-600 rounded-lg transition">
                      <i class="fa-solid fa-rotate"></i>
                    </button>
                    <button onclick="deleteAccount(${acc.id})" title="Disconnect" class="p-1.5 hover:text-red-500 rounded-lg transition">
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>

                <!-- Dynamic Quota Breakdown Grid -->
                <div class="grid grid-cols-2 gap-2 text-[10px] bg-slate-50/70 p-2.5 rounded-xl border border-slate-100">
                  <div>
                    <span class="text-slate-400 block">Total Drive Space</span>
                    <strong class="text-slate-700 text-[11px]">${formatBytes(totalCapacity)}</strong>
                  </div>
                  <div>
                    <span class="text-slate-400 block">Owner Prior Used</span>
                    <strong class="text-slate-700 text-[11px]">${formatBytes(ownerUsed)}</strong>
                  </div>
                  <div>
                    <span class="text-slate-400 block">Safety Reserved</span>
                    <strong class="text-emerald-600 text-[11px]">2 GB Buffer</strong>
                  </div>
                  <div>
                    <span class="text-slate-400 block">CloudDrive Quota</span>
                    <strong class="text-blue-600 text-[11px]">${formatBytes(limitBytes)}</strong>
                  </div>
                </div>

                <!-- Progress Meter -->
                <div class="space-y-1.5">
                  <div class="flex items-center justify-between text-xs">
                    <span class="text-slate-500">CloudDrive Used</span>
                    <span class="font-semibold text-slate-800">${formatBytes(usedBytes)} / ${formatBytes(limitBytes)} (${percent}%)</span>
                  </div>
                  <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                    <div class="h-2 rounded-full transition-all duration-300 ${percent > 90 ? 'bg-amber-500' : 'bg-blue-600'}" style="width: ${percent}%"></div>
                  </div>
                  <div class="flex items-center justify-between text-[10px] text-slate-400">
                    <span>${acc.files_count || 0} files stored</span>
                    <span>Target: CloudDrive_files</span>
                  </div>
                </div>

                <!-- Multi-Gmail Inspector Button -->
                <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                  <button onclick="inspectAccountFiles(${acc.id})" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-xl transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Inspect Account Files</span>
                  </button>
                  <label class="flex items-center space-x-1 text-slate-500 cursor-pointer">
                    <input type="checkbox" ${acc.is_active ? 'checked' : ''} onchange="toggleActive(${acc.id}, this.checked)" class="rounded text-blue-600">
                    <span class="text-[11px]">Enabled</span>
                  </label>
                </div>
              </div>
            `;
          }).join('');
        }
      } catch (e) {
        console.error('Failed to load accounts', e);
      }
    }

    // Inspect files in a specific Gmail Account
    function inspectAccountFiles(accountId) {
      switchTab('files');
      const filterAcc = document.getElementById('filter-files-account');
      if (filterAcc) {
        filterAcc.value = accountId;
        loadFiles();
      }
    }

    // Connect Account by Key
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
          fb.innerHTML = `✓ Successfully connected <strong>${data.account_email}</strong>! Allocated <strong>${formatBytes(data.storage_limit_bytes)}</strong> CloudDrive quota.`;
          fb.classList.remove('hidden');
          document.getElementById('quick-key-input').value = '';
          loadStats();
          loadAnalytics();
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
      document.getElementById('master-settings-card').classList.toggle('hidden');
    }

    async function saveMasterSettings() {
      const clientId = document.getElementById('master-client-id').value.trim();
      const clientSecret = document.getElementById('master-client-secret').value.trim();
      await fetch('../api/admin.php?action=save_master_settings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ client_id: clientId, client_secret: clientSecret }),
      });
      showInAppToast('Project credentials saved successfully! Now connecting any Gmail only requires its Refresh Token.', 'success');
      toggleMasterSettings();
    }

    async function syncQuota(id) {
      await fetch('../api/admin.php?action=account_sync', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      loadStats();
      loadAnalytics();
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
      const ok = await showInAppConfirm({
        title: 'Disconnect Google Account',
        message: 'Are you sure you want to disconnect this Google Account? Files stored on it must be migrated first.',
        confirmText: 'Disconnect',
        isDanger: true
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=account_delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!data.success) {
        showInAppToast(data.error, 'error');
      } else {
        showInAppToast('Google Account disconnected.', 'success');
      }
      loadStats();
      loadAnalytics();
      loadAccounts();
    }

    // 4. LOAD GLOBAL FILES (WITH SEARCH & FILTERS)
    function debounceLoadFiles() {
      clearTimeout(filesSearchTimeout);
      filesSearchTimeout = setTimeout(loadFiles, 300);
    }

    function resetFilesFilter() {
      document.getElementById('filter-files-search').value = '';
      document.getElementById('filter-files-user').value = '0';
      document.getElementById('filter-files-account').value = '0';
      document.getElementById('filter-files-type').value = 'all';
      document.getElementById('filter-files-size').value = 'all';
      document.getElementById('filter-files-date').value = 'all';
      document.getElementById('filter-files-sort').value = 'newest';
      loadFiles();
    }

    async function loadFiles() {
      const tbody = document.getElementById('global-files-tbody');
      tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading files...</td></tr>`;

      const search = document.getElementById('filter-files-search').value.trim();
      const userId = document.getElementById('filter-files-user').value;
      const accountId = document.getElementById('filter-files-account').value;
      const fileType = document.getElementById('filter-files-type').value;
      const sizeRange = document.getElementById('filter-files-size').value;
      const dateRange = document.getElementById('filter-files-date').value;
      const sort = document.getElementById('filter-files-sort').value;

      const params = new URLSearchParams({
        action: 'all_files',
        search,
        user_id: userId,
        account_id: accountId,
        file_type: fileType,
        size_range: sizeRange,
        date_range: dateRange,
        sort,
      });

      try {
        const res = await fetch(`../api/admin.php?${params.toString()}`);
        const data = await res.json();
        if (data.success) {
          const files = data.files || [];
          if (files.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400">No files found matching current filters.</td></tr>`;
            return;
          }

          tbody.innerHTML = files.map(f => `
            <tr class="hover:bg-slate-50 transition">
              <td class="p-3.5 font-medium text-slate-800">
                <div class="flex items-center space-x-2">
                  <i class="fa-regular fa-file text-blue-500"></i>
                  <span class="truncate max-w-[200px]" title="${f.name}">${f.name}</span>
                  ${f.is_visibility_blocked ? `<span class="px-1.5 py-0.5 bg-rose-100 text-rose-700 text-[9px] font-black rounded border border-rose-200 uppercase tracking-tight flex items-center space-x-0.5"><i class="fa-solid fa-lock text-[8px] mr-0.5"></i>Locked</span>` : ''}
                </div>
              </td>
              <td class="p-3.5 text-slate-500">${formatBytes(f.size_bytes)}</td>
              <td class="p-3.5 text-slate-700 font-semibold cursor-pointer hover:text-blue-600" onclick="drilldownUser(${f.user_id})">
                ${f.username}
              </td>
              <td class="p-3.5 text-slate-500 font-mono text-[11px]" title="${f.google_email}">${f.google_email}</td>
              <td class="p-3.5 text-slate-400">${new Date(f.created_at).toLocaleDateString()}</td>
              <td class="p-3.5">
                ${f.is_visibility_blocked 
                  ? `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-100 text-rose-700 border border-rose-200 flex items-center w-fit space-x-1"><i class="fa-solid fa-lock text-[9px]"></i><span>Quarantined</span></span>`
                  : `<span class="px-2 py-0.5 rounded-md text-[10px] font-bold ${f.is_public ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500'}">${f.is_public ? 'Public' : 'Private'}</span>`
                }
              </td>
              <td class="p-3.5 text-right space-x-1.5 whitespace-nowrap">
                <button onclick="previewFile(${f.id}, '${escapeHtml(f.name)}', '${f.mime_type}')" title="Preview" class="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition font-semibold">
                  <i class="fa-solid fa-eye"></i>
                </button>
                <button onclick="toggleFileQuarantine(${f.id}, ${f.is_visibility_blocked ? 0 : 1})" title="${f.is_visibility_blocked ? 'Unblock Visibility' : 'Block Visibility (Quarantine)'}" class="p-1.5 ${f.is_visibility_blocked ? 'text-emerald-600 hover:bg-emerald-50' : 'text-rose-600 hover:bg-rose-50'} rounded-lg transition font-semibold">
                  <i class="fa-solid ${f.is_visibility_blocked ? 'fa-unlock' : 'fa-ban'}"></i>
                </button>
                <button onclick="openMoveModal(${f.id}, '${escapeHtml(f.name)}', ${f.google_account_id}, '${escapeHtml(f.google_email)}')" title="Move to another Gmail" class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition font-semibold">
                  <i class="fa-solid fa-arrow-right-arrow-left"></i>
                </button>
                <button onclick="renameFile(${f.id}, '${escapeHtml(f.name)}')" title="Rename" class="p-1.5 text-slate-600 hover:bg-slate-100 rounded-lg transition">
                  <i class="fa-solid fa-pen-to-square"></i>
                </button>
                <button onclick="toggleFileAccess(${f.id}, ${f.is_public ? 1 : 0})" title="${f.is_public ? 'Stop Public Access' : 'Make Public'}" class="p-1.5 ${f.is_public ? 'text-amber-600 hover:bg-amber-50' : 'text-emerald-600 hover:bg-emerald-50'} rounded-lg transition">
                  <i class="fa-solid ${f.is_public ? 'fa-lock' : 'fa-lock-open'}"></i>
                </button>
                <button onclick="deleteFilePermanent(${f.id})" title="Delete permanently" class="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </td>
            </tr>
          `).join('');
        }
      } catch (e) {
        console.error('Failed to load files', e);
      }
    }

    // 5. LOAD USERS LIST
    function debounceLoadUsers() {
      clearTimeout(usersSearchTimeout);
      usersSearchTimeout = setTimeout(loadUsers, 300);
    }

    async function loadUsers() {
      const tbody = document.getElementById('users-tbody');
      tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading users...</td></tr>`;

      const search = document.getElementById('filter-users-search').value.trim();
      const status = document.getElementById('filter-users-status').value;
      const role = document.getElementById('filter-users-role').value;

      const params = new URLSearchParams({
        action: 'all_users',
        search,
        status,
        role,
      });

      try {
        const res = await fetch(`../api/admin.php?${params.toString()}`);
        const data = await res.json();
        if (data.success) {
          globalUsersCache = data.users || [];

          // Populate filter dropdown in files tab
          const filterUser = document.getElementById('filter-files-user');
          if (filterUser) {
            const currentVal = filterUser.value;
            filterUser.innerHTML = `<option value="0">All Users</option>` +
              globalUsersCache.map(u => `<option value="${u.id}">${u.username} (${u.email})</option>`).join('');
            filterUser.value = currentVal || '0';
          }

          if (globalUsersCache.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400">No users found matching current filters.</td></tr>`;
            return;
          }

          tbody.innerHTML = globalUsersCache.map(u => {
            const limitStr = u.storage_limit_bytes ? formatBytes(u.storage_limit_bytes) : 'Unlimited';
            const usedStr = formatBytes(u.total_bytes);
            const isBlocked = !!u.is_blocked;
            const regIp = u.registration_ip || 'N/A';
            const lastIp = u.last_login_ip || 'N/A';
            return `
              <tr class="hover:bg-slate-50 transition">
                <td class="p-3.5 font-bold text-slate-800 cursor-pointer hover:text-blue-600" onclick="drilldownUser(${u.id})">
                  <div class="flex items-center space-x-2">
                    <span>${u.username}</span>
                    <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded font-normal">ID: ${u.id}</span>
                  </div>
                </td>
                <td class="p-3.5 text-slate-500">${u.email}</td>
                <td class="p-3.5">
                  <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${u.role === 'admin' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600'}">
                    ${u.role}
                  </span>
                </td>
                <td class="p-3.5 font-mono text-[11px] text-slate-500">
                  <div>Reg: <strong>${regIp}</strong></div>
                  <div>Last: ${lastIp}</div>
                </td>
                <td class="p-3.5">
                  <div class="text-xs font-semibold text-slate-800">${usedStr} <span class="text-slate-400 font-normal">of ${limitStr}</span></div>
                  <div class="text-[10px] text-slate-400">${u.files_count} files uploaded</div>
                </td>
                <td class="p-3.5">
                  <span class="px-2 py-0.5 rounded-md text-[10px] font-bold ${isBlocked ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'}">
                    ${isBlocked ? 'Blocked' : 'Active'}
                  </span>
                </td>
                <td class="p-3.5 text-right space-x-1 whitespace-nowrap">
                  <button onclick="drilldownUser(${u.id})" title="View Uploaded Files" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg font-bold text-[11px] transition">
                    <i class="fa-solid fa-folder-open mr-1"></i> Files
                  </button>
                  <button onclick="openStorageLimitModal(${u.id}, '${escapeHtml(u.username)}', ${u.storage_limit_bytes || 0})" title="Limit Storage" class="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition">
                    <i class="fa-solid fa-database"></i>
                  </button>
                  <button onclick="openSetPasswordModal(${u.id}, '${escapeHtml(u.username)}')" title="Set Password" class="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition">
                    <i class="fa-solid fa-key"></i>
                  </button>
                  <button onclick="toggleUserBlock(${u.id}, ${isBlocked ? 0 : 1})" title="${isBlocked ? 'Unblock User' : 'Block User'}" class="p-1.5 ${isBlocked ? 'text-emerald-600 hover:bg-emerald-50' : 'text-red-500 hover:bg-red-50'} rounded-lg transition">
                    <i class="fa-solid ${isBlocked ? 'fa-user-check' : 'fa-user-slash'}"></i>
                  </button>
                  <button onclick="blockUserIP(${u.id})" title="Block User IP" class="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition">
                    <i class="fa-solid fa-ban"></i>
                  </button>
                  <button onclick="deleteUserPermanent(${u.id})" title="Delete User" class="p-1.5 text-slate-400 hover:text-red-600 rounded-lg transition">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </td>
              </tr>
            `;
          }).join('');
        }
      } catch (e) {
        console.error('Failed to load users', e);
      }
    }

    // 6. USER DRILLDOWN (VIEW ALL FILES UPLOADED BY USER)
    async function drilldownUser(userId) {
      const modal = document.getElementById('modal-user-drilldown');
      const tbody = document.getElementById('drilldown-files-tbody');
      tbody.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading files for user...</td></tr>`;
      modal.classList.remove('hidden');

      try {
        const res = await fetch(`../api/admin.php?action=user_files&user_id=${userId}`);
        const data = await res.json();
        if (data.success) {
          const u = data.user;
          const files = data.files || [];
          document.getElementById('drilldown-username').textContent = `Files uploaded by ${u.username}`;
          const badge = document.getElementById('drilldown-status-badge');
          if (u.is_blocked) {
            badge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-md uppercase bg-red-100 text-red-700';
            badge.textContent = 'Blocked';
          } else {
            badge.className = 'px-2 py-0.5 text-[10px] font-bold rounded-md uppercase bg-emerald-100 text-emerald-700';
            badge.textContent = 'Active';
          }

          const limit = u.storage_limit_bytes ? formatBytes(u.storage_limit_bytes) : 'Unlimited';
          document.getElementById('drilldown-user-info').textContent = 
            `Email: ${u.email} | Storage Limit: ${limit} | Registered: ${new Date(u.created_at).toLocaleDateString()} | Reg IP: ${u.registration_ip || 'N/A'}`;

          if (files.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-slate-400">This user has not uploaded any files yet.</td></tr>`;
            return;
          }

          tbody.innerHTML = files.map(f => `
            <tr class="hover:bg-slate-50 transition">
              <td class="p-3 font-medium text-slate-800">
                <div class="flex items-center space-x-2">
                  <i class="fa-regular fa-file text-blue-500"></i>
                  <span class="truncate max-w-[200px]">${f.name}</span>
                </div>
              </td>
              <td class="p-3 text-slate-500">${formatBytes(f.size_bytes)}</td>
              <td class="p-3 text-slate-500 font-mono text-[11px]">${f.google_email}</td>
              <td class="p-3 text-slate-400">${new Date(f.created_at).toLocaleDateString()}</td>
              <td class="p-3 text-right space-x-1.5 whitespace-nowrap">
                <button onclick="previewFile(${f.id}, '${escapeHtml(f.name)}', '${f.mime_type}')" class="text-blue-600 font-semibold p-1 hover:bg-blue-50 rounded">Preview</button>
                <button onclick="openMoveModal(${f.id}, '${escapeHtml(f.name)}', ${f.google_account_id}, '${escapeHtml(f.google_email)}')" class="text-indigo-600 font-semibold p-1 hover:bg-indigo-50 rounded">Move Gmail</button>
                <button onclick="toggleFileAccess(${f.id}, ${f.is_public ? 1 : 0})" class="text-amber-600 font-semibold p-1 hover:bg-amber-50 rounded">${f.is_public ? 'Stop Access' : 'Make Public'}</button>
                <button onclick="deleteFilePermanent(${f.id}, ${u.id})" class="text-red-600 font-semibold p-1 hover:bg-red-50 rounded">Delete</button>
              </td>
            </tr>
          `).join('');
        }
      } catch (e) {
        console.error('Failed to load user files', e);
      }
    }

    // 7. FILE ACTIONS (PREVIEW, MOVE GMAIL, RENAME, ACCESS, DELETE)
    function previewFile(id, name, mime) {
      document.getElementById('preview-title').textContent = name;
      const content = document.getElementById('preview-content');
      const dl = document.getElementById('preview-download-link');
      const streamUrl = `../stream.php?id=${id}`;
      dl.href = streamUrl;

      if (mime.startsWith('image/')) {
        content.innerHTML = `<img src="${streamUrl}" class="max-h-[500px] max-w-full rounded-xl object-contain shadow-xs">`;
      } else if (mime.startsWith('video/')) {
        content.innerHTML = `<video controls autoplay class="max-h-[500px] max-w-full rounded-xl shadow-xs"><source src="${streamUrl}" type="${mime}">Your browser does not support the video tag.</video>`;
      } else if (mime.startsWith('audio/')) {
        content.innerHTML = `<div class="p-6 text-center space-y-4"><i class="fa-solid fa-music text-5xl text-blue-500"></i><br><audio controls autoplay class="w-full max-w-md"><source src="${streamUrl}" type="${mime}">Your browser does not support the audio element.</audio></div>`;
      } else if (mime === 'application/pdf') {
        content.innerHTML = `<iframe src="${streamUrl}" class="w-full h-[500px] rounded-xl border border-slate-200"></iframe>`;
      } else {
        content.innerHTML = `
          <div class="text-center p-8 space-y-3">
            <i class="fa-solid fa-file-lines text-5xl text-slate-300"></i>
            <h4 class="font-bold text-slate-700">${name}</h4>
            <p class="text-xs text-slate-400">Direct streaming or binary download available.</p>
          </div>
        `;
      }

      document.getElementById('modal-preview').classList.remove('hidden');
    }

    function openMoveModal(fileId, fileName, currentAccId, currentAccEmail) {
      activeMoveFileId = fileId;
      document.getElementById('move-file-name').textContent = fileName;
      document.getElementById('move-file-current-acc').textContent = `Currently on: ${currentAccEmail}`;
      const sel = document.getElementById('move-target-account');
      sel.innerHTML = globalAccountsCache
        .filter(a => a.id !== currentAccId)
        .map(a => `<option value="${a.id}">${a.account_email} (${formatBytes(a.storage_limit_bytes - a.used_storage_bytes)} free)</option>`)
        .join('');

      const fb = document.getElementById('move-feedback');
      fb.classList.add('hidden');
      document.getElementById('modal-move-file').classList.remove('hidden');
    }

    async function confirmMoveFile() {
      if (!activeMoveFileId) return;
      const targetAccountId = document.getElementById('move-target-account').value;
      const btn = document.getElementById('btn-confirm-move');
      const fb = document.getElementById('move-feedback');
      fb.classList.add('hidden');

      btn.disabled = true;
      btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-1"></i> Transferring...`;

      try {
        const res = await fetch('../api/admin.php?action=move_file_account', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ file_id: activeMoveFileId, target_account_id: targetAccountId }),
        });
        const data = await res.json();
        if (data.success) {
          fb.className = 'p-3 rounded-xl text-xs bg-emerald-50 text-emerald-700 border border-emerald-200';
          fb.textContent = data.message || 'File migrated successfully!';
          fb.classList.remove('hidden');
          setTimeout(() => {
            closeModal('modal-move-file');
            loadStats();
            loadAnalytics();
            loadAccounts();
            loadFiles();
          }, 1200);
        } else {
          fb.className = 'p-3 rounded-xl text-xs bg-red-50 text-red-600 border border-red-200';
          fb.textContent = data.error || 'Failed to move file.';
          fb.classList.remove('hidden');
        }
      } catch (e) {
        fb.className = 'p-3 rounded-xl text-xs bg-red-50 text-red-600 border border-red-200';
        fb.textContent = 'Server connection error.';
        fb.classList.remove('hidden');
      } finally {
        btn.disabled = false;
        btn.innerHTML = `<i class="fa-solid fa-arrow-right-arrow-left"></i> <span>Transfer File</span>`;
      }
    }

    async function renameFile(fileId, currentName) {
      const newName = await showInAppPrompt({
        title: 'Rename File',
        message: 'Enter new file name:',
        defaultValue: currentName,
        confirmText: 'Rename'
      });
      if (!newName || newName === currentName) return;
      const res = await fetch('../api/admin.php?action=file_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'rename', file_id: fileId, name: newName }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('File renamed successfully.', 'success');
        loadFiles();
      } else {
        showInAppToast(data.error || 'Failed to rename file.', 'error');
      }
    }

    async function toggleFileAccess(fileId, currentPublic) {
      const subaction = currentPublic ? 'stop_access' : 'allow_access';
      const res = await fetch('../api/admin.php?action=file_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction, file_id: fileId }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast(currentPublic ? 'Public sharing revoked.' : 'Public sharing enabled!', 'success');
        loadFiles();
        loadSharedLinks();
      } else {
        showInAppToast(data.error || 'Failed to update access.', 'error');
      }
    }

    async function deleteFilePermanent(fileId, reloadUserId = null) {
      const ok = await showInAppConfirm({
        title: 'Delete File Permanently',
        message: 'Are you sure you want to permanently delete this file from Google Drive and CloudDrive?',
        confirmText: 'Delete Permanently',
        isDanger: true
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=file_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'delete', file_id: fileId }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('File permanently deleted.', 'success');
        loadStats();
        loadAccounts();
        loadFiles();
        if (reloadUserId) drilldownUser(reloadUserId);
      } else {
        showInAppToast(data.error || 'Failed to delete file.', 'error');
      }
    }

    // 8. USER ACTIONS (BLOCK, STORAGE LIMIT, PASSWORD, DELETE, BLOCK IP)
    async function toggleUserBlock(userId, newBlockedState) {
      const actionName = newBlockedState ? 'block' : 'unblock';
      const ok = await showInAppConfirm({
        title: `${newBlockedState ? 'Block' : 'Unblock'} User`,
        message: `Are you sure you want to ${actionName} this user?`,
        confirmText: newBlockedState ? 'Block User' : 'Unblock User',
        isDanger: newBlockedState
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=user_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'toggle_block', user_id: userId, is_blocked: newBlockedState }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast(`User ${actionName}ed successfully.`, 'success');
        loadStats();
        loadUsers();
      } else {
        showInAppToast(data.error || 'Failed to update user block state.', 'error');
      }
    }

    function openStorageLimitModal(userId, username, currentBytes) {
      activeLimitUserId = userId;
      document.getElementById('limit-modal-username').textContent = username;
      const mb = currentBytes > 0 ? Math.round(currentBytes / (1024 * 1024)) : 0;
      document.getElementById('limit-input-mb').value = mb;
      document.getElementById('modal-storage-limit').classList.remove('hidden');
    }

    function setLimitPreset(mb) {
      document.getElementById('limit-input-mb').value = mb;
    }

    async function confirmStorageLimit() {
      if (!activeLimitUserId) return;
      const mb = parseInt(document.getElementById('limit-input-mb').value) || 0;
      const bytes = mb > 0 ? mb * 1024 * 1024 : null;

      const res = await fetch('../api/admin.php?action=user_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'set_storage_limit', user_id: activeLimitUserId, storage_limit_bytes: bytes }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('Storage limit updated.', 'success');
        closeModal('modal-storage-limit');
        loadUsers();
      } else {
        showInAppToast(data.error || 'Failed to update storage limit.', 'error');
      }
    }

    function openSetPasswordModal(userId, username) {
      activePassUserId = userId;
      document.getElementById('password-modal-username').textContent = username;
      document.getElementById('new-password-input').value = '';
      document.getElementById('modal-set-password').classList.remove('hidden');
    }

    async function confirmSetPassword() {
      if (!activePassUserId) return;
      const password = document.getElementById('new-password-input').value;
      if (password.length < 6) {
        showInAppToast('Password must be at least 6 characters.', 'error');
        return;
      }

      const res = await fetch('../api/admin.php?action=user_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'set_password', user_id: activePassUserId, password }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('Password updated successfully!', 'success');
        closeModal('modal-set-password');
      } else {
        showInAppToast(data.error || 'Failed to update password.', 'error');
      }
    }

    async function blockUserIP(userId) {
      const ok = await showInAppConfirm({
        title: 'Block User IP',
        message: 'Block all IP addresses associated with this user?',
        confirmText: 'Block IP',
        isDanger: true
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=user_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'block_ip', user_id: userId }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast("Successfully blacklisted user's IP address!", 'success');
        loadStats();
        loadBlockedIps();
      } else {
        showInAppToast(data.error || 'Failed to block IP.', 'error');
      }
    }

    async function deleteUserPermanent(userId) {
      const ok = await showInAppConfirm({
        title: 'Delete User Permanently',
        message: 'Are you sure you want to permanently delete this user and all files uploaded by them? This cannot be undone!',
        confirmText: 'Delete User',
        isDanger: true
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=user_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'delete', user_id: userId }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('User permanently deleted.', 'success');
        loadStats();
        loadAccounts();
        loadUsers();
        loadFiles();
      } else {
        showInAppToast(data.error || 'Failed to delete user.', 'error');
      }
    }

    // 9. SHARED LINKS AUDITING
    async function loadSharedLinks() {
      const tbody = document.getElementById('shared-links-tbody');
      tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading shared links...</td></tr>`;

      try {
        const res = await fetch('../api/admin.php?action=shared_links_list');
        const data = await res.json();
        if (data.success) {
          const links = data.links || [];
          if (links.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="p-6 text-center text-slate-400">No public shareable links currently exist.</td></tr>`;
            return;
          }

          tbody.innerHTML = links.map(l => {
            let fullUrl = '';
            let badgeClass = '';
            if (l.item_type === 'file') {
              fullUrl = `${window.location.origin}/CloudDrive/share.php?token=${l.share_token}`;
              badgeClass = 'bg-blue-100 text-blue-700';
            } else if (l.item_type === 'folder') {
              fullUrl = `${window.location.origin}/CloudDrive/share.php?token=${l.share_token}`;
              badgeClass = 'bg-amber-100 text-amber-700';
            } else {
              fullUrl = `${window.location.origin}/CloudDrive/temp.php?token=${l.share_token}`;
              badgeClass = 'bg-purple-100 text-purple-700';
            }

            const info = l.expires_at ? `Expires: ${new Date(l.expires_at).toLocaleString()}` : `${formatBytes(l.size_bytes)}`;

            return `
              <tr class="hover:bg-slate-50 transition">
                <td class="p-3.5 font-bold text-slate-800">
                  <div class="truncate max-w-[200px]" title="${l.name}">${l.name}</div>
                </td>
                <td class="p-3.5">
                  <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase ${badgeClass}">
                    ${l.item_type}
                  </span>
                </td>
                <td class="p-3.5 text-slate-700 font-semibold">
                  <div>${l.username}</div>
                  <div class="text-[10px] text-slate-400 font-normal">${l.user_email}</div>
                </td>
                <td class="p-3.5">
                  <div class="flex items-center space-x-1.5 font-mono text-[11px] text-blue-600">
                    <a href="${fullUrl}" target="_blank" class="truncate max-w-[200px] hover:underline">${fullUrl}</a>
                    <button onclick="copyToClipboard('${fullUrl}')" title="Copy Link" class="p-1 hover:text-blue-800 text-slate-400">
                      <i class="fa-regular fa-copy"></i>
                    </button>
                  </div>
                </td>
                <td class="p-3.5 text-slate-400">${new Date(l.created_at).toLocaleDateString()}</td>
                <td class="p-3.5 text-[11px] text-slate-500">${info}</td>
                <td class="p-3.5 text-right whitespace-nowrap">
                  <button onclick="revokeSharedLink('${l.item_type}', ${l.id})" class="px-3 py-1 bg-red-50 hover:bg-red-100 text-red-600 font-bold rounded-xl transition text-[11px]">
                    <i class="fa-solid fa-ban mr-1"></i> Revoke Access
                  </button>
                </td>
              </tr>
            `;
          }).join('');
        }
      } catch (e) {
        console.error('Failed to load shared links', e);
      }
    }

    async function revokeSharedLink(type, id) {
      const ok = await showInAppConfirm({
        title: 'Revoke Shared Link',
        message: `Are you sure you want to stop access and revoke this shareable ${type} link?`,
        confirmText: 'Revoke Link',
        isDanger: true
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=revoke_shared_link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type, id }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('Shared link revoked.', 'success');
        loadStats();
        loadSharedLinks();
      } else {
        showInAppToast(data.error || 'Failed to revoke link.', 'error');
      }
    }

    // 10. IP SECURITY
    async function loadBlockedIps() {
      const tbody = document.getElementById('blocked-ips-tbody');
      tbody.innerHTML = `<tr><td colspan="4" class="p-6 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Loading blacklist...</td></tr>`;

      try {
        const res = await fetch('../api/admin.php?action=ip_list');
        const data = await res.json();
        if (data.success) {
          const ips = data.blocked_ips || [];
          document.getElementById('blocked-ips-count-label').textContent = `${ips.length} IPs blocked`;
          if (ips.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" class="p-6 text-center text-slate-400">No IP addresses are currently blacklisted.</td></tr>`;
            return;
          }

          tbody.innerHTML = ips.map(ip => `
            <tr class="hover:bg-slate-50 transition">
              <td class="p-3.5 font-mono font-bold text-rose-600">${ip.ip_address}</td>
              <td class="p-3.5 text-slate-600">${ip.reason || 'Blocked by Admin'}</td>
              <td class="p-3.5 text-slate-400">${new Date(ip.blocked_at).toLocaleString()}</td>
              <td class="p-3.5 text-right whitespace-nowrap">
                <button onclick="unblockIP(${ip.id})" class="px-3 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition text-[11px]">
                  <i class="fa-solid fa-unlock mr-1"></i> Unblock
                </button>
              </td>
            </tr>
          `).join('');
        }
      } catch (e) {
        console.error('Failed to load blocked IPs', e);
      }
    }

    async function handleBlockIpSubmit(e) {
      e.preventDefault();
      const ip = document.getElementById('new-block-ip').value.trim();
      const reason = document.getElementById('new-block-reason').value.trim();

      const res = await fetch('../api/admin.php?action=ip_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'block', ip_address: ip, reason }),
      });
      const data = await res.json();
      if (data.success) {
        document.getElementById('new-block-ip').value = '';
        document.getElementById('new-block-reason').value = '';
        showInAppToast('IP blocked successfully.', 'success');
        loadStats();
        loadBlockedIps();
      } else {
        showInAppToast(data.error || 'Failed to block IP.', 'error');
      }
    }

    async function unblockIP(id) {
      const ok = await showInAppConfirm({
        title: 'Unblock IP Address',
        message: 'Are you sure you want to unblock this IP address?',
        confirmText: 'Unblock IP',
        isDanger: false
      });
      if (!ok) return;
      const res = await fetch('../api/admin.php?action=ip_action', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ subaction: 'unblock', id }),
      });
      const data = await res.json();
      if (data.success) {
        showInAppToast('IP unblocked successfully.', 'success');
        loadStats();
        loadBlockedIps();
      } else {
        showInAppToast(data.error || 'Failed to unblock IP.', 'error');
      }
    }

    // Helper functions
    function copyToClipboard(text) {
      navigator.clipboard.writeText(text);
      showInAppToast('Link copied to clipboard!', 'success');
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
      });
    }

    async function adminLogout() {
      await fetch('../api/auth.php?action=logout');
      window.location.reload();
    }
  </script>
<?php endif; ?>

</body>
</html>
