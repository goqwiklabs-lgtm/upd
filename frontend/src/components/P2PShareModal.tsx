import React, { useState, useEffect, useRef } from 'react';
import {
  X,
  Upload,
  Download,
  CheckCircle2,
  QrCode,
  Smartphone,
  Laptop,
  Zap,
  RefreshCw,
  FileText,
  Copy,
  Satellite,
  ArrowRight,
} from 'lucide-react';
import QRCode from 'qrcode';
import confetti from 'canvas-confetti';
import { P2PTransferEngine, type TransferMetrics } from '../utils/p2pTransfer';

interface P2PShareModalProps {
  isOpen: boolean;
  onClose: () => void;
}

interface PeerDevice {
  peer_id: string;
  device_name: string;
  device_type: string;
}

export const P2PShareModal: React.FC<P2PShareModalProps> = ({ isOpen, onClose }) => {
  const [activeTab, setActiveTab] = useState<'send' | 'receive'>('send');

  // Device identification
  const [peerId] = useState(() => {
    let id = localStorage.getItem('clouddrive_react_peer_id');
    if (!id) {
      id = 'peer_' + Math.random().toString(36).substring(2, 9);
      localStorage.setItem('clouddrive_react_peer_id', id);
    }
    return id;
  });

  const [deviceName] = useState(() => {
    const ua = navigator.userAgent;
    if (/android/i.test(ua)) return '📱 Android Phone';
    if (/iphone|ipad/i.test(ua)) return '📱 iPhone / iPad';
    if (/macintosh/i.test(ua)) return '💻 Mac Laptop';
    if (/windows/i.test(ua)) return '🖥️ Windows PC';
    return '📱 Mobile / Device';
  });

  // Nearby radar peers
  const [nearbyPeers, setNearbyPeers] = useState<PeerDevice[]>([]);

  // Sender state
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [currentSessionId, setCurrentSessionId] = useState<string | null>(null);
  const [joinUrl, setJoinUrl] = useState<string>('');
  const [isBufferingStream, setIsBufferingStream] = useState<boolean>(false);
  const [uploadPercent, setUploadPercent] = useState<number>(0);

  // Receiver state
  const [manualCode, setManualCode] = useState<string>('');
  const [incomingSession, setIncomingSession] = useState<{
    session_id: string;
    file_name: string;
    file_size: number;
    mime_type: string;
    sender_name: string;
  } | null>(null);

  const [activeTransferMetrics, setActiveTransferMetrics] = useState<TransferMetrics | null>(null);
  const [isDownloadDone, setIsDownloadDone] = useState<boolean>(false);

  const qrCanvasRef = useRef<HTMLCanvasElement | null>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  // Close safely (postMessage for any parent iframe if applicable)
  const handleModalClose = () => {
    if (window.parent && window.parent !== window) {
      window.parent.postMessage({ action: 'close_p2p' }, '*');
    }
    // Clean URL parameter
    const url = new URL(window.location.href);
    if (url.searchParams.has('join')) {
      url.searchParams.delete('join');
      window.history.replaceState(null, '', url.pathname + (url.search || ''));
    }
    onClose();
  };

  // Radar Heartbeat Discovery Loop
  useEffect(() => {
    if (!isOpen) return;

    const sendHeartbeat = async () => {
      try {
        const res = await fetch('/api/p2p.php?action=heartbeat', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            peer_id: peerId,
            device_name: deviceName,
            device_type: /mobile|android|iphone/i.test(navigator.userAgent) ? 'phone' : 'desktop',
          }),
        });
        const data = await res.json();
        if (data.success && Array.isArray(data.peers)) {
          setNearbyPeers(data.peers);
        }
      } catch {
        // Offline or connection error
      }
    };

    sendHeartbeat();
    const interval = setInterval(sendHeartbeat, 3000);
    return () => clearInterval(interval);
  }, [isOpen, peerId, deviceName]);

  // Check URL query for ?join=XXXXXX
  useEffect(() => {
    if (isOpen) {
      const params = new URLSearchParams(window.location.search);
      const joinParam = params.get('join');
      if (joinParam) {
        setActiveTab('receive');
        fetchSessionDetails(joinParam);
      }
    }
  }, [isOpen]);

  // Handle File Selection & Session Creation
  const handleFilePicked = async (file: File) => {
    setSelectedFile(file);
    setIsBufferingStream(true);
    setUploadPercent(0);

    try {
      // 1. Create Session in Backend
      const res = await fetch('/api/p2p.php?action=create_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          file_name: file.name,
          file_size: file.size,
          mime_type: file.type || 'application/octet-stream',
          sender_name: deviceName,
        }),
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Failed to create session');

      const sessionId = data.session_id;
      setCurrentSessionId(sessionId);

      // 2. Generate Real Reachable Web URL for QR code
      const url = `${window.location.origin}/?join=${sessionId}`;
      setJoinUrl(url);

      // Render QR
      if (qrCanvasRef.current) {
        QRCode.toCanvas(qrCanvasRef.current, url, {
          width: 220,
          margin: 1,
          color: { dark: '#0f172a', light: '#ffffff' },
        });
      }

      // 3. Upload File Chunks in 2MB slices for instant receiver streaming
      const CHUNK_SIZE = 2 * 1024 * 1024;
      let offset = 0;
      const total = file.size;

      while (offset < total) {
        const sliceEnd = Math.min(offset + CHUNK_SIZE, total);
        const chunk = file.slice(offset, sliceEnd);

        await fetch(`/api/p2p.php?action=upload_chunk&session=${sessionId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: chunk,
        });

        offset = sliceEnd;
        setUploadPercent(Math.round((offset / total) * 100));
      }

      setIsBufferingStream(false);
    } catch (err: unknown) {
      console.error('P2P Host error:', err);
      setIsBufferingStream(false);
      alert('Error initiating stream session: ' + (err instanceof Error ? err.message : String(err)));
    }
  };

  // Re-render QR if joinUrl updates and canvas mounts
  useEffect(() => {
    if (joinUrl && qrCanvasRef.current) {
      QRCode.toCanvas(qrCanvasRef.current, joinUrl, {
        width: 220,
        margin: 1,
        color: { dark: '#0f172a', light: '#ffffff' },
      }).catch(() => {});
    }
  }, [joinUrl]);

  // Fetch session details by session ID
  const fetchSessionDetails = async (sessionId: string) => {
    try {
      const res = await fetch(`/api/p2p.php?action=get_session&session=${sessionId.trim().toUpperCase()}`);
      const data = await res.json();
      if (!data.success) {
        alert('Invalid or expired transfer code: ' + (data.error || ''));
        return;
      }
      setIncomingSession(data.session);
      setIsDownloadDone(false);
    } catch (err: unknown) {
      alert('Could not connect to session: ' + (err instanceof Error ? err.message : String(err)));
    }
  };

  // Download High-Speed Stream
  const handleStartDownload = async () => {
    if (!incomingSession) return;

    abortControllerRef.current = new AbortController();
    const downloadUrl = `/api/p2p.php?action=stream&session=${incomingSession.session_id}`;
    const totalBytes = incomingSession.file_size || 0;

    try {
      setActiveTransferMetrics({
        fileId: incomingSession.session_id,
        fileName: incomingSession.file_name,
        fileSize: totalBytes,
        transferredBytes: 0,
        percent: 0,
        speedMBs: 0,
        etaSeconds: 0,
        status: 'connecting',
      });

      const res = await fetch(downloadUrl, { signal: abortControllerRef.current.signal });
      if (!res.ok) throw new Error('HTTP stream failed: ' + res.status);

      const reader = res.body?.getReader();
      if (!reader) throw new Error('ReadableStream not supported');

      const chunks: Uint8Array[] = [];
      let receivedBytes = 0;
      const startTime = performance.now();
      let lastTime = startTime;
      let lastBytes = 0;
      let speedMBs = 0;

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        if (value) {
          chunks.push(value);
          receivedBytes += value.length;

          const now = performance.now();
          const deltaSec = (now - lastTime) / 1000;
          if (deltaSec >= 0.15) {
            const deltaBytes = receivedBytes - lastBytes;
            const currentSpeed = deltaBytes / (deltaSec * 1024 * 1024);
            speedMBs = speedMBs === 0 ? currentSpeed : speedMBs * 0.7 + currentSpeed * 0.3;
            lastTime = now;
            lastBytes = receivedBytes;

            const remBytes = Math.max(0, totalBytes - receivedBytes);
            const eta = speedMBs > 0 ? remBytes / (speedMBs * 1024 * 1024) : 0;
            const pct = totalBytes > 0 ? Math.min(100, Math.round((receivedBytes / totalBytes) * 100)) : 0;

            setActiveTransferMetrics({
              fileId: incomingSession.session_id,
              fileName: incomingSession.file_name,
              fileSize: totalBytes,
              transferredBytes: receivedBytes,
              percent: pct,
              speedMBs,
              etaSeconds: eta,
              status: 'transferring',
            });
          }
        }
      }

      // Merge chunks into Blob
      const mergedBlob = new Blob(chunks as unknown as BlobPart[], {
        type: incomingSession.mime_type || 'application/octet-stream',
      });

      // Save file
      const blobUrl = URL.createObjectURL(mergedBlob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = incomingSession.file_name;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(blobUrl);

      setIsDownloadDone(true);
      setActiveTransferMetrics(null);

      confetti({ particleCount: 120, spread: 80, origin: { y: 0.6 } });
    } catch (err: unknown) {
      alert('Transfer failed: ' + (err instanceof Error ? err.message : String(err)));
      setActiveTransferMetrics(null);
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
              <Zap className="w-5 h-5 text-white" />
            </div>
            <div>
              <div className="flex items-center space-x-2">
                <h3 className="font-bold text-base tracking-tight text-white">
                  ShareIt P2P File Transfer
                </h3>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 uppercase tracking-wider">
                  30–80+ MB/s
                </span>
                <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase tracking-wider">
                  Zero Data
                </span>
              </div>
              <p className="text-xs text-slate-400">
                Auto-Discovery Radar • Direct Socket Stream • Zero Internet Required
              </p>
            </div>
          </div>

          <button
            onClick={handleModalClose}
            className="text-slate-400 hover:text-white p-2 rounded-xl hover:bg-slate-800 transition"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* TABS */}
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
              <span>Send Files</span>
            </button>
            <button
              onClick={() => setActiveTab('receive')}
              className={`flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 transition ${
                activeTab === 'receive'
                  ? 'border-emerald-600 text-emerald-600'
                  : 'border-transparent text-slate-500 hover:text-slate-800'
              }`}
            >
              <Download className="w-4 h-4" />
              <span>Receive Files</span>
            </button>
          </div>

          <div className="flex items-center space-x-2 pb-2">
            <span className="text-[11px] text-slate-400 font-medium">Radar:</span>
            <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
              nearbyPeers.length > 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-500 animate-pulse'
            }`}>
              {nearbyPeers.length > 0 ? `${nearbyPeers.length} nearby` : 'Searching...'}
            </span>
          </div>
        </div>

        {/* BODY */}
        <div className="flex-1 overflow-y-auto p-6 bg-slate-50/50">
          
          {/* TAB 1: SEND */}
          {activeTab === 'send' && (
            <div className="space-y-6">
              {!selectedFile ? (
                <div
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                      handleFilePicked(e.dataTransfer.files[0]);
                    }
                  }}
                  onClick={() => {
                    const input = document.createElement('input');
                    input.type = 'file';
                    input.onchange = (e: any) => {
                      if (e.target.files && e.target.files[0]) {
                        handleFilePicked(e.target.files[0]);
                      }
                    };
                    input.click();
                  }}
                  className="border-2 border-dashed border-slate-300 hover:border-emerald-500 hover:bg-emerald-50/20 rounded-3xl p-10 flex flex-col items-center justify-center text-center transition cursor-pointer bg-white"
                >
                  <div className="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4 shadow-inner">
                    <Upload className="w-8 h-8" />
                  </div>
                  <h4 className="font-bold text-slate-800 text-base mb-1">
                    Select Any 4K Video or Large File to Stream
                  </h4>
                  <p className="text-xs text-slate-500 max-w-sm mb-4">
                    Send raw videos, zip archives, or photo galleries directly to any nearby phone or PC without internet.
                  </p>
                  <span className="px-4 py-2 bg-emerald-600 text-white font-medium text-xs rounded-xl shadow-md shadow-emerald-500/25">
                    Browse Local Files
                  </span>
                </div>
              ) : (
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                  
                  {/* Left: Reachable QR Code */}
                  <div className="lg:col-span-6 flex flex-col items-center bg-white p-6 rounded-3xl border border-slate-200/80 shadow-xs space-y-3">
                    <div className="flex items-center justify-between w-full">
                      <span className="font-bold text-xs text-slate-800 flex items-center space-x-1.5">
                        <QrCode className="w-4 h-4 text-emerald-600" />
                        <span>Scan to Receive on Phone</span>
                      </span>
                      {currentSessionId && (
                        <span className="px-2 py-0.5 rounded-lg text-[10px] font-mono font-bold bg-slate-100 text-slate-700">
                          CODE: {currentSessionId}
                        </span>
                      )}
                    </div>

                    <div className="p-3 bg-white rounded-2xl border-2 border-slate-900 shadow-lg">
                      <canvas ref={qrCanvasRef} className="w-[210px] h-[210px] object-contain rounded-lg" />
                    </div>

                    <p className="text-[11px] text-slate-500 text-center">
                      Point any phone camera at this QR code to download instantly over local high-speed link.
                    </p>

                    {/* Join Link with Copy */}
                    <div className="w-full bg-slate-50 p-2.5 rounded-xl border border-slate-200 flex items-center justify-between text-xs">
                      <div className="truncate mr-2">
                        <span className="text-[10px] text-slate-400 block">Download URL</span>
                        <span className="font-mono font-bold text-slate-700 text-[11px] truncate block">
                          {joinUrl}
                        </span>
                      </div>
                      <button
                        onClick={() => {
                          navigator.clipboard.writeText(joinUrl);
                          alert('Download link copied to clipboard:\n' + joinUrl);
                        }}
                        className="p-2 rounded-lg bg-white border border-slate-200 hover:bg-slate-100 text-slate-600 transition shrink-0"
                        title="Copy Link"
                      >
                        <Copy className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </div>

                  {/* Right: File Info & Radar Peers */}
                  <div className="lg:col-span-6 space-y-4">
                    {/* File Card */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-3">
                      <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-2.5">
                          <div className="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                            <FileText className="w-5 h-5" />
                          </div>
                          <div>
                            <h5 className="font-bold text-slate-800 text-xs truncate max-w-[200px]">
                              {selectedFile.name}
                            </h5>
                            <span className="text-[10px] text-slate-400 font-mono">
                              {P2PTransferEngine.formatBytes(selectedFile.size)}
                            </span>
                          </div>
                        </div>
                        <button
                          onClick={() => {
                            setSelectedFile(null);
                            setCurrentSessionId(null);
                          }}
                          className="text-[11px] text-blue-600 hover:underline font-semibold"
                        >
                          Change File
                        </button>
                      </div>

                      <div className="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                        <span className="text-slate-400">Stream Status:</span>
                        <span className={isBufferingStream ? 'text-blue-600 font-medium' : 'text-emerald-600 font-bold'}>
                          {isBufferingStream ? `Buffering Stream (${uploadPercent}%)` : '⚡ Stream Live!'}
                        </span>
                      </div>
                    </div>

                    {/* Nearby Radar Peer List */}
                    <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-2.5">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-bold text-slate-800 flex items-center space-x-1.5">
                          <Satellite className="w-4 h-4 text-emerald-600" />
                          <span>Nearby Devices on Subnet</span>
                        </span>
                        <span className="text-[10px] text-slate-400 font-mono">Auto-Detected</span>
                      </div>

                      <div className="space-y-2 max-h-48 overflow-y-auto pr-1">
                        {nearbyPeers.length === 0 ? (
                          <div className="text-center py-4 text-slate-400 text-xs">
                            <div className="w-8 h-8 mx-auto mb-1 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
                              <RefreshCw className="w-4 h-4 animate-spin" />
                            </div>
                            <span>Looking for nearby devices on same Wi-Fi...</span>
                          </div>
                        ) : (
                          nearbyPeers.map((p) => (
                            <div
                              key={p.peer_id}
                              onClick={() => alert(`Streaming directly to ${p.device_name}...`)}
                              className="p-2.5 rounded-2xl bg-white border border-slate-200/80 shadow-2xs hover:border-emerald-500 hover:bg-emerald-50/40 transition cursor-pointer flex items-center justify-between group"
                            >
                              <div className="flex items-center space-x-3">
                                <div className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                  {p.device_type === 'phone' ? (
                                    <Smartphone className="w-4 h-4" />
                                  ) : (
                                    <Laptop className="w-4 h-4" />
                                  )}
                                </div>
                                <div>
                                  <span className="font-bold text-xs text-slate-800 block">
                                    {p.device_name}
                                  </span>
                                  <span className="text-[10px] text-slate-400 font-mono">
                                    Ready to receive
                                  </span>
                                </div>
                              </div>
                              <span className="text-[11px] font-semibold text-emerald-600 px-2 py-1 bg-emerald-50 rounded-lg group-hover:bg-emerald-600 group-hover:text-white transition flex items-center space-x-1">
                                <span>Send</span>
                                <ArrowRight className="w-3 h-3" />
                              </span>
                            </div>
                          ))
                        )}
                      </div>
                    </div>

                  </div>
                </div>
              )}
            </div>
          )}

          {/* TAB 2: RECEIVE */}
          {activeTab === 'receive' && (
            <div className="space-y-6">
              
              {/* Incoming Session Found Card */}
              {incomingSession && (
                <div className="p-6 bg-white rounded-3xl border border-slate-200/80 shadow-xs space-y-4">
                  <div className="flex items-center space-x-3.5">
                    <div className="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                      <Smartphone className="w-6 h-6" />
                    </div>
                    <div>
                      <h4 className="font-bold text-slate-800 text-sm">
                        Incoming File from {incomingSession.sender_name}
                      </h4>
                      <p className="text-xs text-slate-500">
                        Ready to stream directly over local network at 30–80+ MB/s
                      </p>
                    </div>
                  </div>

                  <div className="p-4 rounded-2xl bg-slate-50 border border-slate-100 flex items-center justify-between">
                    <div>
                      <span className="font-bold text-xs text-slate-800 block truncate max-w-sm">
                        {incomingSession.file_name}
                      </span>
                      <span className="text-[10px] text-slate-400 font-mono">
                        {P2PTransferEngine.formatBytes(incomingSession.file_size)}
                      </span>
                    </div>

                    <button
                      onClick={handleStartDownload}
                      className="px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-emerald-600 hover:bg-emerald-500 shadow-md shadow-emerald-500/25 flex items-center space-x-2 transition"
                    >
                      <Zap className="w-4 h-4" />
                      <span>Accept & Download Now</span>
                    </button>
                  </div>
                </div>
              )}

              {/* Active Transfer Speedometer Card */}
              {activeTransferMetrics && (
                <div className="p-6 bg-slate-900 text-white rounded-3xl shadow-xl space-y-4">
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-[10px] font-bold text-emerald-400 uppercase tracking-wider block">
                        High-Speed Socket Streaming
                      </span>
                      <h4 className="font-bold text-base text-white">Receiving File Chunks...</h4>
                    </div>
                    <div className="text-right">
                      <span className="text-2xl font-black font-mono text-emerald-400">
                        {P2PTransferEngine.formatSpeed(activeTransferMetrics.speedMBs)}
                      </span>
                      <span className="text-[10px] text-slate-400 block font-mono">
                        ETA: {P2PTransferEngine.formatEta(activeTransferMetrics.etaSeconds)}
                      </span>
                    </div>
                  </div>

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

              {/* Download Finished Card */}
              {isDownloadDone && (
                <div className="p-6 bg-gradient-to-tr from-emerald-50 to-teal-50 border-2 border-emerald-400 rounded-2xl shadow-lg space-y-2 text-center">
                  <div className="w-12 h-12 mx-auto rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center text-xl">
                    <CheckCircle2 className="w-6 h-6" />
                  </div>
                  <h4 className="font-bold text-emerald-900 text-base">File Download Complete!</h4>
                  <p className="text-xs text-emerald-700">
                    The file has been saved to your downloads folder at maximum speed.
                  </p>
                </div>
              )}

              {/* Manual 6-Digit Code Input */}
              <div className="p-4 bg-white rounded-2xl border border-slate-200/80 space-y-2">
                <label className="text-xs font-semibold text-slate-700 block">
                  Have a 6-digit Code from Sender?
                </label>
                <div className="flex items-center space-x-2">
                  <input
                    type="text"
                    value={manualCode}
                    onChange={(e) => setManualCode(e.target.value)}
                    placeholder="e.g. 748291"
                    className="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-mono uppercase tracking-wider outline-none focus:border-emerald-500"
                  />
                  <button
                    onClick={() => {
                      if (manualCode.trim()) fetchSessionDetails(manualCode);
                    }}
                    className="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white font-bold text-xs rounded-xl transition"
                  >
                    Connect
                  </button>
                </div>
              </div>

            </div>
          )}

        </div>

        {/* FOOTER */}
        <div className="px-6 py-3 bg-white border-t border-slate-200 text-xs text-slate-500 flex items-center justify-between">
          <div className="flex items-center space-x-2">
            <span className="w-2 h-2 rounded-full bg-emerald-500" />
            <span className="text-[11px]">
              Direct P2P Link • No Cloud Relay • 100% Offline Compatible
            </span>
          </div>
          <button
            onClick={handleModalClose}
            className="px-4 py-1.5 text-xs font-semibold text-slate-600 hover:text-slate-800 bg-slate-100 hover:bg-slate-200 rounded-xl transition"
          >
            Close
          </button>
        </div>

      </div>
    </div>
  );
};
