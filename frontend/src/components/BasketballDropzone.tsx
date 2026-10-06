import React, { useRef, useState } from 'react';
import { UploadCloud, FileText, CheckCircle2 } from 'lucide-react';
import type { UploadItem } from '../types';

interface Props {
  uploadedCount: number;
  onFilesSelected: (files: File[]) => void;
  recentUpload: UploadItem | null;
}

export const BasketballDropzone: React.FC<Props> = ({
  uploadedCount,
  onFilesSelected,
  recentUpload,
}) => {
  const [isDragOver, setIsDragOver] = useState(false);
  const dropzoneRef = useRef<HTMLDivElement>(null);
  const netRef = useRef<HTMLDivElement>(null);
  const scoreRef = useRef<HTMLDivElement>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Parabolic shot animation triggered when file is dropped or picked
  const triggerShotAnimation = (fileName: string) => {
    if (!dropzoneRef.current) return;
    const container = dropzoneRef.current;
    const rect = container.getBoundingClientRect();

    const startX = 60;
    const startY = rect.height - 80;
    const targetX = rect.width / 2;
    const targetY = 125;
    const arcHeight = 150;

    // Dotted trajectory dots
    const dotsContainer = document.createElement('div');
    dotsContainer.className = 'absolute inset-0 pointer-events-none z-20';
    container.appendChild(dotsContainer);

    const totalDots = 10;
    const dots: HTMLDivElement[] = [];
    for (let i = 1; i <= totalDots; i++) {
      const t = i / (totalDots + 1);
      const dotX = startX + (targetX - startX) * t;
      const dotY = startY + (targetY - startY) * t - Math.sin(t * Math.PI) * arcHeight;

      const dot = document.createElement('div');
      dot.className = 'absolute w-2 h-2 rounded-full bg-rose-400/90 transition-all duration-300';
      dot.style.left = `${dotX}px`;
      dot.style.top = `${dotY}px`;
      dot.style.opacity = '0';
      dot.style.transform = 'scale(0.5)';
      dotsContainer.appendChild(dot);
      dots.push(dot);
    }

    // Flight file icon
    const icon = document.createElement('div');
    icon.className = 'flight-icon flex flex-col items-center justify-center p-2 bg-white border border-slate-200 rounded-xl shadow-2xl';
    icon.style.width = '48px';
    icon.style.height = '56px';
    icon.innerHTML = `
      <svg class="w-6 h-6 text-red-500 mb-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <polyline points="14 2 14 8 20 8"></polyline>
      </svg>
      <span class="text-[8px] font-bold text-slate-700 truncate max-w-[38px]">${fileName.slice(0, 8)}</span>
    `;
    icon.style.left = `${startX - 24}px`;
    icon.style.top = `${startY - 28}px`;
    container.appendChild(icon);

    const duration = 850;
    const startTime = performance.now();

    function step(currentTime: number) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);

      dots.forEach((dot, idx) => {
        const threshold = (idx + 1) / (totalDots + 1);
        if (progress >= threshold * 0.75) {
          dot.style.opacity = '0.95';
          dot.style.transform = 'scale(1)';
        }
      });

      const currentX = startX + (targetX - startX) * progress;
      const currentY = startY + (targetY - startY) * progress - Math.sin(progress * Math.PI) * arcHeight;

      icon.style.left = `${currentX - 24}px`;
      icon.style.top = `${currentY - 28}px`;
      icon.style.transform = `scale(${1 - progress * 0.25}) rotate(${progress * 22}deg)`;

      if (progress < 1) {
        requestAnimationFrame(step);
      } else {
        icon.remove();
        // Swish net!
        if (netRef.current) {
          netRef.current.classList.remove('net-swish');
          void netRef.current.offsetWidth;
          netRef.current.classList.add('net-swish');
        }
        // Score pop!
        if (scoreRef.current) {
          scoreRef.current.classList.remove('active');
          void scoreRef.current.offsetWidth;
          scoreRef.current.classList.add('active');
        }
        // Cleanup dots
        setTimeout(() => {
          dotsContainer.style.transition = 'opacity 0.4s';
          dotsContainer.style.opacity = '0';
          setTimeout(() => dotsContainer.remove(), 400);
        }, 350);
      }
    }

    requestAnimationFrame(step);
  };

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
      const files = Array.from(e.dataTransfer.files);
      triggerShotAnimation(files[0].name);
      onFilesSelected(files);
    }
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files.length > 0) {
      const files = Array.from(e.target.files);
      triggerShotAnimation(files[0].name);
      onFilesSelected(files);
      e.target.value = '';
    }
  };

  return (
    <div className="w-full max-w-xl mx-auto space-y-4">
      {/* Header bar */}
      <div className="flex items-center justify-between px-1">
        <div>
          <h1 className="text-2xl font-bold text-slate-800 tracking-tight">Upload files</h1>
          <p className="text-sm text-slate-400">Drag and drop, or take the shot.</p>
        </div>
        <div className="flex items-center space-x-2">
          <span className="px-3 py-1 bg-slate-200/80 text-slate-700 text-xs font-bold rounded-full flex items-center space-x-1.5 shadow-xs">
            <span>Uploaded {uploadedCount}</span>
          </span>
        </div>
      </div>

      {/* Interactive Drop Box with Hoop */}
      <div
        ref={dropzoneRef}
        onDragOver={(e) => { e.preventDefault(); setIsDragOver(true); }}
        onDragLeave={(e) => { e.preventDefault(); setIsDragOver(false); }}
        onDrop={handleDrop}
        onClick={() => fileInputRef.current?.click()}
        className={`relative bg-white border-2 border-dashed rounded-3xl p-6 sm:p-8 text-center transition-all cursor-pointer shadow-xs overflow-hidden ${
          isDragOver ? 'border-blue-500 bg-blue-50/50 scale-[1.01]' : 'border-slate-300 hover:border-blue-400'
        }`}
      >
        <div className="flex flex-col items-center justify-center space-y-3">
          <div className="flex items-center space-x-2 text-slate-600 text-sm font-medium">
            <UploadCloud className="w-5 h-5 text-blue-500" />
            <span>Drop files here or take the shot</span>
          </div>

          {/* Basketball Hoop SVG Illustration */}
          <div className="hoop-container">
            <div className="hoop-backboard">
              <div className="hoop-inner-box"></div>
            </div>
            <div className="hoop-rim"></div>
            <div ref={netRef} className="hoop-net"></div>
            <div ref={scoreRef} className="score-pop">+1</div>
          </div>

          <p className="text-xs text-slate-400">
            Supports single or batch upload up to 2GB+ per file directly to Google Drive
          </p>
        </div>

        <input
          ref={fileInputRef}
          type="file"
          multiple
          className="hidden"
          onChange={handleInputChange}
        />
      </div>

      {/* In-view Upload Progress Card (Matching the video) */}
      {recentUpload && (
        <div className="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex items-center justify-between space-x-4 animate-in fade-in duration-300">
          <div className="flex items-center space-x-3 truncate">
            <div className="w-10 h-10 rounded-xl bg-red-50 text-red-500 flex items-center justify-center shrink-0">
              <FileText className="w-5 h-5" />
            </div>
            <div className="truncate">
              <h4 className="text-sm font-semibold text-slate-800 truncate" title={recentUpload.name}>
                {recentUpload.name}
              </h4>
              <span className="text-xs text-slate-400">
                {(recentUpload.size / (1024 * 1024)).toFixed(1)} MB
              </span>
            </div>
          </div>

          <div className="flex items-center space-x-3 shrink-0">
            {recentUpload.status === 'completed' ? (
              <span className="px-3 py-1 bg-emerald-50 text-emerald-600 border border-emerald-200 text-xs font-bold rounded-full flex items-center space-x-1">
                <span>Uploaded</span>
                <CheckCircle2 className="w-3.5 h-3.5 ml-1" />
              </span>
            ) : recentUpload.status === 'error' ? (
              <span className="px-3 py-1 bg-red-50 text-red-600 border border-red-200 text-xs font-bold rounded-full">
                Error
              </span>
            ) : (
              <div className="flex items-center space-x-2">
                <span className="text-xs font-semibold text-blue-600">Uploading...</span>
                <div className="w-24 bg-slate-100 rounded-full h-1.5 overflow-hidden">
                  <div
                    className="bg-blue-600 h-1.5 rounded-full transition-all duration-200"
                    style={{ width: `${recentUpload.progress}%` }}
                  ></div>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};
