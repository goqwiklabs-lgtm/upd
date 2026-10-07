import React, { useEffect, useState } from 'react';
import {
  X,
  Shield,
  Cloud,
  Users,
  Files,
  Plus,
  RotateCw,
  Trash2,
  Folder,
} from 'lucide-react';
import type { GoogleAccount } from '../types';

function formatBytes(bytes?: number, decimals: number = 2): string {
  if (!bytes || bytes <= 0) return '0 B';
  const k = 1024;
  const dm = decimals < 0 ? 0 : decimals;
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

interface Props {
  onClose: () => void;
}

export const AdminPanelModal: React.FC<Props> = ({ onClose }) => {
  const [activeTab, setActiveTab] = useState<'accounts' | 'files' | 'users'>('accounts');
  const [stats, setStats] = useState<any>(null);
  const [accounts, setAccounts] = useState<GoogleAccount[]>([]);
  const [globalFiles, setGlobalFiles] = useState<any[]>([]);
  const [allUsers, setAllUsers] = useState<any[]>([]);
  const [showAddForm, setShowAddForm] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  // Form fields
  const [email, setEmail] = useState('');
  const [clientId, setClientId] = useState('');
  const [clientSecret, setClientSecret] = useState('');
  const [refreshToken, setRefreshToken] = useState('');

  const loadData = async () => {
    try {
      const sRes = await fetch('/api/admin.php?action=stats');
      const sData = await sRes.json();
      if (sData.success) setStats(sData.stats);

      const aRes = await fetch('/api/admin.php?action=accounts_list');
      const aData = await aRes.json();
      if (aData.success) setAccounts(aData.accounts || []);
    } catch (err) {
      console.error(err);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const loadGlobalFiles = async () => {
    const res = await fetch('/api/admin.php?action=all_files');
    const data = await res.json();
    if (data.success) setGlobalFiles(data.files || []);
  };

  const loadAllUsers = async () => {
    const res = await fetch('/api/admin.php?action=all_users');
    const data = await res.json();
    if (data.success) setAllUsers(data.users || []);
  };

  const handleTabChange = (tab: 'accounts' | 'files' | 'users') => {
    setActiveTab(tab);
    if (tab === 'files') loadGlobalFiles();
    if (tab === 'users') loadAllUsers();
  };

  const handleAddAccount = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setFormError('');

    try {
      const res = await fetch('/api/admin.php?action=account_add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          account_email: email.trim(),
          client_id: clientId.trim(),
          client_secret: clientSecret.trim(),
          refresh_token: refreshToken.trim(),
        }),
      });
      const data = await res.json();
      if (data.success) {
        setShowAddForm(false);
        setEmail('');
        setClientId('');
        setClientSecret('');
        setRefreshToken('');
        loadData();
      } else {
        setFormError(data.error || 'Failed to verify Google account.');
      }
    } catch (err: any) {
      setFormError('Server error verifying account credentials.');
    } finally {
      setIsSubmitting(false);
    }
  };

  const syncQuota = async (id: number) => {
    await fetch('/api/admin.php?action=account_sync', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    loadData();
  };

  const toggleActive = async (id: number, currentActive: number) => {
    await fetch('/api/admin.php?action=account_toggle', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, is_active: currentActive ? 0 : 1 }),
    });
    loadData();
  };

  const deleteAccount = async (id: number) => {
    if (!confirm('Are you sure you want to disconnect this Google Account?')) return;
    const res = await fetch('/api/admin.php?action=account_delete', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id }),
    });
    const data = await res.json();
    if (!data.success) alert(data.error);
    else loadData();
  };

  return (
    <div className="fixed inset-0 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4 z-50 animate-in fade-in">
      <div className="bg-slate-100 rounded-3xl max-w-5xl w-full max-h-[90vh] flex flex-col overflow-hidden shadow-2xl border border-slate-200">
        {/* Header */}
        <div className="p-6 bg-white border-b border-slate-200 flex items-center justify-between shrink-0">
          <div className="flex items-center space-x-3">
            <div className="w-10 h-10 rounded-2xl bg-amber-500 text-white flex items-center justify-center shadow-lg shadow-amber-500/25">
              <Shield className="w-5 h-5" />
            </div>
            <div>
              <h2 className="text-lg font-bold text-slate-800">CloudDrive Admin Control Panel</h2>
              <p className="text-xs text-slate-400">
                Multi-Account Google Drive Pool & Storage Engine
              </p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="w-8 h-8 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-500 flex items-center justify-center transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Stats Row */}
        {stats && (
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-6 bg-slate-50 border-b border-slate-200 text-xs">
            <div className="bg-white p-3.5 rounded-2xl border border-slate-200">
              <span className="text-slate-400 font-medium">Storage Pool Used</span>
              <div className="text-base font-bold text-slate-800 mt-1">
                {formatBytes(stats.pool_used_bytes)}
              </div>
              <span className="text-[10px] text-slate-400">
                of {formatBytes(stats.pool_total_bytes)} capacity
              </span>
            </div>
            <div className="bg-white p-3.5 rounded-2xl border border-slate-200">
              <span className="text-slate-400 font-medium">Connected Accounts</span>
              <div className="text-base font-bold text-slate-800 mt-1">
                {stats.active_google_accounts}
              </div>
              <span className="text-[10px] text-slate-400">Dynamic quota (-2GB buffer)</span>
            </div>
            <div className="bg-white p-3.5 rounded-2xl border border-slate-200">
              <span className="text-slate-400 font-medium">Total Files</span>
              <div className="text-base font-bold text-slate-800 mt-1">{stats.total_files}</div>
              <span className="text-[10px] text-slate-400">Stored on Google Drive</span>
            </div>
            <div className="bg-white p-3.5 rounded-2xl border border-slate-200">
              <span className="text-slate-400 font-medium">Registered Users</span>
              <div className="text-base font-bold text-slate-800 mt-1">{stats.total_users}</div>
              <span className="text-[10px] text-slate-400">In MySQL database</span>
            </div>
          </div>
        )}

        {/* Navigation Tabs */}
        <div className="flex border-b border-slate-200 bg-white px-6 space-x-6 text-xs font-semibold shrink-0">
          <button
            onClick={() => handleTabChange('accounts')}
            className={`py-3.5 border-b-2 transition flex items-center space-x-2 ${
              activeTab === 'accounts'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <Cloud className="w-4 h-4" />
            <span>Google Accounts Pool</span>
          </button>
          <button
            onClick={() => handleTabChange('files')}
            className={`py-3.5 border-b-2 transition flex items-center space-x-2 ${
              activeTab === 'files'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <Files className="w-4 h-4" />
            <span>Global File Manager</span>
          </button>
          <button
            onClick={() => handleTabChange('users')}
            className={`py-3.5 border-b-2 transition flex items-center space-x-2 ${
              activeTab === 'users'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <Users className="w-4 h-4" />
            <span>Users List</span>
          </button>
        </div>

        {/* Tab Contents */}
        <div className="p-6 overflow-y-auto flex-1 space-y-6">
          {activeTab === 'accounts' && (
            <div className="space-y-6">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="font-bold text-slate-800 text-sm">
                    Active Storage Pool Accounts
                  </h3>
                  <p className="text-xs text-slate-400">
                    When one account reaches its allocated quota, uploads automatically roll over to the next
                    account in the pool.
                  </p>
                </div>
                <button
                  onClick={() => setShowAddForm(!showAddForm)}
                  className="px-3.5 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition shadow-xs flex items-center space-x-1.5"
                >
                  <Plus className="w-3.5 h-3.5" />
                  <span>Connect New Gmail Account</span>
                </button>
              </div>

              {/* Add Account Card */}
              {showAddForm && (
                <div className="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm space-y-4 animate-in fade-in">
                  <div className="flex items-center justify-between">
                    <h4 className="font-bold text-sm text-slate-800">
                      Connect Google Cloud Storage Account
                    </h4>
                    <button
                      onClick={() => setShowAddForm(false)}
                      className="text-slate-400 hover:text-slate-600 text-xs"
                    >
                      Cancel
                    </button>
                  </div>

                  <div className="p-3 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-800 space-y-1">
                    <p className="font-bold">Setup Instructions:</p>
                    <p>
                      1. Go to Google Cloud Console, enable <strong>Google Drive API</strong>, and
                      create OAuth 2.0 Credentials.
                    </p>
                    <p>
                      2. Use Google OAuth Playground to authorize{' '}
                      <code>https://www.googleapis.com/auth/drive</code> and get your Refresh Token.
                    </p>
                  </div>

                  {formError && (
                    <div className="p-3 bg-red-50 text-red-600 text-xs rounded-xl border border-red-200">
                      {formError}
                    </div>
                  )}

                  <form onSubmit={handleAddAccount} className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div>
                      <label className="block font-semibold text-slate-700 mb-1">
                        Gmail Account Email
                      </label>
                      <input
                        type="email"
                        required
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        placeholder="storage1@gmail.com"
                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block font-semibold text-slate-700 mb-1">
                        Client ID
                      </label>
                      <input
                        type="text"
                        required
                        value={clientId}
                        onChange={(e) => setClientId(e.target.value)}
                        placeholder="xxxx.apps.googleusercontent.com"
                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 font-mono"
                      />
                    </div>
                    <div>
                      <label className="block font-semibold text-slate-700 mb-1">
                        Client Secret
                      </label>
                      <input
                        type="password"
                        required
                        value={clientSecret}
                        onChange={(e) => setClientSecret(e.target.value)}
                        placeholder="GOCSPX-xxxxxx"
                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 font-mono"
                      />
                    </div>
                    <div>
                      <label className="block font-semibold text-slate-700 mb-1">
                        Refresh Token
                      </label>
                      <input
                        type="text"
                        required
                        value={refreshToken}
                        onChange={(e) => setRefreshToken(e.target.value)}
                        placeholder="1//04xxxxxx"
                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl outline-none focus:bg-white focus:border-blue-500 font-mono"
                      />
                    </div>
                    <div className="col-span-full flex justify-end pt-2">
                      <button
                        type="submit"
                        disabled={isSubmitting}
                        className="px-5 py-2.5 bg-blue-600 hover:bg-blue-500 text-white font-semibold rounded-xl transition shadow-xs flex items-center space-x-1.5"
                      >
                        {isSubmitting ? 'Verifying with Google...' : 'Verify & Add to Pool'}
                      </button>
                    </div>
                  </form>
                </div>
              )}

              {/* Accounts Cards Grid */}
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                {accounts.map((acc: GoogleAccount) => {
                  const usedBytes = acc.used_storage_bytes || 0;
                  const limitBytes = acc.storage_limit_bytes || (13 * 1024 * 1024 * 1024);
                  const totalCapacity = acc.total_capacity_bytes || (15 * 1024 * 1024 * 1024);
                  const ownerUsed = acc.initial_used_bytes || 0;
                  const percent = Math.min(
                    100,
                    Math.round((usedBytes / limitBytes) * 100)
                  );
                  const isFull = percent >= 100;
                  return (
                    <div
                      key={acc.id}
                      className={`bg-white border ${isFull ? 'border-amber-300' : 'border-slate-200'} rounded-2xl p-5 shadow-xs space-y-4`}
                    >
                      <div className="flex items-start justify-between">
                        <div className="flex items-center space-x-3">
                          <div
                            className={`w-9 h-9 rounded-xl ${
                              acc.is_active ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-400'
                            } flex items-center justify-center`}
                          >
                            <Cloud className="w-5 h-5" />
                          </div>
                          <div>
                            <h4
                              className="font-bold text-xs text-slate-800 truncate max-w-[150px]"
                              title={acc.account_email}
                            >
                              {acc.account_email}
                            </h4>
                            <span
                              className={`text-[10px] font-semibold ${
                                acc.is_active
                                  ? isFull
                                    ? 'text-amber-500'
                                    : 'text-emerald-600'
                                  : 'text-slate-400'
                              }`}
                            >
                              {acc.is_active ? (isFull ? 'Storage Cap Reached' : 'Active') : 'Disabled'}
                            </span>
                          </div>
                        </div>
                        <div className="flex items-center space-x-1 text-slate-400">
                          <button
                            onClick={() => syncQuota(acc.id)}
                            className="p-1 hover:text-blue-600 rounded-lg transition"
                            title="Sync live quota with Google"
                          >
                            <RotateCw className="w-3.5 h-3.5" />
                          </button>
                          <button
                            onClick={() => deleteAccount(acc.id)}
                            className="p-1 hover:text-red-500 rounded-lg transition"
                            title="Disconnect account"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </div>

                      {/* Folder Badge */}
                      <div className="flex items-center justify-between text-[11px] bg-slate-50 px-3 py-1.5 rounded-xl border border-slate-100">
                        <span className="text-slate-500 font-medium flex items-center space-x-1.5">
                          <Folder className="w-3.5 h-3.5 text-amber-500" />
                          <span>Target Folder:</span>
                        </span>
                        <span className="font-mono font-bold text-blue-600">CloudDrive_files</span>
                      </div>

                      {/* Dynamic Quota Breakdown Grid */}
                      <div className="grid grid-cols-2 gap-2 text-[10px] bg-slate-50/70 p-2.5 rounded-xl border border-slate-100">
                        <div>
                          <span className="text-slate-400 block">Total Drive Space</span>
                          <strong className="text-slate-700 text-[11px]">{formatBytes(totalCapacity)}</strong>
                        </div>
                        <div>
                          <span className="text-slate-400 block">Owner Prior Used</span>
                          <strong className="text-slate-700 text-[11px]">{formatBytes(ownerUsed)}</strong>
                        </div>
                        <div>
                          <span className="text-slate-400 block">Safety Reserved</span>
                          <strong className="text-emerald-600 text-[11px]">2 GB Buffer</strong>
                        </div>
                        <div>
                          <span className="text-slate-400 block">CloudDrive Quota</span>
                          <strong className="text-blue-600 text-[11px]">{formatBytes(limitBytes)}</strong>
                        </div>
                      </div>

                      {/* Progress Meter */}
                      <div className="space-y-1.5">
                        <div className="flex justify-between text-[11px]">
                          <span className="text-slate-500">CloudDrive Used</span>
                          <span className="font-bold text-slate-700">
                            {formatBytes(usedBytes)} / {formatBytes(limitBytes)} ({percent}%)
                          </span>
                        </div>
                        <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                          <div
                            className={`h-2 rounded-full transition-all duration-300 ${
                              percent > 90 ? 'bg-amber-500' : 'bg-blue-600'
                            }`}
                            style={{ width: `${percent}%` }}
                          ></div>
                        </div>
                        <div className="flex justify-between text-[10px] text-slate-400">
                          <span>{acc.files_count || 0} files stored</span>
                          <span>Auto-rollover ready</span>
                        </div>
                      </div>

                      <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span className="text-slate-500">Enable in Pool</span>
                        <input
                          type="checkbox"
                          checked={acc.is_active === 1}
                          onChange={() => toggleActive(acc.id, acc.is_active)}
                          className="toggle cursor-pointer"
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}

          {activeTab === 'files' && (
            <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 border-b border-slate-200 uppercase text-slate-400 font-semibold">
                  <tr>
                    <th className="p-3.5">Name</th>
                    <th className="p-3.5">Size</th>
                    <th className="p-3.5">User</th>
                    <th className="p-3.5">Stored On</th>
                    <th className="p-3.5">Date</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {globalFiles.map((file: any) => (
                    <tr key={file.id} className="hover:bg-slate-50">
                      <td className="p-3.5 font-medium text-slate-800">{file.name}</td>
                      <td className="p-3.5 text-slate-500">
                        {(file.size_bytes / (1024 * 1024)).toFixed(1)} MB
                      </td>
                      <td className="p-3.5 text-slate-700">{file.username}</td>
                      <td className="p-3.5 text-slate-500 font-mono text-[11px]">
                        {file.google_email}
                      </td>
                      <td className="p-3.5 text-slate-400">
                        {new Date(file.created_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {activeTab === 'users' && (
            <div className="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-xs">
              <table className="w-full text-left text-xs">
                <thead className="bg-slate-50 border-b border-slate-200 uppercase text-slate-400 font-semibold">
                  <tr>
                    <th className="p-3.5">Username</th>
                    <th className="p-3.5">Email</th>
                    <th className="p-3.5">Role</th>
                    <th className="p-3.5">Files Count</th>
                    <th className="p-3.5">Registered</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {allUsers.map((u: any) => (
                    <tr key={u.id} className="hover:bg-slate-50">
                      <td className="p-3.5 font-bold text-slate-800">{u.username}</td>
                      <td className="p-3.5 text-slate-600">{u.email}</td>
                      <td className="p-3.5">
                        <span className="px-2 py-0.5 rounded-md font-bold uppercase text-[10px] bg-slate-100">
                          {u.role}
                        </span>
                      </td>
                      <td className="p-3.5 text-slate-600">{u.files_count}</td>
                      <td className="p-3.5 text-slate-400">
                        {new Date(u.created_at).toLocaleDateString()}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};
