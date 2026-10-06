import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  X,
  QrCode,
  Camera,
  Upload,
  Play,
  Pause,
  RotateCcw,
  SkipForward,
  SkipBack,
  Sliders,
  CheckCircle2,
  AlertTriangle,
  Download,
  ShieldCheck,
  RefreshCw,
  Zap,
  Eye,
  Radio,
  FileText,
  Layers,
} from 'lucide-react';
import QRCode from 'qrcode';
import jsQR from 'jsqr';
import confetti from 'canvas-confetti';
import {
  OpticalProtocol,
  OpticalReceiverSession,
  type PreparedTransfer,
} from '../utils/opticalTransfer';

interface QRFilesModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const QRFilesModal: React.FC<QRFilesModalProps> = ({ isOpen, onClose }) => {
  const [activeTab, setActiveTab] = useState<'sender' | 'receiver'>('sender');

  // ==========================================
  // SENDER STATE
  // ==========================================
  const [senderFile, setSenderFile] = useState<File | null>(null);
  const [preparedTransfer, setPreparedTransfer] = useState<PreparedTransfer | null>(null);
  const [isPreparing, setIsPreparing] = useState(false);
  const [targetFps, setTargetFps] = useState(24);
  const [chunkSize, setChunkSize] = useState(360);
  const [redundancyPercent, setRedundancyPercent] = useState(25);
  const [currentFrameIndex, setCurrentFrameIndex] = useState(0);
  const [isPlaying, setIsPlaying] = useState(true);
  const [actualSenderFps, setActualSenderFps] = useState(0);

  const senderCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const senderAnimFrameRef = useRef<number | null>(null);
  const lastRenderTimeRef = useRef<number>(0);
  const frameCountRef = useRef<number>(0);
  const lastFpsCalcTimeRef = useRef<number>(0);

  // ==========================================
  // RECEIVER STATE
  // ==========================================
  const [isCameraActive, setIsCameraActive] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [receiverFps, setReceiverFps] = useState(0);
  const [receiverSession, setReceiverSession] = useState<OpticalReceiverSession>(
    () => new OpticalReceiverSession()
  );
  const [, setReceiverUpdateTick] = useState(0);

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const scannerCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const mediaStreamRef = useRef<MediaStream | null>(null);
  const scannerLoopActiveRef = useRef<boolean>(false);
  const lastScanFpsTimeRef = useRef<number>(0);
  const scanFramesCountRef = useRef<number>(0);

  // Clean up when modal closes
  useEffect(() => {
    if (!isOpen) {
      stopCamera();
      setIsPlaying(false);
      if (senderAnimFrameRef.current) {
        cancelAnimationFrame(senderAnimFrameRef.current);
      }
    }
  }, [isOpen]);

  // ========================================================
  // SENDER LOGIC
  // ========================================================
  const handleFileSelect = async (file: File) => {
    setSenderFile(file);
    setIsPreparing(true);
    setCurrentFrameIndex(0);
    try {
      const prepared = await OpticalProtocol.prepareFile(file, chunkSize, redundancyPercent);
      setPreparedTransfer(prepared);
      setIsPlaying(true);
    } catch (err) {
      console.error('Failed to prepare file:', err);
      alert('Error compressing or packaging file for optical streaming.');
    } finally {
      setIsPreparing(false);
    }
  };

  // Re-encode if settings change
  useEffect(() => {
    if (senderFile && !isPreparing) {
      handleFileSelect(senderFile);
    }
  }, [chunkSize, redundancyPercent]);

  // Render QR Canvas for current frame
  const renderQrFrame = useCallback(
    async (frameIdx: number) => {
      if (!preparedTransfer || !senderCanvasRef.current) return;
      const packetStr = preparedTransfer.packets[frameIdx];
      if (!packetStr) return;

      try {
        await QRCode.toCanvas(senderCanvasRef.current, packetStr, {
          errorCorrectionLevel: 'M',
          margin: 1,
          scale: 8,
          color: {
            dark: '#000000',
            light: '#ffffff',
          },
        });
      } catch (err) {
        console.error('Error drawing QR:', err);
      }
    },
    [preparedTransfer]
  );

