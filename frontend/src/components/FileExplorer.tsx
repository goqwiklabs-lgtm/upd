import React, { useState } from 'react';
import {
  Folder,
  FileVideo,
  FileImage,
  FileAudio,
  FileText,
  FileCode,
  FileArchive,
  File as FileGeneric,
  MoreVertical,
  FolderPlus,
  Globe,
  ChevronRight,
  Home,
  Trash2,
  Copy,
  PenSquare,
  Share2,
  Download,
  Eye,
} from 'lucide-react';
import type { FileItem, FolderItem, BreadcrumbItem } from '../types';

interface Props {
  folders: FolderItem[];
  files: FileItem[];
  breadcrumbs: BreadcrumbItem[];
  onOpenFolder: (folderId: number | null) => void;
  onCreateFolder: (name: string) => void;
  onOpenFile: (file: FileItem) => void;
  onDeleteFile: (fileId: number) => void;
  onDeleteFolder: (folderId: number) => void;
  onRenameFile: (fileId: number, currentName: string) => void;
  onCopyFile: (fileId: number) => void;
  onShareFile: (file: FileItem) => void;
  searchQuery: string;
}

export const FileExplorer: React.FC<Props> = ({
  folders,
  files,
  breadcrumbs,
  onOpenFolder,
  onCreateFolder,
  onOpenFile,
  onDeleteFile,
  onDeleteFolder,
  onRenameFile,
  onCopyFile,
  onShareFile,
  searchQuery,
}) => {
  const [activeMenu, setActiveMenu] = useState<{ type: 'file' | 'folder'; id: number } | null>(null);

  const getFileIcon = (fileName: string, mime: string) => {
    const ext = fileName.split('.').pop()?.toLowerCase() || '';
    if (['mp4', 'mkv', 'webm', 'mov'].includes(ext) || mime.startsWith('video/')) {
      return { icon: FileVideo, color: 'text-purple-600', bg: 'bg-purple-50' };
    }
    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext) || mime.startsWith('image/')) {
      return { icon: FileImage, color: 'text-emerald-600', bg: 'bg-emerald-50' };
    }
    if (['mp3', 'wav', 'ogg'].includes(ext) || mime.startsWith('audio/')) {
      return { icon: FileAudio, color: 'text-amber-600', bg: 'bg-amber-50' };
    }
    if (ext === 'pdf' || mime === 'application/pdf') {
      return { icon: FileText, color: 'text-rose-600', bg: 'bg-rose-50' };
    }
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
      return { icon: FileArchive, color: 'text-orange-600', bg: 'bg-orange-50' };
    }
    if (['js', 'ts', 'tsx', 'jsx', 'py', 'php', 'html', 'css', 'json'].includes(ext)) {
      return { icon: FileCode, color: 'text-blue-600', bg: 'bg-blue-50' };
    }
    return { icon: FileGeneric, color: 'text-slate-600', bg: 'bg-slate-50' };
  };

  const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  };

  const filteredFolders = folders.filter((f) =>
    f.name.toLowerCase().includes(searchQuery.toLowerCase())
  );
  const filteredFiles = files.filter((f) =>
    f.name.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <div className="w-full max-w-6xl mx-auto space-y-6">
      {/* Action Bar & Breadcrumbs */}
      <div className="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-slate-200">
        <nav className="flex items-center space-x-1.5 text-sm text-slate-500 overflow-x-auto">
          <button
            onClick={() => onOpenFolder(null)}
            className="flex items-center hover:text-blue-600 transition font-medium"
          >
            <Home className="w-4 h-4 mr-1 text-slate-400" />
            <span>My Drive</span>
          </button>
          {breadcrumbs.map((b, idx) => (
            <React.Fragment key={b.id}>
              <ChevronRight className="w-4 h-4 text-slate-300 shrink-0" />
              <button
                onClick={() => onOpenFolder(b.id)}
                className={`transition truncate max-w-[120px] ${
                  idx === breadcrumbs.length - 1
                    ? 'font-bold text-slate-800'
                    : 'text-slate-500 hover:text-blue-600'
                }`}
              >
                {b.name}
              </button>
            </React.Fragment>
          ))}
        </nav>

        <div className="flex items-center space-x-2">
          <button
            onClick={() => {
              const name = prompt('Enter new folder name:');
              if (name && name.trim()) onCreateFolder(name.trim());
            }}
            className="px-3.5 py-1.5 bg-white hover:bg-slate-50 border border-slate-200 text-slate-700 text-xs font-semibold rounded-xl transition shadow-2xs inline-flex items-center space-x-1.5"
          >
            <FolderPlus className="w-3.5 h-3.5 text-amber-500" />
            <span>New folder</span>
          </button>
        </div>
      </div>

      {filteredFolders.length === 0 && filteredFiles.length === 0 ? (
        <div className="py-20 text-center text-slate-400">
          <div className="w-16 h-16 mx-auto mb-3 rounded-2xl bg-slate-100 flex items-center justify-center text-slate-400">
            <Folder className="w-8 h-8" />
          </div>
          <h3 className="font-semibold text-slate-700">No items found</h3>
          <p className="text-xs text-slate-400 mt-1">
            Drop files above or take the shot to start uploading!
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          {/* Folders Grid */}
          {filteredFolders.length > 0 && (
            <div>
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">
                Folders
              </h3>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
                {filteredFolders.map((folder) => (
                  <div
                    key={folder.id}
                    onDoubleClick={() => onOpenFolder(folder.id)}
                    className="group bg-white hover:bg-blue-50/50 border border-slate-200/90 hover:border-blue-300 rounded-xl p-3 flex items-center justify-between cursor-pointer transition shadow-xs"
                  >
                    <div className="flex items-center space-x-2.5 truncate">
                      <Folder className="w-5 h-5 text-amber-400 fill-amber-400 shrink-0" />
                      <span className="text-sm font-medium text-slate-700 group-hover:text-blue-600 truncate">
                        {folder.name}
                      </span>
                    </div>
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        if (confirm(`Delete folder "${folder.name}" and all its contents?`)) {
                          onDeleteFolder(folder.id);
                        }
                      }}
                      className="text-slate-300 hover:text-red-500 p-1 opacity-0 group-hover:opacity-100 transition"
                      title="Delete folder"
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                    </button>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Files Grid */}
          {filteredFiles.length > 0 && (
            <div>
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">
                Files
              </h3>
              <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                {filteredFiles.map((file) => {
                  const iconInfo = getFileIcon(file.name, file.mime_type);
                  const Icon = iconInfo.icon;
                  return (
                    <div
                      key={file.id}
                      onDoubleClick={() => onOpenFile(file)}
                      className="group bg-white hover:bg-slate-50/80 border border-slate-200 rounded-2xl p-4 flex flex-col justify-between cursor-pointer transition shadow-xs hover:shadow-md relative"
                    >
                      <div className="flex items-start justify-between mb-3">
                        <div
                          className={`w-10 h-10 rounded-xl ${iconInfo.bg} ${iconInfo.color} flex items-center justify-center`}
                        >
                          <Icon className="w-5 h-5" />
                        </div>

                        {/* 3-Dot Menu */}
                        <div className="relative">
                          <button
                            onClick={(e) => {
                              e.stopPropagation();
                              setActiveMenu(
                                activeMenu?.id === file.id ? null : { type: 'file', id: file.id }
                              );
                            }}
                            className="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition"
                          >
                            <MoreVertical className="w-4 h-4" />
                          </button>

                          {activeMenu?.id === file.id && (
                            <div
                              onClick={(e) => e.stopPropagation()}
                              className="absolute right-0 top-6 z-30 w-44 bg-white border border-slate-200 rounded-xl shadow-xl p-1.5 text-xs text-slate-700 space-y-0.5"
                            >
                              <button
                                onClick={() => {
                                  setActiveMenu(null);
                                  onOpenFile(file);
                                }}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-slate-100 rounded-lg transition"
                              >
                                <Eye className="w-3.5 h-3.5 text-blue-500" />
                                <span>Preview</span>
                              </button>
                              <button
                                onClick={() => {
                                  setActiveMenu(null);
                                  onShareFile(file);
                                }}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-slate-100 rounded-lg transition"
                              >
                                <Share2 className="w-3.5 h-3.5 text-emerald-500" />
                                <span>Share link</span>
                              </button>
                              <button
                                onClick={() => {
                                  setActiveMenu(null);
                                  onRenameFile(file.id, file.name);
                                }}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-slate-100 rounded-lg transition"
                              >
                                <PenSquare className="w-3.5 h-3.5 text-slate-500" />
                                <span>Rename</span>
                              </button>
                              <button
                                onClick={() => {
                                  setActiveMenu(null);
                                  onCopyFile(file.id);
                                }}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-slate-100 rounded-lg transition"
                              >
                                <Copy className="w-3.5 h-3.5 text-indigo-500" />
                                <span>Make a copy</span>
                              </button>
                              <a
                                href={`stream.php?id=${file.id}&download=1`}
                                download={file.name}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-slate-100 rounded-lg transition"
                              >
                                <Download className="w-3.5 h-3.5 text-amber-500" />
                                <span>Download</span>
                              </a>
                              <div className="border-t border-slate-100 my-1"></div>
                              <button
                                onClick={() => {
                                  setActiveMenu(null);
                                  if (confirm(`Delete "${file.name}"?`)) onDeleteFile(file.id);
                                }}
                                className="w-full flex items-center space-x-2 px-2.5 py-1.5 hover:bg-red-50 text-red-600 rounded-lg transition"
                              >
                                <Trash2 className="w-3.5 h-3.5 text-red-500" />
                                <span>Delete</span>
                              </button>
                            </div>
                          )}
                        </div>
                      </div>

                      <div>
                        <h4
                          className="text-sm font-semibold text-slate-800 truncate mb-1"
                          title={file.name}
                        >
                          {file.name}
                        </h4>
                        <div className="flex items-center justify-between text-xs text-slate-400">
                          <span>{formatBytes(file.size_bytes)}</span>
                          {file.is_public === 1 && (
                            <span className="text-emerald-600 font-semibold flex items-center text-[10px]">
                              <Globe className="w-2.5 h-2.5 mr-1" /> Public
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
};
