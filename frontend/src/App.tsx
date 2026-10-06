import React, { useEffect, useState } from 'react';
import {
  Cloud,
  Search,
  Shield,
  LogOut,
  FolderPlus,
  Upload,
  HardDrive,
  Clock,
  Share2,
} from 'lucide-react';
import type { User, FolderItem, FileItem, BreadcrumbItem, UploadItem } from './types';
import { BasketballDropzone } from './components/BasketballDropzone';
import { FileExplorer } from './components/FileExplorer';
import { PreviewModal } from './components/PreviewModal';
import { AdminPanelModal } from './components/AdminPanelModal';
import { AuthModal } from './components/AuthModal';

export const App: React.FC = () => {
  const [user, setUser] = useState<User | null>(null);
  const [isAuthOpen, setIsAuthOpen] = useState(false);
  const [isAdminOpen, setIsAdminOpen] = useState(false);
  const [previewFile, setPreviewFile] = useState<FileItem | null>(null);

  const [currentFolderId, setCurrentFolderId] = useState<number | null>(null);
  const [folders, setFolders] = useState<FolderItem[]>([]);
  const [files, setFiles] = useState<FileItem[]>([]);
  const [breadcrumbs, setBreadcrumbs] = useState<BreadcrumbItem[]>([]);
  const [totalUserBytes, setTotalUserBytes] = useState(0);

  const [uploadedCount, setUploadedCount] = useState(0);
  const [recentUpload, setRecentUpload] = useState<UploadItem | null>(null);
  const [searchQuery, setSearchQuery] = useState('');

  // 1. Check user session on load
  useEffect(() => {
    fetch('/api/auth.php?action=me')
      .then((res) => res.json())
      .then((data) => {
        if (data.authenticated) {
          setUser(data.user);
          loadFolderContent(null);
        } else {
          setIsAuthOpen(true);
        }
      })
      .catch(() => setIsAuthOpen(true));
  }, []);

  // 2. Load folder contents
  const loadFolderContent = async (folderId: number | null) => {
    setCurrentFolderId(folderId);
    try {
      const q = folderId !== null ? `?action=list&folder_id=${folderId}` : '?action=list';
      const res = await fetch(`/api/files.php${q}`);
      const data = await res.json();
      if (data.success) {
        setFolders(data.folders || []);
        setFiles(data.files || []);
        setBreadcrumbs(data.breadcrumbs || []);
        setTotalUserBytes(data.total_user_bytes || 0);
      }
    } catch (err) {
      console.error('Error fetching files:', err);
    }
  };

  // 3. Direct Browser-to-Google Drive Resumable Upload
  const handleUploadFiles = async (selectedFiles: File[]) => {
    for (const file of selectedFiles) {
      const uploadId = 'up_' + Math.random().toString(36).substring(2, 9);
      const item: UploadItem = {
        id: uploadId,
        name: file.name,
        size: file.size,
        progress: 0,
        status: 'connecting',
      };
      setRecentUpload(item);

      try {
        // Step 1: Request upload session from PHP backend (assigns account under 13 GB)
        const initRes = await fetch('/api/upload_init.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: file.name,
            size: file.size,
            mimeType: file.type || 'application/octet-stream',
            folder_id: currentFolderId,
            origin: window.location.origin,
          }),
        });
        const initData = await initRes.json();
        if (!initData.success) {
          throw new Error(initData.error || 'Failed to initialize Google upload');
        }

        const { upload_url, google_account_id, share_token } = initData;

        // Step 2: Stream in 2MB chunks directly or via relay if CORS blocked
        const CHUNK_SIZE = 2 * 1024 * 1024;
        let start = 0;
        const total = file.size;

        while (start < total) {
          const end = Math.min(start + CHUNK_SIZE, total);
          const chunk = file.slice(start, end);
          const contentRange = `bytes ${start}-${end - 1}/${total}`;

          let putRes: Response;
          try {
            // Attempt 1: Direct browser-to-Google streaming
            putRes = await fetch(upload_url, {
              method: 'PUT',
              headers: {
                'Content-Range': contentRange,
              },
              body: chunk,
            });
          } catch (netErr) {
            // Attempt 2: If browser blocks cross-origin PUT (CORS), relay chunk through server
            const relayUrl = `/api/upload_chunk.php?upload_url=${encodeURIComponent(upload_url)}`;
            putRes = await fetch(relayUrl, {
              method: 'POST',
              headers: {
                'Content-Range': contentRange,
              },
              body: chunk,
            });
          }

          start = end;
          const pct = Math.round((start / total) * 100);
          setRecentUpload((prev) => (prev ? { ...prev, progress: pct, status: 'uploading' } : null));

          // Upload complete
          if (putRes.status === 200 || putRes.status === 201) {
            const googleInfo = await putRes.json();

            // Step 3: Record file in MySQL database
            await fetch('/api/upload_finish.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                google_account_id,
                google_file_id: googleInfo.id,
                name: file.name,
                size: file.size,
                mime_type: file.type || 'application/octet-stream',
                folder_id: currentFolderId,
                share_token,
              }),
            });

            setRecentUpload((prev) => (prev ? { ...prev, progress: 100, status: 'completed' } : null));
            setUploadedCount((c) => c + 1);
            loadFolderContent(currentFolderId);
            break;
          }
        }
      } catch (err: any) {
        console.error('Upload failed:', err);
        setRecentUpload((prev) =>
          prev ? { ...prev, status: 'error', errorMessage: err.message } : null
        );
      }
    }
  };

  // Folder & File CRUD
  const handleCreateFolder = async (name: string) => {
    await fetch('/api/files.php?action=create_folder', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name, parent_id: currentFolderId }),
    });
    loadFolderContent(currentFolderId);
  };

  const handleDeleteFile = async (id: number) => {
    // Optimistic removal: remove immediately from screen
    setFiles((prev) => prev.filter((f) => f.id !== id));
    try {
      const res = await fetch('/api/files.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'file', id }),
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Failed to delete file');
      }
    } catch (err) {
      console.error(err);
    } finally {
      loadFolderContent(currentFolderId);
    }
  };

  const handleDeleteFolder = async (id: number) => {
    // Optimistic removal: remove immediately from screen
    setFolders((prev) => prev.filter((f) => f.id !== id));
    try {
      const res = await fetch('/api/files.php?action=delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'folder', id }),
      });
      const data = await res.json();
      if (!data.success) {
        alert(data.error || 'Failed to delete folder');
      }
    } catch (err) {
      console.error(err);
    } finally {
      loadFolderContent(currentFolderId);
    }
  };

  const handleRenameFile = async (id: number, currentName: string) => {
    const newName = prompt('Enter new file name:', currentName);
    if (newName && newName.trim() && newName !== currentName) {
      await fetch('/api/files.php?action=rename', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type: 'file', id, new_name: newName.trim() }),
      });
      loadFolderContent(currentFolderId);
    }
  };

  const handleCopyFile = async (id: number) => {
    await fetch('/api/files.php?action=copy', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ file_id: id }),
    });
    loadFolderContent(currentFolderId);
  };

  const handleShareFile = (file: FileItem) => {
    const shareUrl = `${window.location.origin}/share.php?token=${file.share_token}`;
    navigator.clipboard.writeText(shareUrl);
    alert(`Public share link copied to clipboard:\n${shareUrl}`);
  };

  const handleLogout = async () => {
    await fetch('/api/auth.php?action=logout');
    setUser(null);
    setIsAuthOpen(true);
  };

  return (
    <div className="min-h-screen bg-slate-50 flex flex-col font-sans text-slate-900">
      {/* NAVBAR */}
      <header className="bg-white border-b border-slate-200 px-4 sm:px-6 py-3.5 flex items-center justify-between sticky top-0 z-40 shadow-2xs">
        <div className="flex items-center space-x-3">
          <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-blue-500/25">
            <Cloud className="w-5 h-5" />
          </div>
          <div>
            <span className="font-bold text-base text-slate-800 tracking-tight">CloudDrive</span>
            <span className="text-[10px] uppercase font-bold tracking-wider px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 ml-1.5">
              React + TS
            </span>
          </div>
        </div>

        {/* Search */}
        <div className="hidden md:flex items-center flex-1 max-w-md mx-8">
          <div className="relative w-full">
            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
            <input
              type="text"
              placeholder="Search files & folders..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2 bg-slate-100 hover:bg-slate-200/70 focus:bg-white border border-transparent focus:border-blue-500 rounded-xl text-xs transition outline-none"
            />
          </div>
        </div>

        {/* Actions & User */}
        <div className="flex items-center space-x-3">
          {user?.role === 'admin' && (
            <button
              onClick={() => setIsAdminOpen(true)}
              className="px-3.5 py-2 bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold rounded-xl border border-amber-200 flex items-center space-x-1.5 transition shadow-2xs"
            >
              <Shield className="w-3.5 h-3.5" />
              <span>Admin Panel</span>
            </button>
          )}

          {user && (
            <div className="flex items-center space-x-2 pl-3 border-l border-slate-200">
              <div className="w-8 h-8 rounded-full bg-blue-600 text-white font-bold text-xs flex items-center justify-center uppercase">
                {user.username[0]}
              </div>
              <div className="hidden sm:block text-left text-xs">
                <div className="font-bold text-slate-800">{user.username}</div>
                <div className="text-[10px] text-slate-400 truncate max-w-[100px]">{user.email}</div>
              </div>
              <button
                onClick={handleLogout}
                className="text-slate-400 hover:text-red-500 p-1.5 rounded-lg transition"
                title="Logout"
              >
                <LogOut className="w-4 h-4" />
              </button>
            </div>
          )}
        </div>
      </header>

      {/* MAIN BODY */}
      <div className="flex-1 flex overflow-hidden">
        {/* Sidebar */}
        <aside className="w-64 bg-white border-r border-slate-200 p-5 hidden lg:flex flex-col justify-between shrink-0">
          <div className="space-y-6">
            <div className="space-y-2">
              <button
                onClick={() => {
                  const input = document.createElement('input');
                  input.type = 'file';
                  input.multiple = true;
                  input.onchange = (e: any) => {
                    if (e.target.files) handleUploadFiles(Array.from(e.target.files));
                  };
                  input.click();
                }}
                className="w-full py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs rounded-xl shadow-md shadow-blue-500/20 transition flex items-center justify-center space-x-2"
              >
                <Upload className="w-4 h-4" />
                <span>Upload Files</span>
              </button>
              <button
                onClick={() => {
                  const name = prompt('Folder name:');
                  if (name && name.trim()) handleCreateFolder(name.trim());
                }}
                className="w-full py-2 px-4 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold text-xs rounded-xl transition flex items-center justify-center space-x-2"
              >
                <FolderPlus className="w-4 h-4 text-amber-500" />
                <span>New Folder</span>
              </button>
            </div>

            <nav className="space-y-1 text-xs font-semibold">
              <button
                onClick={() => loadFolderContent(null)}
                className="w-full flex items-center space-x-3 px-3 py-2.5 text-blue-600 bg-blue-50 rounded-xl transition"
              >
                <HardDrive className="w-4 h-4" />
                <span>My Drive</span>
              </button>
              <button className="w-full flex items-center space-x-3 px-3 py-2.5 text-slate-600 hover:bg-slate-100 rounded-xl transition">
                <Clock className="w-4 h-4 text-slate-400" />
                <span>Recent</span>
              </button>
              <button className="w-full flex items-center space-x-3 px-3 py-2.5 text-slate-600 hover:bg-slate-100 rounded-xl transition">
                <Share2 className="w-4 h-4 text-slate-400" />
                <span>Shared Links</span>
              </button>
            </nav>
          </div>

          <div className="p-4 bg-slate-50 border border-slate-200/80 rounded-2xl text-xs space-y-1">
            <div className="flex justify-between font-semibold text-slate-600">
              <span>Cloud Storage</span>
              <span className="text-slate-400">
                {(totalUserBytes / (1024 * 1024)).toFixed(1)} MB used
              </span>
            </div>
            <p className="text-[10px] text-slate-400">Multi-Account Google 13 GB Engine</p>
          </div>
        </aside>

        {/* Content View */}
        <main className="flex-1 overflow-y-auto p-4 sm:p-8 space-y-8">
          {/* BASKETBALL DROPZONE */}
          <BasketballDropzone
            uploadedCount={uploadedCount}
            onFilesSelected={handleUploadFiles}
            recentUpload={recentUpload}
          />

          {/* FILE EXPLORER */}
          <FileExplorer
            folders={folders}
            files={files}
            breadcrumbs={breadcrumbs}
            onOpenFolder={(id) => loadFolderContent(id)}
            onCreateFolder={handleCreateFolder}
            onOpenFile={(file) => setPreviewFile(file)}
            onDeleteFile={handleDeleteFile}
            onDeleteFolder={handleDeleteFolder}
            onRenameFile={handleRenameFile}
            onCopyFile={handleCopyFile}
            onShareFile={handleShareFile}
            searchQuery={searchQuery}
          />
        </main>
      </div>

      {/* MODALS */}
      {previewFile && (
        <PreviewModal file={previewFile} onClose={() => setPreviewFile(null)} />
      )}
      {isAdminOpen && (
        <AdminPanelModal onClose={() => setIsAdminOpen(false)} />
      )}
      {isAuthOpen && (
        <AuthModal
          onSuccess={(u) => {
            setUser(u);
            setIsAuthOpen(false);
            loadFolderContent(null);
          }}
        />
      )}
    </div>
  );
};

export default App;
