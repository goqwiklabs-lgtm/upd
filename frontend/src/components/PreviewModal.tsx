import React, { useEffect, useState } from 'react';
import { X, Download, FileText, ZoomIn, ZoomOut, RotateCw } from 'lucide-react';
import type { FileItem } from '../types';

interface Props {
  file: FileItem | null;
  onClose: () => void;
}

export const PreviewModal: React.FC<Props> = ({ file, onClose }) => {
  const [zoom, setZoom] = useState(1);
  const [rotation, setRotation] = useState(0);
  const [textContent, setTextContent] = useState<string | null>(null);

  useEffect(() => {
    setZoom(1);
    setRotation(0);
    setTextContent(null);

    if (file && (file.mime_type.startsWith('text/') || file.name.endsWith('.json') || file.name.endsWith('.js') || file.name.endsWith('.py') || file.name.endsWith('.php'))) {
      fetch(`stream.php?id=${file.id}`)
        .then((res) => res.text())
        .then((text) => setTextContent(text))
        .catch(() => setTextContent('Error loading file preview.'));
    }
  }, [file]);

  if (!file) return null;

  const streamUrl = `stream.php?id=${file.id}`;
  const isVideo = file.mime_type.startsWith('video/') || ['mp4', 'mkv', 'webm', 'mov'].includes(file.name.split('.').pop() || '');
  const isImage = file.mime_type.startsWith('image/');
  const isPdf = file.mime_type === 'application/pdf' || file.name.endsWith('.pdf');
  const isAudio = file.mime_type.startsWith('audio/');

  return (
    <div className="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex flex-col justify-between p-4 sm:p-6 z-50 animate-in fade-in duration-200">
      {/* Top Header */}
      <div className="flex items-center justify-between max-w-6xl w-full mx-auto pb-4 border-b border-slate-800">
        <div className="flex items-center space-x-3 truncate">
          <div className="w-8 h-8 rounded-lg bg-blue-600/20 text-blue-400 flex items-center justify-center shrink-0">
            <FileText className="w-4 h-4" />
          </div>
          <h3 className="text-base font-bold text-white truncate max-w-md" title={file.name}>
            {file.name}
          </h3>
        </div>

        <div className="flex items-center space-x-2">
          {isImage && (
            <div className="flex items-center space-x-1 mr-2 bg-slate-800/80 rounded-xl p-1 text-slate-300">
              <button
                onClick={() => setZoom((z) => Math.max(0.5, z - 0.25))}
                className="p-1.5 hover:text-white rounded-lg transition"
                title="Zoom Out"
              >
                <ZoomOut className="w-4 h-4" />
              </button>
              <span className="text-xs px-1 font-mono">{Math.round(zoom * 100)}%</span>
              <button
                onClick={() => setZoom((z) => Math.min(3, z + 0.25))}
                className="p-1.5 hover:text-white rounded-lg transition"
                title="Zoom In"
              >
                <ZoomIn className="w-4 h-4" />
              </button>
              <button
                onClick={() => setRotation((r) => (r + 90) % 360)}
                className="p-1.5 hover:text-white rounded-lg transition"
                title="Rotate"
              >
                <RotateCw className="w-4 h-4" />
              </button>
            </div>
          )}

          <a
            href={`${streamUrl}&download=1`}
            download={file.name}
            className="px-3.5 py-1.5 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition flex items-center space-x-1.5 shadow-md shadow-blue-500/20"
          >
            <Download className="w-3.5 h-3.5" />
            <span>Download</span>
          </a>

          <button
            onClick={onClose}
            className="w-8 h-8 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white flex items-center justify-center transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
      </div>

      {/* Main Preview Workspace */}
      <div className="flex-1 flex items-center justify-center max-w-6xl w-full mx-auto overflow-hidden p-2">
        {isVideo ? (
          <div className="w-full max-h-[75vh] flex items-center justify-center bg-black rounded-2xl overflow-hidden shadow-2xl">
            <video
              controls
              autoPlay
              className="w-full max-h-[75vh] object-contain rounded-2xl"
              preload="metadata"
            >
              <source src={streamUrl} type={file.mime_type} />
              Your browser does not support video playback.
            </video>
          </div>
        ) : isImage ? (
          <div className="overflow-auto max-h-[75vh] flex items-center justify-center">
            <img
              src={streamUrl}
              alt={file.name}
              style={{
                transform: `scale(${zoom}) rotate(${rotation}deg)`,
                transition: 'transform 0.15s ease',
              }}
              className="max-h-[72vh] max-w-full object-contain rounded-xl shadow-2xl select-none"
            />
          </div>
        ) : isPdf ? (
          <iframe
            src={streamUrl}
            title={file.name}
            className="w-full h-[75vh] rounded-2xl border border-slate-800 shadow-2xl"
          />
        ) : isAudio ? (
          <div className="p-8 text-center bg-slate-900 border border-slate-800 rounded-3xl max-w-md w-full shadow-2xl space-y-4">
            <div className="w-20 h-20 mx-auto rounded-full bg-blue-500/10 text-blue-400 flex items-center justify-center text-3xl">
              🎵
            </div>
            <h4 className="text-white font-bold truncate">{file.name}</h4>
            <audio controls autoPlay className="w-full">
              <source src={streamUrl} type={file.mime_type} />
            </audio>
          </div>
        ) : textContent !== null ? (
          <div className="w-full max-h-[75vh] bg-slate-900 border border-slate-800 rounded-2xl p-6 overflow-auto text-slate-100 font-mono text-xs whitespace-pre-wrap shadow-2xl">
            {textContent}
          </div>
        ) : (
          <div className="text-center p-12 text-slate-400 space-y-3">
            <FileText className="w-16 h-16 mx-auto text-slate-600 mb-2" />
            <p className="text-slate-300 font-semibold">No direct preview available</p>
            <p className="text-xs text-slate-500">Download the file to view it on your device.</p>
          </div>
        )}
      </div>

      <div className="text-center text-xs text-slate-500 pt-2 border-t border-slate-800">
        Streaming securely via CloudDrive Resumable Gateway
      </div>
    </div>
  );
};