  // Animation Loop for Sender
  useEffect(() => {
    if (!preparedTransfer || preparedTransfer.packets.length === 0) return;

    let localFrameIdx = currentFrameIndex;
    const intervalMs = 1000 / targetFps;
    lastRenderTimeRef.current = performance.now();
    lastFpsCalcTimeRef.current = performance.now();
    frameCountRef.current = 0;

    const tick = (now: number) => {
      if (!isPlaying) {
        senderAnimFrameRef.current = requestAnimationFrame(tick);
        return;
      }

      const elapsed = now - lastRenderTimeRef.current;
      if (elapsed >= intervalMs) {
        lastRenderTimeRef.current = now - (elapsed % intervalMs);
        localFrameIdx = (localFrameIdx + 1) % preparedTransfer.packets.length;
        setCurrentFrameIndex(localFrameIdx);
        renderQrFrame(localFrameIdx);

        frameCountRef.current++;
        const fpsElapsed = now - lastFpsCalcTimeRef.current;
        if (fpsElapsed >= 1000) {
          setActualSenderFps(Math.round((frameCountRef.current * 1000) / fpsElapsed));
          frameCountRef.current = 0;
          lastFpsCalcTimeRef.current = now;
        }
      }

      senderAnimFrameRef.current = requestAnimationFrame(tick);
    };

    senderAnimFrameRef.current = requestAnimationFrame(tick);

    return () => {
      if (senderAnimFrameRef.current) {
        cancelAnimationFrame(senderAnimFrameRef.current);
      }
    };
  }, [preparedTransfer, targetFps, isPlaying, renderQrFrame]);

  // Initial draw when frame paused or index stepped manually
  useEffect(() => {
    if (!isPlaying && preparedTransfer) {
      renderQrFrame(currentFrameIndex);
    }
  }, [currentFrameIndex, isPlaying, preparedTransfer, renderQrFrame]);

  // ========================================================
  // RECEIVER LOGIC
  // ========================================================
  const startCamera = async () => {
    setCameraError(null);
    try {
      if (mediaStreamRef.current) {
        mediaStreamRef.current.getTracks().forEach((t) => t.stop());
      }

      const constraints: MediaStreamConstraints = {
        video: {
          facingMode: facingMode,
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
        audio: false,
      };

      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      mediaStreamRef.current = stream;

      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }

      setIsCameraActive(true);
      scannerLoopActiveRef.current = true;
      runScannerLoop();
    } catch (err: unknown) {
      console.error('Camera access error:', err);
      const msg = err instanceof Error ? err.message : 'Unknown camera error';
      setCameraError(`Camera permission denied or camera unavailable (${msg})`);
      setIsCameraActive(false);
    }
  };

  const stopCamera = () => {
    scannerLoopActiveRef.current = false;
    if (mediaStreamRef.current) {
      mediaStreamRef.current.getTracks().forEach((t) => t.stop());
      mediaStreamRef.current = null;
    }
    if (videoRef.current) {
      videoRef.current.srcObject = null;
    }
    setIsCameraActive(false);
  };

  const toggleCameraFacing = () => {
    const nextMode = facingMode === 'environment' ? 'user' : 'environment';
    setFacingMode(nextMode);
  };

  useEffect(() => {
    if (isCameraActive) {
      startCamera();
    }
  }, [facingMode]);

