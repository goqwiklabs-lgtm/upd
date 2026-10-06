import React, { useState, useEffect, useRef } from 'react';
import {
  X,
  Wifi,
  Upload,
  Download,
  CheckCircle2,
  AlertTriangle,
  QrCode,
  Smartphone,
  Laptop,
  Zap,
  RefreshCw,
  FileText,
  Copy,
  Info,
} from 'lucide-react';
import QRCode from 'qrcode';
import jsQR from 'jsqr';
import confetti from 'canvas-confetti';
import {
  P2PTransferEngine,
  type P2PFileMeta,
  type P2PHandshakePayload,
  type TransferMetrics,
} from '../utils/p2pTransfer';

interface P2PShareModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export const P2PShareModal: React.FC<P2PShareModalProps> = ({ isOpen, onClose }) => {
  const [activeTab, setActiveTab] = useState<'send' | 'receive'>('send');
  const [operatingMode, setOperatingMode] = useState<'direct_ap' | 'lan_router'>('lan_router');

  // ==========================================
  // SENDER STATE
  // ==========================================
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [senderIp, setSenderIp] = useState('10.0.11.249');
  const [senderPort, setSenderPort] = useState(8080);
  const [hotspotSsid, setHotspotSsid] = useState('Direct-Share-8492');
  const [hotspotPass, setHotspotPass] = useState('x9k2mP7q');
  const [qrDisplayType, setQrDisplayType] = useState<'handshake' | 'wifi'>('handshake');
  const [handshakePayload, setHandshakePayload] = useState<P2PHandshakePayload | null>(null);

  const qrCanvasRef = useRef<HTMLCanvasElement | null>(null);

  // ==========================================
  // RECEIVER STATE
  // ==========================================
  const [isCameraActive, setIsCameraActive] = useState(false);
  const [cameraError, setCameraError] = useState<string | null>(null);
  const [facingMode, setFacingMode] = useState<'environment' | 'user'>('environment');
  const [manualAddress, setManualAddress] = useState('');
  const [receivedManifest, setReceivedManifest] = useState<P2PHandshakePayload | null>(null);

  const [activeTransferMetrics, setActiveTransferMetrics] = useState<TransferMetrics | null>(null);
  const [completedBlobs, setCompletedBlobs] = useState<Map<string, { blob: Blob; fileName: string }>>(
    new Map()
  );

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const scannerCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const mediaStreamRef = useRef<MediaStream | null>(null);
  const scannerLoopActiveRef = useRef<boolean>(false);
  const abortControllerRef = useRef<AbortController | null>(null);

  // Fetch local IP from background streaming server if available
  useEffect(() => {
    if (isOpen) {
      fetch('http://127.0.0.1:8080/status')
        .then((res) => res.json())
        .then((data) => {
          if (data.interfaces && data.interfaces.length > 0) {
            setSenderIp(data.interfaces[0].ip);
            setSenderPort(data.port || 8080);
          }
        })
        .catch(() => {
          // Streaming server not on localhost or running elsewhere
        });
    }
  }, [isOpen]);

  // Clean up on modal close
  useEffect(() => {
    if (!isOpen) {
      stopCamera();
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
    }
  }, [isOpen]);

  // ==========================================
  // SENDER LOGIC: Construct Handshake & Render QR
  // ==========================================
  useEffect(() => {
    if (selectedFiles.length === 0) {
      setHandshakePayload(null);
      return;
    }

    const fileMetas: P2PFileMeta[] = selectedFiles.map((f, idx) => ({
      id: `f_${idx + 101}`,
      name: f.name,
      size: f.size,
      type: f.type || 'application/octet-stream',
    }));

    const payload = P2PTransferEngine.createHandshakePayload({
      deviceName: `User's Device (${operatingMode === 'direct_ap' ? 'Hotspot' : 'Wi-Fi'})`,
      mode: operatingMode,
      ip: senderIp,
      port: senderPort,
      files: fileMetas,
      ssid: operatingMode === 'direct_ap' ? hotspotSsid : undefined,
      pass: operatingMode === 'direct_ap' ? hotspotPass : undefined,
    });

    setHandshakePayload(payload);
  }, [selectedFiles, operatingMode, senderIp, senderPort, hotspotSsid, hotspotPass]);

