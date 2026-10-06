import React, { useEffect, useState, useRef } from 'react';
import { X, Download, FileText, ZoomIn, ZoomOut, RotateCw, Copy, Check, Eye, Code, Settings, Sliders } from 'lucide-react';
import type { FileItem } from '../types';

interface Props {
  file: FileItem | null;
  onClose: () => void;
}

export const PreviewModal: React.FC<Props> = ({ file, onClose }) => {
  const [zoom, setZoom] = useState(1);
  const [rotation, setRotation] = useState(0);
  const [textContent, setTextContent] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [htmlView, setHtmlView] = useState<'preview' | 'code'>('preview');

  const [qualities, setQualities] = useState<{ value: string; label: string; ready: boolean }[]>([]);
  const [selectedQuality, setSelectedQuality] = useState('auto');
  const [showQualityMenu, setShowQualityMenu] = useState(false);
  const videoRef = useRef<HTMLVideoElement | null>(null);

  const ext = file?.name.split('.').pop()?.toLowerCase() || '';
  const videoExts = ['mp4', 'mkv', 'webm', 'mov', 'avi', 'flv', 'm4v'];
  const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
  const audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac'];
  const codeExts  = ['txt', 'json', 'html', 'htm', 'css', 'js', 'ts', 'jsx', 'tsx', 'py', 'php', 'md', 'csv', 'xml', 'sql', 'sh', 'yaml', 'yml', 'c', 'cpp', 'java', 'log'];

  const isVideo = (file?.mime_type.startsWith('video/') || videoExts.includes(ext)) ?? false;
  const isImage = (file?.mime_type.startsWith('image/') || imageExts.includes(ext)) ?? false;
  const isPdf = (file?.mime_type === 'application/pdf' || ext === 'pdf') ?? false;
  const isAudio = (file?.mime_type.startsWith('audio/') || audioExts.includes(ext)) ?? false;
  const isCode = (file?.mime_type.startsWith('text/') || codeExts.includes(ext)) ?? false;
  const isHtml = ['html', 'htm'].includes(ext);

  useEffect(() => {
    setZoom(1);
    setRotation(0);
    setTextContent(null);
    setCopied(false);
    setHtmlView('preview');
    setSelectedQuality('auto');
    setShowQualityMenu(false);

    if (file && isVideo) {
      fetch(`api/files.php?action=video_qualities&file_id=${file.id}`)
        .then((res) => res.json())
        .then((data) => {
          if (data.success && data.qualities) {
            setQualities(data.qualities);
          }
        })
        .catch(() => {});
    }

    if (file && isCode) {
      fetch(`stream.php?id=${file.id}`)
        .then((res) => res.text())
        .then((text) => setTextContent(text))
        .catch(() => setTextContent('Error loading file preview.'));
    }
  }, [file, isCode, isVideo]);

  if (!file) return null;

  const streamUrl = `stream.php?id=${file.id}`;

  const copyToClipboard = () => {
    if (textContent) {
      navigator.clipboard.writeText(textContent);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    }
  };

  const handleQualityChange = (q: string) => {
    setSelectedQuality(q);
    setShowQualityMenu(false);
    if (!videoRef.current || !file) return;

    const video = videoRef.current;
    const currentTime = video.currentTime;
    const wasPlaying = !video.paused;

    video.src = `stream.php?id=${file.id}&quality=${encodeURIComponent(q)}`;
    video.load();

    video.onloadedmetadata = () => {
      video.currentTime = currentTime;
      if (wasPlaying) {
        video.play();
      }
    };
  };

  return (
    <div className="fixed inset-0 bg-slate-950/85 backdrop-blur-md flex flex-col justify-between p-4 sm:p-6 z-50 animate-in fade-in duration-200">
      {/* Top Header */}
      <div className="flex items-center justify-between max-w-6xl w-full mx-auto pb-4 border-b border-slate-800 gap-3">
        <div className="flex items-center space-x-3 truncate">
          <div className="w-9 h-9 rounded-xl bg-blue-600/20 text-blue-400 flex items-center justify-center shrink-0">
            <FileText className="w-5 h-5" />
          </div>
          <div className="truncate">
            <h3 className="text-base font-bold text-white truncate max-w-md" title={file.name}>
              {file.name}
            </h3>
            <span className="text-[11px] text-slate-400">
              {(file.size_bytes / (1024 * 1024)).toFixed(1)} MB
            </span>
          </div>
        </div>

        <div className="flex items-center space-x-2">
          {/* HTML Toggle View */}
          {isHtml && (
            <div className="flex items-center bg-slate-800 rounded-xl p-1 text-xs mr-2">
              <button
                onClick={() => setHtmlView('preview')}
                className={`px-3 py-1 rounded-lg font-semibold flex items-center space-x-1.5 transition ${
                  htmlView === 'preview' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white'
                }`}
              >
                <Eye className="w-3.5 h-3.5" />
                <span>Web Preview</span>
              </button>
              <button
                onClick={() => setHtmlView('code')}
                className={`px-3 py-1 rounded-lg font-semibold flex items-center space-x-1.5 transition ${
                  htmlView === 'code' ? 'bg-blue-600 text-white' : 'text-slate-400 hover:text-white'
                }`}
              >
                <Code className="w-3.5 h-3.5" />
                <span>Source Code</span>
              </button>
            </div>
          )}

          {/* Image Zoom & Rotate Controls */}
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

          {/* Copy Code Button */}
          {isCode && !isHtml && textContent && (
            <button
              onClick={copyToClipboard}
              className="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold rounded-xl transition flex items-center space-x-1.5"
            >
              {copied ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
              <span>{copied ? 'Copied' : 'Copy'}</span>
            </button>
          )}

          <a
            href={`${streamUrl}&download=1`}
            download={file.name}
            className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold rounded-xl transition flex items-center space-x-1.5 shadow-md shadow-blue-500/20"
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
          <div className="w-full max-w-4xl flex flex-col items-center">
            <div className="relative w-full rounded-2xl overflow-hidden shadow-2xl bg-black group">
              <video
                ref={videoRef}
                controls
                autoPlay
                className="w-full max-h-[75vh] object-contain rounded-2xl bg-black"
                preload="auto"
              >
                <source src={`${streamUrl}&quality=${selectedQuality}`} type={file.mime_type} />
                Your browser does not support video playback.
              </video>

              {/* YouTube-style Quality Selector */}
              {qualities.length > 0 && (
                <div className="absolute top-4 right-4 z-20">
                  <div className="relative">
                    <button
                      type="button"
                      onClick={() => setShowQualityMenu(!showQualityMenu)}
                      className="px-3 py-1.5 bg-black/75 hover:bg-black/90 backdrop-blur-md text-white text-xs font-semibold rounded-xl border border-white/20 transition flex items-center space-x-1.5 shadow-lg"
                    >
                      <Settings className="w-3.5 h-3.5 text-amber-400" />
                      <span className="capitalize">
                        {qualities.find((q) => q.value === selectedQuality)?.label || selectedQuality}
                      </span>
                    </button>

                    {showQualityMenu && (
                      <div className="absolute right-0 mt-2 w-44 bg-slate-900/95 backdrop-blur-md border border-slate-700/80 rounded-xl shadow-2xl p-1.5 text-xs text-slate-200 z-30">
                        <div className="text-[10px] uppercase font-bold text-slate-400 px-2 py-1 border-b border-slate-800 flex items-center justify-between">
                          <span>Resolution</span>
                          <Sliders className="w-3 h-3 text-slate-400" />
                        </div>
                        <div className="mt-1 space-y-0.5 max-h-60 overflow-y-auto">
                          {qualities.map((q) => (
                            <button
                              key={q.value}
                              type="button"
                              onClick={() => handleQualityChange(q.value)}
                              className={`w-full text-left px-2.5 py-1.5 hover:bg-slate-800 rounded-lg flex items-center justify-between transition ${
                                selectedQuality === q.value
                                  ? 'text-blue-400 font-bold bg-blue-500/10'
                                  : 'text-slate-200'
                              }`}
                            >
                              <span>{q.label}</span>
                              {selectedQuality === q.value && <Check className="w-3.5 h-3.5 text-blue-400" />}
                            </button>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
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
        ) : isHtml && htmlView === 'preview' ? (
          <div className="w-full h-[75vh] bg-white rounded-2xl overflow-hidden shadow-2xl">
            <iframe src={streamUrl} title={file.name} className="w-full h-full border-0"></iframe>
          </div>
        ) : isCode && textContent !== null ? (
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