  // Scanner Vision Loop
  const runScannerLoop = () => {
    lastScanFpsTimeRef.current = performance.now();
    scanFramesCountRef.current = 0;

    const scanFrame = () => {
      if (!scannerLoopActiveRef.current) return;

      const video = videoRef.current;
      const canvas = scannerCanvasRef.current;

      if (video && canvas && video.readyState === video.HAVE_ENOUGH_DATA) {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (ctx) {
          // Downsample or keep optimal size for high-speed JSQR parsing
          const w = video.videoWidth;
          const h = video.videoHeight;
          if (w > 0 && h > 0) {
            canvas.width = w;
            canvas.height = h;
            ctx.drawImage(video, 0, 0, w, h);

            const imageData = ctx.getImageData(0, 0, w, h);
            const qrCode = jsQR(imageData.data, imageData.width, imageData.height, {
              inversionAttempts: 'dontInvert',
            });

            if (qrCode && qrCode.data) {
              const packet = OpticalProtocol.parsePacket(qrCode.data);
              if (packet) {
                const hadEffect = receiverSession.ingestPacket(packet);
                if (hadEffect) {
                  setReceiverUpdateTick((t) => t + 1);

                  // Trigger celebration when assembly finishes!
                  if (receiverSession.assemblyPromise) {
                    receiverSession.assemblyPromise.then((success) => {
                      if (success) {
                        setReceiverUpdateTick((t) => t + 1);
                        confetti({
                          particleCount: 120,
                          spread: 80,
                          origin: { y: 0.6 },
                        });
                      }
                    });
                  } else if (receiverSession.isComplete) {
                    confetti({
                      particleCount: 120,
                      spread: 80,
                      origin: { y: 0.6 },
                    });
                  }
                }
              }
            }
          }
        }

        // Measure FPS
        scanFramesCountRef.current++;
        const now = performance.now();
        const diff = now - lastScanFpsTimeRef.current;
        if (diff >= 1000) {
          setReceiverFps(Math.round((scanFramesCountRef.current * 1000) / diff));
          scanFramesCountRef.current = 0;
          lastScanFpsTimeRef.current = now;
        }
      }

      if (scannerLoopActiveRef.current) {
        requestAnimationFrame(scanFrame);
      }
    };

    requestAnimationFrame(scanFrame);
  };

  // Reset Receiver
  const handleResetReceiver = () => {
    const fresh = new OpticalReceiverSession();
    setReceiverSession(fresh);
    setReceiverUpdateTick((t) => t + 1);
  };