  // Draw QR Code
  useEffect(() => {
    if (!qrCanvasRef.current || !handshakePayload) return;

    let qrContent = '';
    if (qrDisplayType === 'wifi' && operatingMode === 'direct_ap' && handshakePayload.ssid) {
      qrContent = P2PTransferEngine.generateWiFiQRString(
        handshakePayload.ssid,
        handshakePayload.pass || ''
      );
    } else {
      qrContent = P2PTransferEngine.serializeHandshake(handshakePayload);
    }

    QRCode.toCanvas(qrCanvasRef.current, qrContent, {
      width: 280,
      margin: 2,
      errorCorrectionLevel: 'M',
      color: {
        dark: '#0f172a',
        light: '#ffffff',
      },
    }).catch((err) => console.error('QR draw error:', err));
  }, [handshakePayload, qrDisplayType, operatingMode]);

  // ==========================================
  // RECEIVER LOGIC: Camera Vision & High-Speed Stream
  // ==========================================
  const startCamera = async () => {
    setCameraError(null);
    try {
      if (mediaStreamRef.current) {
        mediaStreamRef.current.getTracks().forEach((t) => t.stop());
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
        audio: false,
      });
      mediaStreamRef.current = stream;

      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        await videoRef.current.play();
      }

      setIsCameraActive(true);
      scannerLoopActiveRef.current = true;
      runScannerLoop();
    } catch (err: unknown) {
      console.error('Camera error:', err);
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
    setFacingMode((m) => (m === 'environment' ? 'user' : 'environment'));
  };

  useEffect(() => {
    if (isCameraActive) {
      startCamera();
    }
  }, [facingMode]);

  // Scanner Vision Loop
  const runScannerLoop = () => {
    const scan = () => {
      if (!scannerLoopActiveRef.current) return;

      const video = videoRef.current;
      const canvas = scannerCanvasRef.current;

      if (video && canvas && video.readyState === video.HAVE_ENOUGH_DATA) {
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        if (ctx) {
          canvas.width = video.videoWidth;
          canvas.height = video.videoHeight;
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

          const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
          const code = jsQR(imgData.data, imgData.width, imgData.height, {
            inversionAttempts: 'dontInvert',
          });

          if (code && code.data) {
            const parsed = P2PTransferEngine.parseHandshake(code.data);
            if (parsed) {
              setReceivedManifest(parsed);
              stopCamera();
              // Haptic feedback / chime
              if (navigator.vibrate) navigator.vibrate(80);
              return;
            }
          }
        }
      }

      if (scannerLoopActiveRef.current) {
        requestAnimationFrame(scan);
      }
    };

    requestAnimationFrame(scan);
  };

  // Manual Handshake Connect
  const handleManualConnect = async () => {
    if (!manualAddress.trim()) return;
    try {
      let url = manualAddress.trim();
      if (!url.startsWith('http://') && !url.startsWith('https://')) {
        url = `http://${url}`;
      }
      const manifestUrl = url.endsWith('/manifest') ? url : `${url}/manifest`;
      const res = await fetch(manifestUrl);
      const data = await res.json();
      const parsed = P2PTransferEngine.parseHandshake(JSON.stringify(data));
      if (parsed) {
        setReceivedManifest(parsed);
      } else {
        alert('Invalid offline-share manifest response from server');
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Connection failed';
      alert(`Could not connect to ${manualAddress}: ${msg}`);
    }
  };

  // Start High-Speed Binary Stream Download
  const handleDownloadFile = async (file: P2PFileMeta) => {
    if (!receivedManifest) return;

    abortControllerRef.current = new AbortController();
    const downloadUrl = `http://${receivedManifest.ip}:${receivedManifest.port}/download/${file.id}?token=${receivedManifest.authKey}`;

    try {
      setActiveTransferMetrics({
        fileId: file.id,
        fileName: file.name,
        fileSize: file.size,
        transferredBytes: 0,
        percent: 0,
        speedMBs: 0,
        etaSeconds: 0,
        status: 'connecting',
      });

      const { blob } = await P2PTransferEngine.downloadFileStream(
        downloadUrl,
        file,
        (metrics) => {
          setActiveTransferMetrics(metrics);
        },
        abortControllerRef.current.signal
      );

      // Save blob into completed map
      setCompletedBlobs((prev) => new Map(prev).set(file.id, { blob, fileName: file.name }));

      // Trigger instant browser download
      const objectUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objectUrl;
      a.download = file.name;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(objectUrl);

      // Celebration
      confetti({
        particleCount: 100,
        spread: 70,
        origin: { y: 0.6 },
      });
    } catch (err: unknown) {
      console.error('Download stream error:', err);
      const msg = err instanceof Error ? err.message : 'Transfer failed';
      setActiveTransferMetrics((prev) =>
        prev ? { ...prev, status: 'error', errorMessage: msg } : null
      );
    }
  };

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/85 backdrop-blur-md p-3 sm:p-6 overflow-y-auto">
      <div className="bg-white border border-slate-200/90 rounded-3xl shadow-2xl w-full max-w-4xl flex flex-col overflow-hidden max-h-[92vh] animate-in fade-in zoom-in-95 duration-200">
        {/* HEADER */}
        <div className="px-6 py-4 bg-slate-900 text-white flex items-center justify-between border-b border-slate-800">
          <div className="flex items-center space-x-3">
            <div className="w-10 h-10 rounded-2xl bg-gradient-to-tr from-emerald-500 to-cyan-600 flex items-center justify-center text-white shadow-lg shadow-emerald-500/25">
              <Zap className="w-5 h-5 text-white animate-pulse" />
            </div>
            <div>
              <div className="flex items-center space-x-2">
                <h3 className="font-bold text-base tracking-tight text-white">
                  P2P Offline File Transfer
                </h3>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase tracking-wider">
                  30–80+ MB/s
                </span>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase tracking-wider">
                  Zero Data
                </span>
              </div>
              <p className="text-xs text-slate-400">
                LocalSend & Xender Architecture • Direct Wi-Fi Socket Streaming • Zero Internet Required
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

        {/* TABS & MODE SELECTOR */}
        <div className="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-6 pt-3">
          <div className="flex space-x-2">
            <button
              onClick={() => setActiveTab('send')}
              className={`flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 transition ${
                activeTab === 'send'
                  ? 'border-emerald-600 text-emerald-600'
                  : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              <Upload className="w-4 h-4" />
              <span>Send Files (Host)</span>
            </button>
            <button
              onClick={() => {
                setActiveTab('receive');
                if (!isCameraActive && !receivedManifest) startCamera();
              }}
              className={`flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 transition ${
                activeTab === 'receive'
                  ? 'border-emerald-600 text-emerald-600'
                  : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              <Download className="w-4 h-4" />
              <span>Receive Files (Connect)</span>
            </button>
          </div>

          <div className="flex items-center space-x-2 pb-2">
            <span className="text-[11px] font-medium text-slate-400">Mode:</span>
            <div className="inline-flex p-0.5 bg-slate-200/70 rounded-xl text-[11px]">
              <button
                onClick={() => setOperatingMode('lan_router')}
                className={`px-2.5 py-1 rounded-lg font-semibold transition ${
                  operatingMode === 'lan_router'
                    ? 'bg-white text-slate-800 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
                title="Both devices on same home Wi-Fi"
              >
                Home Wi-Fi (Mode A)
              </button>
              <button
                onClick={() => setOperatingMode('direct_ap')}
                className={`px-2.5 py-1 rounded-lg font-semibold transition ${
                  operatingMode === 'direct_ap'
                    ? 'bg-white text-slate-800 shadow-2xs'
                    : 'text-slate-600 hover:text-slate-900'
                }`}
                title="Direct Hotspot / Zero Router"
              >
                Direct Hotspot (Mode B)
              </button>
            </div>
          </div>
        </div>

        {/* MODAL CONTENT */}
        <div className="flex-1 overflow-y-auto p-6 bg-slate-50/50">
          {/* ========================================================
              TAB 1: SENDER (HOST)
             ======================================================== */}
          {activeTab === 'send' && (
            <div className="space-y-6">
              {/* Environment Info Banner */}
              <div className="p-3.5 bg-emerald-50/80 border border-emerald-200 rounded-2xl flex items-start space-x-3 text-xs text-emerald-900">
                <Info className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                <div>
                  <span className="font-bold">
                    {operatingMode === 'lan_router'
                      ? 'Mode A: Shared Local Network (Wi-Fi Router)'
                      : 'Mode B: Pure Offline Direct Mode (Direct Hotspot)'}
                  </span>
                  <p className="text-emerald-700 text-[11px] mt-0.5">
                    {operatingMode === 'lan_router'
                      ? 'Both devices are connected to the same Wi-Fi router. Files stream directly between devices over the local subnet without consuming cellular data or hitting the cloud.'
                      : 'Zero internet, zero router required. The sender device runs a Wi-Fi Hotspot (AP). The receiver scans the QR code to pair and download files directly.'}
                  </p>
                </div>
              </div>

              {/* File Dropzone */}
              {selectedFiles.length === 0 ? (
                <div
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (e.dataTransfer.files) {
                      setSelectedFiles(Array.from(e.dataTransfer.files));
                    }
                  }}
                  onClick={() => {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.multiple = true;
                    input.onchange = (e: any) => {
                      if (e.target.files) setSelectedFiles(Array.from(e.target.files));
                    };
                    input.click();
                  }}
                  className="border-2 border-dashed border-slate-300 hover:border-emerald-500 hover:bg-emerald-50/20 rounded-3xl p-10 flex flex-col items-center justify-center text-center transition cursor-pointer bg-white"
                >
                  <div className="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4 shadow-inner">
                    <Upload className="w-8 h-8" />
                  </div>
                  <h4 className="font-bold text-slate-800 text-base mb-1">
                    Select High-Capacity Files to Share
                  </h4>
                  <p className="text-xs text-slate-500 max-w-sm mb-4">
                    Send raw 4K videos, massive ZIP archives, or whole albums directly to phones, laptops, or PCs at 30–80+ MB/s.
                  </p>
                  <span className="px-4 py-2 bg-emerald-600 text-white font-medium text-xs rounded-xl shadow-md shadow-emerald-500/25">
                    Browse Files
                  </span>
                </div>
              ) : (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                  {/* Left: Optical Handshake QR Code Card */}
                  <div className="lg:col-span-6 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-4">
                    <div className="flex items-center justify-between w-full">
                      <div className="flex items-center space-x-2">
                        <QrCode className="w-4 h-4 text-emerald-600" />
                        <span className="font-bold text-xs text-slate-800">
                          {qrDisplayType === 'handshake'
                            ? 'Optical Handshake QR'
                            : 'Wi-Fi Auto-Connect QR'}
                        </span>
                      </div>
                      {operatingMode === 'direct_ap' && (
                        <div className="flex bg-slate-100 rounded-lg p-0.5 text-[10px]">
                          <button
                            onClick={() => setQrDisplayType('handshake')}
                            className={`px-2 py-0.5 rounded font-semibold ${
                              qrDisplayType === 'handshake'
                                ? 'bg-white text-slate-800 shadow-2xs'
                                : 'text-slate-500'
                            }`}
                          >
                            Handshake
                          </button>
                          <button
                            onClick={() => setQrDisplayType('wifi')}
                            className={`px-2 py-0.5 rounded font-semibold ${
                              qrDisplayType === 'wifi'
                                ? 'bg-white text-slate-800 shadow-2xs'
                                : 'text-slate-500'
                            }`}
                          >
                            Wi-Fi Join
                          </button>
                        </div>
                      )}
                    </div>

                    {/* QR Code Canvas */}
                    <div className="p-3 bg-white rounded-2xl border-2 border-slate-900 shadow-lg">
                      <canvas ref={qrCanvasRef} className="w-[240px] h-[240px] object-contain rounded-lg" />
                    </div>

                    <p className="text-[11px] text-slate-500 text-center max-w-xs">
                      Scan this QR code from the Receiver tab or phone camera to establish the local direct link.
                    </p>

                    {/* Quick Direct Link Pill */}
                    <div className="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                      <div className="truncate mr-2">
                        <span className="text-[10px] text-slate-400 block">Local Stream Server URL</span>
                        <span className="font-mono font-bold text-slate-700 text-[11px]">
                          http://{senderIp}:{senderPort}
                        </span>
                      </div>
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(`http://${senderIp}:${senderPort}`);
                          alert('Server URL copied to clipboard');
                        }}
                        className="p-1.5 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 transition"
                        title="Copy URL"
                      >
                        <Copy className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {/* Right: Files Manifest & Network Config */}
                  <div className="lg:col-span-6 space-y-4">
                    {/* Selected Files List */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2">
                          <FileText className="w-4 h-4 text-emerald-600" />
                          <h5 className="font-bold text-slate-800 text-xs">
                            Hosted Files ({selectedFiles.length})
                          </h5>
                        </div>
                        <button
                          onClick={() => setSelectedFiles([])}
                          className="text-[11px] text-red-500 hover:underline font-semibold"
                        >
                          Clear All
                        </button>
                      </div>

                      <div className="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                        {selectedFiles.map((f, i) => (
                          <div
                            key={i}
                            className="flex items-center justify-between p-2 rounded-xl bg-slate-50 border border-slate-100 text-xs"
                          >
                            <span className="font-medium text-slate-700 truncate max-w-[200px]">
                              {f.name}
                            </span>
                            <span className="font-mono text-[10px] text-slate-400">
                              {P2PTransferEngine.formatBytes(f.size)}
                            </span>
                          </div>
                        ))}
                      </div>

                      <div className="flex justify-between items-center pt-2 border-t border-slate-100 text-xs">
                        <span className="text-slate-500">Total Payload:</span>
                        <span className="font-bold text-slate-800">
                          {P2PTransferEngine.formatBytes(
                            selectedFiles.reduce((acc, curr) => acc + curr.size, 0)
                          )}
                        </span>
                      </div>
                    </div>

                    {/* Network Settings Tuner */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3 text-xs">
                      <h5 className="font-bold text-slate-800 flex items-center space-x-1.5">
                        <Wifi className="w-3.5 h-3.5 text-blue-600" />
                        <span>Network Parameters</span>
                      </h5>

                      <div className="grid grid-cols-2 gap-3">
                        <div>
                          <label className="text-[10px] font-semibold text-slate-500 block mb-1">
                            Local IPv4 Address
                          </label>
                          <input
                            type="text"
                            value={senderIp}
                            onChange={(e) => setSenderIp(e.target.value)}
                            className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs outline-none focus:border-emerald-500"
                          />
                        </div>
                        <div>
                          <label className="text-[10px] font-semibold text-slate-500 block mb-1">
                            HTTP Stream Port
                          </label>
                          <input
                            type="number"
                            value={senderPort}
                            onChange={(e) => setSenderPort(Number(e.target.value))}
                            className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs outline-none focus:border-emerald-500"
                          />
                        </div>
                      </div>

                      {operatingMode === 'direct_ap' && (
                        <div className="grid grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                          <div>
                            <label className="text-[10px] font-semibold text-slate-500 block mb-1">
                              Hotspot SSID
                            </label>
                            <input
                              type="text"
                              value={hotspotSsid}
                              onChange={(e) => setHotspotSsid(e.target.value)}
                              className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs outline-none focus:border-emerald-500"
                            />
                          </div>
                          <div>
                            <label className="text-[10px] font-semibold text-slate-500 block mb-1">
                              Hotspot Password
                            </label>
                            <input
                              type="text"
                              value={hotspotPass}
                              onChange={(e) => setHotspotPass(e.target.value)}
                              className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-200 rounded-xl font-mono text-xs outline-none focus:border-emerald-500"
                            />
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* ========================================================
              TAB 2: RECEIVER (CLIENT)
             ======================================================== */}
          {activeTab === 'receive' && (
            <div className="space-y-6">
              {!receivedManifest ? (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                  {/* Left: Camera Scanner Viewfinder */}
                  <div className="lg:col-span-7 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-4">
                    <div className="relative w-full max-w-[340px] aspect-square bg-slate-950 rounded-2xl overflow-hidden border-2 border-slate-800 flex items-center justify-center shadow-xl">
                      <video ref={videoRef} playsInline muted className="w-full h-full object-cover" />
                      <canvas ref={scannerCanvasRef} className="hidden" />

                      {/* Viewfinder Reticle */}
                      <div className="absolute inset-8 border-2 border-emerald-400/70 rounded-2xl pointer-events-none flex flex-col justify-between p-2">
                        <div className="flex justify-between">
                          <div className="w-4 h-4 border-t-2 border-l-2 border-emerald-400" />
                          <div className="w-4 h-4 border-t-2 border-r-2 border-emerald-400" />
                        </div>
                        <div className="w-full h-0.5 bg-emerald-400 shadow-[0_0_12px_#10b981] animate-bounce" />
                        <div className="flex justify-between">
                          <div className="w-4 h-4 border-b-2 border-l-2 border-emerald-400" />
                          <div className="w-4 h-4 border-b-2 border-r-2 border-emerald-400" />
                        </div>
                      </div>

                      {!isCameraActive && (
                        <div className="absolute inset-0 bg-slate-950/90 flex flex-col items-center justify-center p-4 text-center">
                          <AlertTriangle className="w-10 h-10 text-amber-500 mb-2" />
                          <p className="text-xs text-white font-medium mb-3">
                            {cameraError || 'Camera scanner paused'}
                          </p>
                          <button
                            onClick={startCamera}
                            className="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white font-semibold text-xs rounded-xl shadow-md transition"
                          >
                            Start Camera
                          </button>
                        </div>
                      )}

                      <button
                        onClick={toggleCameraFacing}
                        className="absolute top-3 right-3 bg-slate-900/80 hover:bg-slate-800 p-2 rounded-lg border border-slate-700 text-white transition"
                        title="Flip Camera"
                      >
                        <RefreshCw className="w-3.5 h-3.5" />
                      </button>
                    </div>

                    <p className="text-xs text-slate-500 text-center">
                      Point camera at the Sender's QR Code to automatically pair and fetch file manifest.
                    </p>
                  </div>

                  {/* Right: Manual IP / Address Fallback */}
                  <div className="lg:col-span-5 p-5 bg-white rounded-3xl border border-slate-200/80 space-y-4">
                    <h5 className="font-bold text-slate-800 text-xs flex items-center space-x-1.5">
                      <Laptop className="w-4 h-4 text-blue-600" />
                      <span>Manual Local Connect</span>
                    </h5>
                    <p className="text-[11px] text-slate-500">
                      If camera is unavailable, enter the sender device's local IP address and port directly:
                    </p>

                    <div className="space-y-2">
                      <input
                        type="text"
                        placeholder="e.g. 192.168.1.15:8080 or 10.0.11.249:8080"
                        value={manualAddress}
                        onChange={(e) => setManualAddress(e.target.value)}
                        className="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono outline-none focus:border-emerald-500"
                      />
                      <button
                        onClick={handleManualConnect}
                        className="w-full py-2 bg-blue-600 hover:bg-blue-500 text-white font-bold text-xs rounded-xl shadow-md transition"
                      >
                        Connect & Fetch Files
                      </button>
                    </div>

                    {/* Mobile OS Tips Card */}
                    <div className="p-3 bg-amber-50/70 border border-amber-200 rounded-xl space-y-1.5 text-[11px] text-amber-900">
                      <span className="font-bold block flex items-center space-x-1">
                        <Smartphone className="w-3.5 h-3.5 text-amber-700" />
                        <span>Mobile OS Hotspot Tip</span>
                      </span>
                      <p className="text-amber-800 leading-relaxed">
                        If joining sender's direct hotspot, keep Wi-Fi connected even if Android or iOS asks:
                        <em> "Wi-Fi has no internet access, switch to mobile data?"</em> Select <strong>"Stay Connected"</strong>.
                      </p>
                    </div>
                  </div>
                </div>
              ) : (
                /* Manifest Received & Download Section */
                <div className="space-y-6">
                  {/* Sender Device Overview */}
                  <div className="p-5 bg-white rounded-3xl border border-slate-200/80 flex items-center justify-between shadow-xs">
                    <div className="flex items-center space-x-3.5">
                      <div className="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                        <CheckCircle2 className="w-6 h-6" />
                      </div>
                      <div>
                        <div className="flex items-center space-x-2">
                          <h4 className="font-bold text-slate-800 text-sm">
                            Connected to {receivedManifest.deviceName}
                          </h4>
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800 uppercase">
                            Paired
                          </span>
                        </div>
                        <p className="text-xs text-slate-500 font-mono">
                          Endpoint: http://{receivedManifest.ip}:{receivedManifest.port} • Mode:{' '}
                          {receivedManifest.mode === 'direct_ap' ? 'Direct AP' : 'LAN Wi-Fi'}
                        </p>
                      </div>
                    </div>

                    <button
                      onClick={() => {
                        setReceivedManifest(null);
                        setActiveTransferMetrics(null);
                      }}
                      className="text-xs text-slate-400 hover:text-slate-700 font-semibold px-3 py-1.5 bg-slate-100 rounded-xl transition"
                    >
                      Disconnect
                    </button>
                  </div>

                  {/* Active Transfer Speedometer & Progress Card */}
                  {activeTransferMetrics && (
                    <div className="p-6 bg-slate-900 text-white rounded-3xl shadow-xl space-y-4">
                      <div className="flex items-center justify-between">
                        <div>
                          <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider block">
                            Live Stream Socket ({activeTransferMetrics.status})
                          </span>
                          <h4 className="font-bold text-base text-white">
                            {activeTransferMetrics.fileName}
                          </h4>
                        </div>
                        {/* Speedometer Badge */}
                        <div className="text-right">
                          <span className="text-2xl font-black font-mono text-emerald-400">
                            {P2PTransferEngine.formatSpeed(activeTransferMetrics.speedMBs)}
                          </span>
                          <span className="text-[10px] text-slate-400 block font-mono">
                            ETA: {P2PTransferEngine.formatEta(activeTransferMetrics.etaSeconds)}
                          </span>
                        </div>
                      </div>

                      {/* Progress Bar */}
                      <div className="w-full bg-slate-800 rounded-full h-3 overflow-hidden p-0.5 border border-slate-700">
                        <div
                          className="h-full rounded-full bg-gradient-to-r from-emerald-500 to-cyan-400 transition-all duration-150"
                          style={{ width: `${activeTransferMetrics.percent}%` }}
                        />
                      </div>

                      <div className="flex justify-between items-center text-xs text-slate-400 font-mono">
                        <span>
                          {P2PTransferEngine.formatBytes(activeTransferMetrics.transferredBytes)} /{' '}
                          {P2PTransferEngine.formatBytes(activeTransferMetrics.fileSize)}
                        </span>
                        <span>{activeTransferMetrics.percent}%</span>
                      </div>
                    </div>
                  )}

                  {/* Available Files in Manifest */}
                  <div className="bg-white rounded-3xl border border-slate-200/80 p-5 space-y-3">
                    <h5 className="font-bold text-slate-800 text-xs">
                      Available Files ({receivedManifest.files.length})
                    </h5>

                    <div className="space-y-2">
                      {receivedManifest.files.map((file) => {
                        const isDone = completedBlobs.has(file.id);
                        const isDownloading =
                          activeTransferMetrics?.fileId === file.id &&
                          activeTransferMetrics.status === 'transferring';

                        return (
                          <div
                            key={file.id}
                            className="flex items-center justify-between p-3.5 rounded-2xl bg-slate-50 border border-slate-100 transition hover:bg-slate-100/60"
                          >
                            <div className="flex items-center space-x-3">
                              <div className="w-9 h-9 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <FileText className="w-4 h-4" />
                              </div>
                              <div>
                                <span className="font-bold text-xs text-slate-800 block truncate max-w-[260px]">
                                  {file.name}
                                </span>
                                <span className="text-[10px] text-slate-400 font-mono">
                                  {P2PTransferEngine.formatBytes(file.size)}
                                </span>
                              </div>
                            </div>

                            <button
                              disabled={isDownloading}
                              onClick={() => handleDownloadFile(file)}
                              className={`px-4 py-2 rounded-xl font-bold text-xs flex items-center space-x-1.5 transition ${
                                isDone
                                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                  : isDownloading
                                  ? 'bg-slate-200 text-slate-500 cursor-not-allowed'
                                  : 'bg-emerald-600 hover:bg-emerald-500 text-white shadow-md shadow-emerald-500/20'
                              }`}
                            >
                              {isDone ? (
                                <>
                                  <CheckCircle2 className="w-3.5 h-3.5 text-emerald-600" />
                                  <span>Downloaded</span>
                                </>
                              ) : isDownloading ? (
                                <>
                                  <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                                  <span>Streaming...</span>
                                </>
                              ) : (
                                <>
                                  <Download className="w-3.5 h-3.5" />
                                  <span>Download (High Speed)</span>
                                </>
                              )}
                            </button>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* FOOTER */}
        <div className="px-6 py-3 bg-white border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-[11px]">
              Direct Socket Protocol • Zero Internet • Memory-Safe Binary Streaming
            </span>
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