  // Download Assembled File
  const handleDownloadAssembledFile = () => {
    if (!receiverSession.assembledBlob || !receiverSession.fileName) return;

    const url = URL.createObjectURL(receiverSession.assembledBlob);
    const a = document.createElement('a');
    a.href = url;
    a.download = receiverSession.fileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-md p-3 sm:p-6 overflow-y-auto">
      <div className="bg-white border border-slate-200/80 rounded-3xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden max-h-[92vh] animate-in fade-in zoom-in-95 duration-200">
        {/* HEADER */}
        <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between border-b border-slate-800">
          <div className="flex items-center space-x-3">
            <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white shadow-lg shadow-cyan-500/25">
              <Radio className="w-5 h-5 animate-pulse text-cyan-200" />
            </div>
            <div>
              <div className="flex items-center space-x-2">
                <h3 className="font-bold text-base tracking-tight text-white">
                  Air-Gapped QR Optical Transfer
                </h3>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase tracking-wider">
                  100% Offline PWA
                </span>
              </div>
              <p className="text-xs text-slate-400">
                Camera-to-Screen Data Transmission • Deflate + Fountain Erasure Codes
              </p>
            </div>
          </div>

          <button
            onClick={onClose}
            className="text-slate-400 hover:text-white p-2 rounded-xl hover:bg-slate-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* TABS SELECTOR */}
        <div className="flex border-b border-slate-200 bg-slate-50 px-6 pt-3">
          <button
            onClick={() => setActiveTab('sender')}
            className={`flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 transition ${
              activeTab === 'sender'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <QrCode className="w-4 h-4" />
            <span>Sender (Broadcast Screen)</span>
          </button>
          <button
            onClick={() => {
              setActiveTab('receiver');
              if (!isCameraActive) startCamera();
            }}
            className={`flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 transition ${
              activeTab === 'receiver'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            <Camera className="w-4 h-4" />
            <span>Receiver (Camera Scanner)</span>
            {receiverSession.totalBlocks > 0 && (
              <span className="ml-1 px-1.5 py-0.2 rounded-full text-[10px] font-bold bg-blue-100 text-blue-700">
                {receiverSession.progressPercent}%
              </span>
            )}
          </button>
        </div>

        {/* TAB CONTENTS */}
        <div className="flex-1 overflow-y-auto p-6 bg-slate-50/50">
          {/* ========================================================
              TAB 1: SENDER
             ======================================================== */}
          {activeTab === 'sender' && (
            <div className="space-y-6">
              {/* File Selection Box */}
              {!preparedTransfer ? (
                <div
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                      handleFileSelect(e.dataTransfer.files[0]);
                    }
                  }}
                  className="border-2 border-dashed border-slate-300 hover:border-blue-500 hover:bg-blue-50/30 rounded-3xl p-10 flex flex-col items-center justify-center text-center transition cursor-pointer bg-white"
                  onClick={() => {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.onchange = (e: any) => {
                      if (e.target.files && e.target.files[0]) {
                        handleFileSelect(e.target.files[0]);
                      }
                    };
                    input.click();
                  }}
                >
                  <div className="w-16 h-16 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-4 shadow-inner">
                    <Upload className="w-8 h-8" />
                  </div>
                  <h4 className="font-bold text-slate-800 text-base mb-1">
                    Select Any Local File to Stream
                  </h4>
                  <p className="text-xs text-slate-500 max-w-sm mb-4">
                    Images, PDFs, documents, or compressed archives. The file is compressed in-memory,
                    erasure-encoded, and converted into animated QR frames completely offline.
                  </p>
                  <span className="px-4 py-2 bg-blue-600 text-white font-medium text-xs rounded-xl shadow-md shadow-blue-500/25">
                    Browse File
                  </span>
                </div>
              ) : (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                  {/* Left: QR Canvas Player */}
                  <div className="lg:col-span-7 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs">
                    <div className="relative p-3 bg-white rounded-2xl border-2 border-slate-900 shadow-xl overflow-hidden flex items-center justify-center">
                      <canvas
                        ref={senderCanvasRef}
                        className="w-[280px] h-[280px] sm:w-[320px] sm:h-[320px] object-contain rounded-lg"
                      />

                      {/* Optical sync corner indicators */}
                      <div className="absolute top-1 left-1 w-2 h-2 bg-blue-600 rounded-full animate-ping" />
                    </div>

                    {/* Progress Bar & Frame Counter */}
                    <div className="w-full mt-4 space-y-2">
                      <div className="flex justify-between items-center text-xs">
                        <span className="font-semibold text-slate-700 flex items-center space-x-1">
                          <Layers className="w-3.5 h-3.5 text-blue-600" />
                          <span>
                            Frame {currentFrameIndex + 1} / {preparedTransfer.packets.length}
                          </span>
                        </span>
                        <span className="text-slate-500 font-mono text-[11px]">
                          {currentFrameIndex < preparedTransfer.totalBlocks ? (
                            <span className="text-blue-600 font-semibold">
                              Systematic Block #{currentFrameIndex + 1}
                            </span>
                          ) : (
                            <span className="text-amber-600 font-semibold">
                              Fountain Parity Block #{currentFrameIndex - preparedTransfer.totalBlocks + 1}
                            </span>
                          )}
                        </span>
                      </div>

                      <div className="w-full bg-slate-100 rounded-full h-2 overflow-hidden">
                        <div
                          className="bg-blue-600 h-full rounded-full transition-all duration-75"
                          style={{
                            width: `${
                              ((currentFrameIndex + 1) / preparedTransfer.packets.length) * 100
                            }%`,
                          }}
                        />
                      </div>
                    </div>

                    {/* Playback Controls */}
                    <div className="flex items-center justify-center space-x-3 mt-4">
                      <button
                        onClick={() => {
                          setIsPlaying(false);
                          setCurrentFrameIndex(
                            (idx) => (idx - 1 + preparedTransfer.packets.length) % preparedTransfer.packets.length
                          );
                        }}
                        className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        title="Previous Frame"
                      >
                        <SkipBack className="w-4 h-4" />
                      </button>

                      <button
                        onClick={() => setIsPlaying(!isPlaying)}
                        className={`px-5 py-2.5 rounded-xl font-bold text-xs flex items-center space-x-2 text-white shadow-md transition ${
                          isPlaying
                            ? 'bg-amber-500 hover:bg-amber-600 shadow-amber-500/25'
                            : 'bg-blue-600 hover:bg-blue-700 shadow-blue-500/25'
                        }`}
                      >
                        {isPlaying ? <Pause className="w-4 h-4" /> : <Play className="w-4 h-4" />}
                        <span>{isPlaying ? 'Pause' : 'Stream'}</span>
                      </button>

                      <button
                        onClick={() => {
                          setIsPlaying(false);
                          setCurrentFrameIndex(
                            (idx) => (idx + 1) % preparedTransfer.packets.length
                          );
                        }}
                        className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        title="Next Frame"
                      >
                        <SkipForward className="w-4 h-4" />
                      </button>

                      <button
                        onClick={() => {
                          setCurrentFrameIndex(0);
                        }}
                        className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 transition"
                        title="Restart from Frame 1"
                      >
                        <RotateCcw className="w-4 h-4" />
                      </button>
                    </div>
                  </div>

                  {/* Right: File Metrics & Tuners */}
                  <div className="lg:col-span-5 space-y-4">
                    {/* File Overview Card */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3">
                      <div className="flex items-start justify-between">
                        <div className="flex items-center space-x-2.5">
                          <div className="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                            <FileText className="w-5 h-5" />
                          </div>
                          <div>
                            <h5 className="font-bold text-slate-800 text-xs truncate max-w-[170px]">
                              {preparedTransfer.fileName}
                            </h5>
                            <span className="text-[10px] text-slate-400 font-mono">
                              ID: {preparedTransfer.fileId}
                            </span>
                          </div>
                        </div>
                        <button
                          onClick={() => {
                            setPreparedTransfer(null);
                            setSenderFile(null);
                          }}
                          className="text-[11px] text-blue-600 hover:underline font-semibold"
                        >
                          Change File
                        </button>
                      </div>

                      <div className="grid grid-cols-2 gap-2 text-xs pt-1 border-t border-slate-100">
                        <div className="p-2 rounded-xl bg-slate-50">
                          <span className="text-slate-400 text-[10px] block">Original Size</span>
                          <span className="font-bold text-slate-700">
                            {(preparedTransfer.originalSize / 1024).toFixed(1)} KB
                          </span>
                        </div>
                        <div className="p-2 rounded-xl bg-slate-50">
                          <span className="text-slate-400 text-[10px] block">Compressed</span>
                          <span className="font-bold text-emerald-600">
                            {(preparedTransfer.compressedSize / 1024).toFixed(1)} KB (
                            {preparedTransfer.compressionRatio}% saved)
                          </span>
                        </div>
                        <div className="p-2 rounded-xl bg-slate-50">
                          <span className="text-slate-400 text-[10px] block">Systematic Blocks</span>
                          <span className="font-bold text-slate-700">
                            {preparedTransfer.totalBlocks} blocks
                          </span>
                        </div>
                        <div className="p-2 rounded-xl bg-slate-50">
                          <span className="text-slate-400 text-[10px] block">Parity Fountain Blocks</span>
                          <span className="font-bold text-purple-600">
                            +{preparedTransfer.redundancyBlocks} blocks
                          </span>
                        </div>
                      </div>

                      <div className="text-[10px] bg-slate-50 p-2 rounded-xl font-mono text-slate-500 break-all border border-slate-100">
                        <span className="font-semibold text-slate-700 block">SHA-256 Checksum:</span>
                        {preparedTransfer.sha256}
                      </div>
                    </div>

                    {/* Tuning Sliders */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-4">
                      <div className="flex items-center space-x-2 text-xs font-bold text-slate-800">
                        <Sliders className="w-4 h-4 text-blue-600" />
                        <span>Transmission Tuning</span>
                      </div>

                      {/* FPS Control */}
                      <div className="space-y-1.5">
                        <div className="flex justify-between text-xs">
                          <span className="text-slate-600 font-medium">Optical FPS Speed</span>
                          <span className="font-bold text-blue-600 font-mono">
                            {targetFps} FPS ({actualSenderFps} rendered)
                          </span>
                        </div>
                        <input
                          type="range"
                          min="10"
                          max="60"
                          step="2"
                          value={targetFps}
                          onChange={(e) => setTargetFps(Number(e.target.value))}
                          className="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                        />
                        <div className="flex justify-between text-[10px] text-slate-400">
                          <span>10 FPS (Slow Camera)</span>
                          <span>30 FPS</span>
                          <span>60 FPS (Ultra-Fast)</span>
                        </div>
                      </div>

                      {/* Chunk Size Control */}
                      <div className="space-y-1.5">
                        <div className="flex justify-between text-xs">
                          <span className="text-slate-600 font-medium">QR Density (Chunk Size)</span>
                          <span className="font-bold text-blue-600 font-mono">{chunkSize} Bytes</span>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                          {[
                            { label: 'Low (220B)', val: 220 },
                            { label: 'Medium (360B)', val: 360 },
                            { label: 'High (480B)', val: 480 },
                          ].map((opt) => (
                            <button
                              key={opt.val}
                              onClick={() => setChunkSize(opt.val)}
                              className={`py-1.5 px-2 rounded-xl text-xs font-semibold border transition ${
                                chunkSize === opt.val
                                  ? 'bg-blue-50 border-blue-500 text-blue-700'
                                  : 'bg-slate-50 border-slate-200 text-slate-600 hover:bg-slate-100'
                              }`}
                            >
                              {opt.label}
                            </button>
                          ))}
                        </div>
                      </div>

                      {/* Fountain Erasure Redundancy Slider */}
                      <div className="space-y-1.5">
                        <div className="flex justify-between text-xs">
                          <span className="text-slate-600 font-medium">Fountain Redundancy (Loss Recovery)</span>
                          <span className="font-bold text-purple-600 font-mono">
                            {redundancyPercent}%
                          </span>
                        </div>
                        <input
                          type="range"
                          min="10"
                          max="50"
                          step="5"
                          value={redundancyPercent}
                          onChange={(e) => setRedundancyPercent(Number(e.target.value))}
                          className="w-full h-1.5 bg-slate-200 rounded-lg appearance-none cursor-pointer accent-purple-600"
                        />
                        <p className="text-[10px] text-slate-400">
                          Generates XOR erasure packets to instantly recover dropped frames without waiting for a full cycle.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* ========================================================
              TAB 2: RECEIVER
             ======================================================== */}
          {activeTab === 'receiver' && (
            <div className="space-y-6">
              <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                {/* Left: Camera Feed Viewfinder */}
                <div className="lg:col-span-7 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-4">
                  <div className="relative w-full max-w-[380px] aspect-square bg-slate-950 rounded-2xl overflow-hidden border-2 border-slate-800 flex items-center justify-center shadow-xl">
                    {/* Live Video */}
                    <video
                      ref={videoRef}
                      playsInline
                      muted
                      className="w-full h-full object-cover"
                    />

                    {/* Hidden Canvas for Vision Processing */}
                    <canvas ref={scannerCanvasRef} className="hidden" />

                    {/* Reticle / Viewfinder Overlay */}
                    <div className="absolute inset-8 border-2 border-cyan-400/60 rounded-2xl pointer-events-none flex flex-col justify-between p-2">
                      <div className="flex justify-between">
                        <div className="w-4 h-4 border-t-2 border-l-2 border-cyan-400" />
                        <div className="w-4 h-4 border-t-2 border-r-2 border-cyan-400" />
                      </div>

                      {/* Optical scanning laser beam */}
                      <div className="w-full h-0.5 bg-cyan-400 shadow-[0_0_12px_#22d3ee] animate-bounce" />

                      <div className="flex justify-between">
                        <div className="w-4 h-4 border-b-2 border-l-2 border-cyan-400" />
                        <div className="w-4 h-4 border-b-2 border-r-2 border-cyan-400" />
                      </div>
                    </div>

                    {/* Camera Offline Warning */}
                    {!isCameraActive && (
                      <div className="absolute inset-0 bg-slate-950/90 flex flex-col items-center justify-center p-4 text-center">
                        <AlertTriangle className="w-10 h-10 text-amber-500 mb-2" />
                        <p className="text-xs text-white font-medium mb-3">
                          {cameraError || 'Camera feed paused'}
                        </p>
                        <button
                          onClick={startCamera}
                          className="px-4 py-2 bg-blue-600 hover:bg-blue-500 text-white font-semibold text-xs rounded-xl shadow-md transition"
                        >
                          Start Camera
                        </button>
                      </div>
                    )}

                    {/* Vision Stats Badge */}
                    <div className="absolute top-3 left-3 bg-slate-900/80 backdrop-blur-md px-2.5 py-1 rounded-lg border border-slate-700 text-[10px] font-mono text-cyan-300 flex items-center space-x-1.5">
                      <div className="w-2 h-2 rounded-full bg-cyan-400 animate-ping" />
                      <span>{receiverFps} FPS Scan</span>
                    </div>

                    {/* Camera Flip Button */}
                    <button
                      onClick={toggleCameraFacing}
                      className="absolute top-3 right-3 bg-slate-900/80 hover:bg-slate-800 backdrop-blur-md p-2 rounded-lg border border-slate-700 text-white transition"
                      title="Flip Camera (Front/Back)"
                    >
                      <RefreshCw className="w-3.5 h-3.5" />
                    </button>
                  </div>

                  {/* Actions & Resets */}
                  <div className="flex items-center space-x-3">
                    <button
                      onClick={handleResetReceiver}
                      className="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold flex items-center space-x-1.5 transition"
                    >
                      <RotateCcw className="w-3.5 h-3.5" />
                      <span>Reset Receiver</span>
                    </button>
                    {isCameraActive ? (
                      <button
                        onClick={stopCamera}
                        className="px-4 py-2 rounded-xl bg-slate-100 hover:bg-red-50 text-slate-700 hover:text-red-600 text-xs font-semibold flex items-center space-x-1.5 transition"
                      >
                        <Pause className="w-3.5 h-3.5" />
                        <span>Pause Camera</span>
                      </button>
                    ) : (
                      <button
                        onClick={startCamera}
                        className="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-semibold flex items-center space-x-1.5 transition shadow-sm"
                      >
                        <Play className="w-3.5 h-3.5" />
                        <span>Resume Camera</span>
                      </button>
                    )}
                  </div>
                </div>

                {/* Right: Real-time Matrix Grid & Assembly Stats */}
                <div className="lg:col-span-5 space-y-4">
                  {/* File Transfer Summary Card */}
                  <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center space-x-2">
                        <Zap className="w-4 h-4 text-cyan-600" />
                        <h5 className="font-bold text-slate-800 text-xs">
                          {receiverSession.fileId
                            ? `Incoming: ${receiverSession.fileName}`
                            : 'Awaiting Optical Stream...'}
                        </h5>
                      </div>
                      <span className="font-bold text-xs font-mono text-cyan-600">
                        {receiverSession.progressPercent}%
                      </span>
                    </div>

                    {/* Big Progress Bar */}
                    <div className="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                      <div
                        className={`h-full rounded-full transition-all duration-150 ${
                          receiverSession.isComplete ? 'bg-emerald-500' : 'bg-cyan-500'
                        }`}
                        style={{ width: `${receiverSession.progressPercent}%` }}
                      />
                    </div>

                    {/* Stats Grid */}
                    <div className="grid grid-cols-2 gap-2 text-xs pt-1 border-t border-slate-100">
                      <div className="p-2 rounded-xl bg-slate-50">
                        <span className="text-slate-400 text-[10px] block">Blocks Received</span>
                        <span className="font-bold text-slate-700">
                          {receiverSession.receivedCount} / {receiverSession.totalBlocks || '—'}
                        </span>
                      </div>
                      <div className="p-2 rounded-xl bg-slate-50">
                        <span className="text-slate-400 text-[10px] block">Fountain Recovered</span>
                        <span className="font-bold text-purple-600">
                          {receiverSession.parityRecoveries} blocks
                        </span>
                      </div>
                      <div className="p-2 rounded-xl bg-slate-50">
                        <span className="text-slate-400 text-[10px] block">Frames Scanned</span>
                        <span className="font-bold text-slate-700">
                          {receiverSession.totalFramesScanned}
                        </span>
                      </div>
                      <div className="p-2 rounded-xl bg-slate-50">
                        <span className="text-slate-400 text-[10px] block">Duplicate Frames</span>
                        <span className="font-bold text-slate-400">
                          {receiverSession.duplicateFrames}
                        </span>
                      </div>
                    </div>

                    {/* Missing blocks notice */}
                    {receiverSession.totalBlocks > 0 && !receiverSession.isComplete && (
                      <div className="text-[10px] p-2 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl">
                        <span className="font-semibold block">Missing Blocks:</span>
                        <span className="font-mono">
                          {receiverSession.getMissingBlocks().slice(0, 15).join(', ')}
                          {receiverSession.getMissingBlocks().length > 15 ? '...' : ''}
                        </span>
                      </div>
                    )}
                  </div>

                  {/* VISUAL COMPLETION MATRIX GRID */}
                  <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-2.5">
                    <div className="flex items-center justify-between text-xs">
                      <span className="font-bold text-slate-800 flex items-center space-x-1.5">
                        <Eye className="w-3.5 h-3.5 text-blue-600" />
                        <span>Completion Matrix Grid</span>
                      </span>
                      <span className="text-[10px] text-slate-400 font-mono">
                        {receiverSession.receivedCount}/{receiverSession.totalBlocks} Blocks
                      </span>
                    </div>

                    {receiverSession.totalBlocks > 0 ? (
                      <div className="grid grid-cols-8 sm:grid-cols-10 gap-1.5 max-h-48 overflow-y-auto p-2 bg-slate-900 rounded-xl border border-slate-800">
                        {Array.from({ length: receiverSession.totalBlocks }, (_, i) => i + 1).map(
                          (seq) => {
                            const isReceived = receiverSession.isBlockReceived(seq);
                            return (
                              <div
                                key={seq}
                                title={`Block #${seq}: ${isReceived ? 'Received' : 'Pending'}`}
                                className={`aspect-square rounded-md text-[9px] font-mono font-bold flex items-center justify-center transition-all ${
                                  isReceived
                                    ? 'bg-emerald-500 text-white shadow-[0_0_8px_#10b981]'
                                    : 'bg-slate-800 text-slate-600 border border-slate-700/50'
                                }`}
                              >
                                {seq}
                              </div>
                            );
                          }
                        )}
                      </div>
                    ) : (
                      <div className="p-6 bg-slate-50 border border-slate-200/60 rounded-xl text-center text-xs text-slate-400">
                        Point camera at animated QR code on the sender device to start decoding.
                      </div>
                    )}
                  </div>

                  {/* SUCCESS / COMPLETION CELEBRATION CARD */}
                  {receiverSession.isComplete && (
                    <div className="p-5 bg-gradient-to-tr from-emerald-50 to-teal-50 border-2 border-emerald-400 rounded-2xl shadow-lg space-y-3 animate-in zoom-in-95">
                      <div className="flex items-center space-x-2 text-emerald-800 font-bold text-sm">
                        <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                        <span>Optical Transfer Complete & Verified!</span>
                      </div>

                      <div className="space-y-1 text-xs text-emerald-900">
                        <div className="font-semibold text-slate-800">{receiverSession.fileName}</div>
                        <div className="text-[10px] text-emerald-700 flex items-center space-x-1">
                          <ShieldCheck className="w-3.5 h-3.5 text-emerald-600" />
                          <span>SHA-256 Checksum Verified Lossless</span>
                        </div>
                      </div>

                      <button
                        onClick={handleDownloadAssembledFile}
                        className="w-full py-2.5 px-4 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl shadow-md shadow-emerald-600/25 flex items-center justify-center space-x-2 transition"
                      >
                        <Download className="w-4 h-4" />
                        <span>Save & Download File</span>
                      </button>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}
        </div>

        {/* FOOTER */}
        <div className="px-6 py-3 bg-white border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-[11px]">Air-Gapped Optical Layer Active • No WiFi or Bluetooth Required</span>
          </div>
          <button
            onClick={onClose}
            className="px-4 py-1.5 text-xs font-semibold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-xl transition"
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
};
