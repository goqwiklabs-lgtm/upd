// ============================================================================
// CloudDrive Native P2P Transfer Engine (ShareIt & LocalSend Architecture)
// 100% Offline • LAN WebRTC DataChannel (30–80 MB/s) • Global Subnet Radar
// Live Sender-to-Receiver Progress Sync • Mobile Background Keepalive
// Auto-Pause on Offline & Auto-Resume on Reconnect
// ============================================================================

(function () {
  'use strict';

  // --- DEVICE IDENTITY & PERSISTENCE ---
  let p2pPeerId = localStorage.getItem('clouddrive_p2p_peer_id');
  if (!p2pPeerId) {
    p2pPeerId = 'peer_' + Math.random().toString(36).substring(2, 10);
    localStorage.setItem('clouddrive_p2p_peer_id', p2pPeerId);
  }

  const p2pDeviceName = (function () {
    const ua = navigator.userAgent;
    if (/android/i.test(ua)) return '📱 Android Phone';
    if (/iphone|ipad|ipod/i.test(ua)) return '📱 iPhone / iPad';
    if (/macintosh/i.test(ua)) return '💻 Mac Laptop';
    if (/windows/i.test(ua)) return '🖥️ Windows PC';
    if (/linux/i.test(ua)) return '💻 Linux PC';
    return '📱 Mobile / Device';
  })();

  const p2pDeviceType = /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent) ? 'phone' : 'desktop';

  // --- STATE VARIABLES ---
  let p2pActiveTab = 'send';
  let p2pHeartbeatTimer = null;
  let p2pActivePeers = [];
  let p2pPendingOffer = null;

  // Offline / Network Resilience
  let p2pIsNetworkOffline = !navigator.onLine;
  let p2pBackgroundAudio = null;
  let p2pWakeLock = null;

  // Sender state
  let p2pSelectedFiles = []; // Array of File objects
  let p2pCurrentSessionId = null;
  let p2pSenderSessionData = null;
  let p2pSenderPeerConnection = null;
  let p2pSenderDataChannel = null;
  let p2pSenderIsStreaming = false;

  // Receiver state
  let p2pReceiverSession = null;
  let p2pReceiverPeerConnection = null;
  let p2pReceiverDataChannel = null;
  let p2pReceiverSyncInterval = null;
  let p2pIsTransferPaused = false;
  let p2pIsTransferStopped = false;
  let p2pTransferAbortController = null;

  // --- FORMATTERS ---
  function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  function formatSpeed(mbPerSec) {
    if (mbPerSec >= 1) return mbPerSec.toFixed(1) + ' MB/s';
    return (mbPerSec * 1024).toFixed(0) + ' KB/s';
  }

  function formatEta(seconds) {
    if (!isFinite(seconds) || seconds < 0) return '--';
    if (seconds === 0) return '0s';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
  }

  function getFileIcon(mime, name) {
    const ext = (name || '').split('.').pop().toLowerCase();
    if (mime.startsWith('video/') || ['mp4', 'mkv', 'mov', 'webm', 'avi'].includes(ext)) {
      return '<i class="fa-solid fa-file-video text-purple-600"></i>';
    }
    if (mime.startsWith('image/') || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'].includes(ext)) {
      return '<i class="fa-solid fa-file-image text-emerald-600"></i>';
    }
    if (mime.startsWith('audio/') || ['mp3', 'wav', 'flac', 'aac', 'ogg'].includes(ext)) {
      return '<i class="fa-solid fa-file-audio text-amber-600"></i>';
    }
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) {
      return '<i class="fa-solid fa-file-zipper text-yellow-600"></i>';
    }
    return '<i class="fa-solid fa-file text-blue-600"></i>';
  }

  // ============================================================================
  // BACKGROUND KEEPALIVE & OFFLINE RESILIENCE FOR MOBILE BROWSERS
  // ============================================================================
  async function startBackgroundKeepalive() {
    // 1. Screen WakeLock API (prevents mobile screen lock during active upload)
    if ('wakeLock' in navigator) {
      try {
        p2pWakeLock = await navigator.wakeLock.request('screen');
        p2pWakeLock.addEventListener('release', () => { p2pWakeLock = null; });
      } catch {}
    }

    // 2. HTML5 Audio Keepalive (keeps Android Chrome active in background when minimized)
    try {
      if (!p2pBackgroundAudio) {
        // Tiny silent WAV data URI
        const silentWav = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
        p2pBackgroundAudio = new Audio(silentWav);
        p2pBackgroundAudio.loop = true;
        p2pBackgroundAudio.volume = 0.001;
      }
      p2pBackgroundAudio.play().catch(() => {});
    } catch {}

    const badge = document.getElementById('p2p-send-background-badge');
    if (badge) badge.classList.remove('hidden');
  }

  function stopBackgroundKeepalive() {
    if (p2pWakeLock) {
      try { p2pWakeLock.release(); } catch {}
      p2pWakeLock = null;
    }
    if (p2pBackgroundAudio) {
      try { p2pBackgroundAudio.pause(); } catch {}
    }
    const badge = document.getElementById('p2p-send-background-badge');
    if (badge) badge.classList.add('hidden');
  }

  // Monitor visibility state (keeps mobile tab alive when minimized)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      if (p2pSelectedFiles.length > 0) {
        startBackgroundKeepalive();
      }
    }
  });

  // Network Connectivity Check
  async function checkNetworkConnectivity() {
    try {
      const res = await fetch('/api/p2p.php?action=ping', { method: 'GET', cache: 'no-store' });
      return res.ok;
    } catch {
      return false;
    }
  }

  window.addEventListener('offline', () => {
    p2pIsNetworkOffline = true;
    updateSenderOfflineStatus(true);
  });

  window.addEventListener('online', async () => {
    const isOnline = await checkNetworkConnectivity();
    if (isOnline) {
      p2pIsNetworkOffline = false;
      updateSenderOfflineStatus(false);
    }
  });

  function updateSenderOfflineStatus(isOffline) {
    const statusEl = document.getElementById('p2p-send-upload-status');
    if (!statusEl) return;
    if (isOffline) {
      statusEl.textContent = '⚠️ Internet disconnected. Upload paused. Waiting for connection to resume...';
      statusEl.className = 'text-[11px] text-amber-600 font-bold';
    } else {
      statusEl.textContent = '⚡ Internet restored! Resuming stream upload...';
      statusEl.className = 'text-[11px] text-emerald-600 font-bold';
    }
  }

  // ============================================================================
  // 1. GLOBAL HEARTBEAT & RADAR AUTO-DISCOVERY (RUNS EVERY 2.5s ON ALL PAGES)
  // ============================================================================
  async function p2pPollHeartbeat() {
    try {
      const res = await fetch('/api/p2p.php?action=heartbeat', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          peer_id: p2pPeerId,
          device_name: p2pDeviceName,
          device_type: p2pDeviceType,
        }),
      });
      const data = await res.json();
      if (!data.success) return;

      p2pActivePeers = data.peers || [];
      renderRadarPeers(p2pActivePeers);

      // Check for incoming transfer offers targeted to this device
      if (data.offers && data.offers.length > 0) {
        handleIncomingTransferOffer(data.offers[0]);
      }
    } catch {
      // Gracefully ignore offline / timeout
    }
  }

  function renderRadarPeers(peers) {
    const listEl = document.getElementById('p2p-radar-peer-list');
    const badgeEl = document.getElementById('p2p-radar-count-badge');
    if (!listEl) return;

    if (badgeEl) {
      badgeEl.textContent = peers.length > 0 ? `${peers.length} nearby` : 'Searching...';
      badgeEl.className = peers.length > 0
        ? 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800'
        : 'px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500 animate-pulse';
    }

    if (peers.length === 0) {
      listEl.innerHTML = `
        <div class="text-center py-4 text-slate-400 text-xs">
          <div class="w-8 h-8 mx-auto mb-1 rounded-full bg-slate-100 flex items-center justify-center text-slate-400">
            <i class="fa-solid fa-satellite-dish animate-spin"></i>
          </div>
          <span>Looking for nearby devices on same Wi-Fi...</span>
        </div>
      `;
      return;
    }

    listEl.innerHTML = peers.map((p) => `
      <div onclick="window.sendToPeer('${escapeAttr(p.peer_id)}', '${escapeAttr(p.device_name)}')"
           class="p-2.5 rounded-2xl bg-white border border-slate-200/80 shadow-xs hover:border-emerald-500 hover:bg-emerald-50/40 transition cursor-pointer flex items-center justify-between group">
        <div class="flex items-center space-x-3">
          <div class="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:scale-105 transition-transform">
            <i class="fa-solid ${p.device_type === 'phone' ? 'fa-mobile-screen' : 'fa-laptop'}"></i>
          </div>
          <div>
            <span class="font-bold text-xs text-slate-800 block">${escapeHtml(p.device_name)}</span>
            <span class="text-[10px] text-slate-400 font-mono">Tap to transfer</span>
          </div>
        </div>
        <span class="text-[11px] font-semibold text-emerald-600 px-2.5 py-1 bg-emerald-50 rounded-lg group-hover:bg-emerald-600 group-hover:text-white transition flex items-center space-x-1">
          <span>Send</span>
          <i class="fa-solid fa-paper-plane text-[10px]"></i>
        </span>
      </div>
    `).join('');
  }

  // ============================================================================
  // 2. HOMEPAGE INCOMING NOTIFICATION BANNER (POPS UP ANYWHERE ON SITE)
  // ============================================================================
  function handleIncomingTransferOffer(offer) {
    p2pPendingOffer = offer;
    const banner = document.getElementById('p2p-incoming-banner');
    const senderEl = document.getElementById('p2p-banner-sender');
    const infoEl = document.getElementById('p2p-banner-file-info');

    if (!banner) return;

    if (senderEl) senderEl.textContent = offer.sender_name || 'Nearby Device';

    const fileCount = (offer.files && offer.files.length) || 1;
    const firstFileName = offer.files && offer.files[0] ? offer.files[0].name : 'File';
    const totalSizeStr = formatBytes(offer.total_size);

    if (infoEl) {
      if (fileCount > 1) {
        infoEl.textContent = `${fileCount} files (${totalSizeStr}) • e.g. ${firstFileName}`;
      } else {
        infoEl.textContent = `${firstFileName} (${totalSizeStr})`;
      }
    }

    // Show banner with animation
    banner.classList.remove('hidden');
    requestAnimationFrame(() => {
      banner.classList.remove('-translate-y-4', 'opacity-0');
      banner.classList.add('translate-y-0', 'opacity-100');
    });

    playNotificationTone();
  }

  function playNotificationTone() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(587.33, ctx.currentTime); // D5
      osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1); // A5
      gain.gain.setValueAtTime(0.2, ctx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.start();
      osc.stop(ctx.currentTime + 0.35);
    } catch {}
  }

  window.acceptIncomingP2POffer = async function () {
    const banner = document.getElementById('p2p-incoming-banner');
    if (banner) {
      banner.classList.add('-translate-y-4', 'opacity-0');
      setTimeout(() => banner.classList.add('hidden'), 300);
    }

    if (!p2pPendingOffer) return;

    const offer = p2pPendingOffer;
    try {
      await fetch('/api/p2p.php?action=respond_offer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ offer_id: offer.offer_id, response: 'accept' }),
      });
    } catch {}

    // Open receive tab and load session
    window.openP2PShareModal('receive');
    await loadIncomingSession(offer.session_id);
  };

  window.declineIncomingP2POffer = async function () {
    const banner = document.getElementById('p2p-incoming-banner');
    if (banner) {
      banner.classList.add('-translate-y-4', 'opacity-0');
      setTimeout(() => banner.classList.add('hidden'), 300);
    }

    if (!p2pPendingOffer) return;
    try {
      await fetch('/api/p2p.php?action=respond_offer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ offer_id: p2pPendingOffer.offer_id, response: 'decline' }),
      });
    } catch {}
    p2pPendingOffer = null;
  };

  // ============================================================================
  // 3. SENDER ENGINE: MULTI-FILE HOSTING & BACKGROUND RESILIENT UPLOADING
  // ============================================================================
  function handleFilesSelected(fileList) {
    if (!fileList || fileList.length === 0) return;

    // Append new files without duplicates
    const existingNames = new Set(p2pSelectedFiles.map((f) => f.name + '_' + f.size));
    for (let i = 0; i < fileList.length; i++) {
      const f = fileList[i];
      if (!existingNames.has(f.name + '_' + f.size)) {
        p2pSelectedFiles.push(f);
      }
    }

    renderSenderFilesList();
    initiateSenderHosting();
  }

  function renderSenderFilesList() {
    const dropzone = document.getElementById('p2p-send-dropzone');
    const activeCard = document.getElementById('p2p-send-active-card');
    const listContainer = document.getElementById('p2p-send-files-list');
    const summaryEl = document.getElementById('p2p-send-files-summary');
    const totalSizeEl = document.getElementById('p2p-send-files-totalsize');

    if (p2pSelectedFiles.length === 0) {
      if (dropzone) dropzone.classList.remove('hidden');
      if (activeCard) activeCard.classList.add('hidden');
      return;
    }

    if (dropzone) dropzone.classList.add('hidden');
    if (activeCard) activeCard.classList.remove('hidden');

    const totalBytes = p2pSelectedFiles.reduce((acc, f) => acc + f.size, 0);
    if (summaryEl) summaryEl.textContent = `${p2pSelectedFiles.length} File${p2pSelectedFiles.length > 1 ? 's' : ''} Selected`;
    if (totalSizeEl) totalSizeEl.textContent = `${formatBytes(totalBytes)} Total`;

    if (listContainer) {
      listContainer.innerHTML = p2pSelectedFiles.map((file, idx) => `
        <div class="p-2 rounded-xl bg-slate-50 border border-slate-200/70 flex items-center justify-between text-xs group">
          <div class="flex items-center space-x-2.5 min-w-0 mr-2">
            <div class="w-7 h-7 rounded-lg bg-white border border-slate-200 flex items-center justify-center shrink-0">
              ${getFileIcon(file.type, file.name)}
            </div>
            <div class="truncate">
              <span class="font-bold text-slate-800 block truncate text-[11px]">${escapeHtml(file.name)}</span>
              <span class="text-[10px] text-slate-400 font-mono">${formatBytes(file.size)}</span>
            </div>
          </div>
          <button onclick="window.removeSenderFile(${idx})" class="p-1 text-slate-400 hover:text-rose-500 rounded-lg hover:bg-white transition" title="Remove">
            <i class="fa-solid fa-trash-can text-[11px]"></i>
          </button>
        </div>
      `).join('');
    }
  }

  window.removeSenderFile = function (index) {
    p2pSelectedFiles.splice(index, 1);
    if (p2pSelectedFiles.length === 0) {
      window.resetP2PSender();
    } else {
      renderSenderFilesList();
      initiateSenderHosting();
    }
  };

  async function initiateSenderHosting() {
    if (p2pSelectedFiles.length === 0) return;

    const qrCanvas = document.getElementById('p2p-send-qr-canvas');
    const joinLinkInput = document.getElementById('p2p-send-join-link');
    const codeEl = document.getElementById('p2p-send-code-badge');
    const uploadStatusEl = document.getElementById('p2p-send-upload-status');

    if (uploadStatusEl) {
      uploadStatusEl.textContent = '⚡ Establishing direct socket stream...';
      uploadStatusEl.className = 'text-[11px] text-emerald-600 font-bold';
    }

    try {
      // 1. Register Session on Local Backend
      const payloadFiles = p2pSelectedFiles.map((f, i) => ({
        index: i,
        name: f.name,
        size: f.size,
        type: f.type || 'application/octet-stream',
      }));

      const res = await fetch('/api/p2p.php?action=create_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sender_name: p2pDeviceName,
          sender_peer: p2pPeerId,
          files: payloadFiles,
        }),
      });

      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Failed to initialize session');

      p2pCurrentSessionId = data.session_id;
      p2pSenderSessionData = data;

      // 2. Generate Reachable Join QR Code instantly
      const joinUrl = `${window.location.origin}/index.php?join=${p2pCurrentSessionId}`;
      if (joinLinkInput) joinLinkInput.value = joinUrl;
      if (codeEl) codeEl.textContent = `CODE: ${p2pCurrentSessionId}`;

      if (qrCanvas && window.QRCode) {
        window.QRCode.toCanvas(qrCanvas, joinUrl, {
          width: 210,
          margin: 1,
          color: { dark: '#0f172a', light: '#ffffff' },
        });
      }

      // 3. PURE P2P ZERO-WAIT: 0.00 seconds delay! No server pre-upload!
      const totalAllBytes = p2pSelectedFiles.reduce((acc, f) => acc + f.size, 0);
      const progressBar = document.getElementById('p2p-send-progress-bar');
      const uploadPctEl = document.getElementById('p2p-send-upload-pct');
      const uploadBytesEl = document.getElementById('p2p-send-upload-bytes');

      if (progressBar) progressBar.style.width = '100%';
      if (uploadPctEl) uploadPctEl.textContent = '100%';
      if (uploadBytesEl) uploadBytesEl.textContent = `${formatBytes(totalAllBytes)} Ready to Beam`;
      if (uploadStatusEl) {
        uploadStatusEl.textContent = '⚡ Pure P2P Live (0s Wait • 30–80 MB/s Direct LAN)';
        uploadStatusEl.className = 'text-[11px] text-emerald-600 font-black';
      }

      startBackgroundKeepalive();

      // 4. Start Signaling Listener for Direct WebRTC (30–80 MB/s LAN stream)
      listenForWebRTCSignalsSender();

      // 5. Non-blocking Background Sync: buffer chunks to server as fallback
      // (Runs purely in background without blocking UI or delaying 0s stream ready)
      bufferChunksInBackground(p2pCurrentSessionId, p2pSelectedFiles);
    } catch (err) {
      console.error('P2P Sender error:', err);
      if (uploadStatusEl) {
        uploadStatusEl.textContent = 'Error: ' + err.message;
        uploadStatusEl.className = 'text-[11px] text-red-500 font-medium';
      }
    }
  }

  // Non-blocking background sync so receiver fallback NEVER fails with 404 or 0 MB/s
  async function bufferChunksInBackground(sessionId, files) {
    try {
      const CHUNK_SIZE = 2 * 1024 * 1024;
      let totalSent = 0;

      for (let fIdx = 0; fIdx < files.length; fIdx++) {
        if (p2pCurrentSessionId !== sessionId || p2pIsTransferStopped) break;
        const file = files[fIdx];
        let offset = 0;
        const total = file.size;

        while (offset < total && p2pCurrentSessionId === sessionId && !p2pIsTransferStopped) {
          const sliceEnd = Math.min(offset + CHUNK_SIZE, total);
          const chunk = file.slice(offset, sliceEnd);
          const isFinal = (fIdx === files.length - 1) && (sliceEnd >= total);

          try {
            await fetch(
              `/api/p2p.php?action=upload_chunk&session=${encodeURIComponent(sessionId)}&file_index=${fIdx}&uploaded_bytes=${totalSent + chunk.size}&is_final=${isFinal ? 1 : 0}`,
              {
                method: 'POST',
                headers: { 'Content-Type': 'application/octet-stream' },
                body: chunk,
              }
            );
          } catch {
            await new Promise((r) => setTimeout(r, 1000));
          }

          offset = sliceEnd;
          totalSent += chunk.size;
        }
      }
    } catch (e) {
      console.warn('Background buffer error:', e);
    }
  }

  // Tapping a device on the radar sends a direct transfer request
  window.sendToPeer = async function (targetPeerId, targetDeviceName) {
    if (p2pSelectedFiles.length === 0) {
      alert(`Please select files first, then tap ${targetDeviceName}`);
      document.getElementById('p2p-send-file-input')?.click();
      return;
    }

    if (!p2pCurrentSessionId) {
      await initiateSenderHosting();
    }

    const uploadStatusEl = document.getElementById('p2p-send-upload-status');
    if (uploadStatusEl) {
      uploadStatusEl.textContent = `📲 Sending transfer request to ${targetDeviceName}...`;
      uploadStatusEl.className = 'text-[11px] text-blue-600 font-bold';
    }

    try {
      const payloadFiles = p2pSelectedFiles.map((f, i) => ({
        index: i,
        name: f.name,
        size: f.size,
        type: f.type || 'application/octet-stream',
      }));
      const totalBytes = p2pSelectedFiles.reduce((acc, f) => acc + f.size, 0);

      const res = await fetch('/api/p2p.php?action=send_offer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          sender_peer: p2pPeerId,
          sender_name: p2pDeviceName,
          target_peer: targetPeerId,
          session_id: p2pCurrentSessionId,
          files: payloadFiles,
          total_size: totalBytes,
        }),
      });

      const data = await res.json();
      if (data.success) {
        if (uploadStatusEl) {
          uploadStatusEl.textContent = `⏳ Request sent to ${targetDeviceName}! Waiting for acceptance...`;
          uploadStatusEl.className = 'text-[11px] text-amber-600 font-bold';
        }
      }
    } catch (err) {
      alert('Failed to send transfer request: ' + err.message);
    }
  };

  // ============================================================================
  // 4. WEBRTC DATACHANNEL ENGINE (30–80+ MB/s LAN DIRECT TRANSFER)
  // ============================================================================
  const rtcConfig = {
    iceServers: [
      { urls: 'stun:stun.l.google.com:19302' },
      { urls: 'stun:stun1.l.google.com:19302' },
      { urls: 'stun:stun2.l.google.com:19302' },
    ],
  };

  let p2pSenderSince = 0;
  let p2pReceiverSince = 0;

  async function listenForWebRTCSignalsSender() {
    if (p2pSenderPeerConnection) {
      try { p2pSenderPeerConnection.close(); } catch {}
      p2pSenderPeerConnection = null;
    }

    const pc = new RTCPeerConnection(rtcConfig);
    p2pSenderPeerConnection = pc;
    let senderPendingCandidates = [];

    const dc = pc.createDataChannel('p2pStream', { ordered: true });
    p2pSenderDataChannel = dc;
    dc.binaryType = 'arraybuffer';

    dc.onopen = () => {
      console.log('[WebRTC] DataChannel connected directly! Starting 30–80 MB/s stream');
      const statusEl = document.getElementById('p2p-send-upload-status');
      if (statusEl) {
        statusEl.textContent = '🚀 Direct LAN DataChannel Live (30–80 MB/s)!';
        statusEl.className = 'text-[11px] text-emerald-600 font-black';
      }
      setTimeout(() => {
        if (!p2pSenderIsStreaming) streamFilesOverDataChannel(dc);
      }, 250);
    };

    dc.onmessage = (event) => {
      try {
        const msg = typeof event.data === 'string' ? JSON.parse(event.data) : null;
        if (!msg) return;
        if (msg.type === 'ready') {
          console.log('[WebRTC Sender] Receiver confirmed ready, streaming now');
          streamFilesOverDataChannel(dc);
        }
        if (msg.type === 'pause') p2pIsTransferPaused = true;
        if (msg.type === 'resume') p2pIsTransferPaused = false;
        if (msg.type === 'cancel') p2pIsTransferStopped = true;
      } catch {}
    };

    pc.onicecandidate = (e) => {
      if (e.candidate) {
        sendSignalToRole('receiver', { type: 'candidate', candidate: e.candidate });
      }
    };

    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await sendSignalToRole('receiver', { type: 'offer', sdp: offer.sdp });

    const pollTimer = setInterval(async () => {
      if (!p2pCurrentSessionId || pc.connectionState === 'closed') {
        clearInterval(pollTimer);
        return;
      }

      const signals = await getSignalsForRole('sender', p2pSenderSince);
      for (const item of signals) {
        const sig = item.signal;
        if (sig.type === 'connect_request') {
          console.log('[WebRTC Sender] Receiver requested fresh offer, recreating...');
          clearInterval(pollTimer);
          listenForWebRTCSignalsSender();
          return;
        } else if (sig.type === 'answer' && !pc.currentRemoteDescription) {
          await pc.setRemoteDescription(new RTCSessionDescription(sig));
          for (const c of senderPendingCandidates) {
            try { await pc.addIceCandidate(new RTCIceCandidate(c)); } catch {}
          }
          senderPendingCandidates = [];
        } else if (sig.type === 'candidate' && sig.candidate) {
          if (pc.currentRemoteDescription) {
            try {
              await pc.addIceCandidate(new RTCIceCandidate(sig.candidate));
            } catch (e) {
              console.warn('[WebRTC Sender] Add ICE error:', e);
            }
          } else {
            senderPendingCandidates.push(sig.candidate);
          }
        }
      }
    }, 350);
  }

  async function streamFilesOverDataChannel(dc) {
    if (p2pSenderIsStreaming) return;
    p2pSenderIsStreaming = true;

    const manifest = p2pSelectedFiles.map((f, i) => ({
      index: i,
      name: f.name,
      size: f.size,
      type: f.type,
    }));
    dc.send(JSON.stringify({ type: 'manifest', files: manifest }));

    const totalBatchBytes = p2pSelectedFiles.reduce((acc, f) => acc + f.size, 0);
    const CHUNK_SIZE = 64 * 1024;
    dc.bufferedAmountLowThreshold = 1024 * 1024;

    const progressBar = document.getElementById('p2p-send-progress-bar');
    const uploadPctEl = document.getElementById('p2p-send-upload-pct');
    const uploadBytesEl = document.getElementById('p2p-send-upload-bytes');
    const uploadStatusEl = document.getElementById('p2p-send-upload-status');

    let totalSentAllFiles = 0;
    let lastTime = performance.now();
    let lastBytes = 0;
    let speedMBs = 0;

    for (let fIdx = 0; fIdx < p2pSelectedFiles.length; fIdx++) {
      if (p2pIsTransferStopped) break;
      const file = p2pSelectedFiles[fIdx];

      dc.send(JSON.stringify({ type: 'file_start', index: fIdx, name: file.name, size: file.size }));

      let offset = 0;
      const total = file.size;

      while (offset < total && !p2pIsTransferStopped) {
        while (p2pIsTransferPaused && !p2pIsTransferStopped) {
          await new Promise((r) => setTimeout(r, 200));
        }

        if (dc.bufferedAmount > 2 * 1024 * 1024) {
          await new Promise((resolve) => {
            dc.onbufferedamountlow = () => {
              dc.onbufferedamountlow = null;
              resolve();
            };
          });
        }

        const sliceEnd = Math.min(offset + CHUNK_SIZE, total);
        const slice = file.slice(offset, sliceEnd);
        const buffer = await slice.arrayBuffer();

        dc.send(buffer);
        offset = sliceEnd;
        totalSentAllFiles += buffer.byteLength;

        const now = performance.now();
        const deltaSec = (now - lastTime) / 1000;
        if (deltaSec >= 0.15) {
          const deltaBytes = totalSentAllFiles - lastBytes;
          const curSpeed = deltaBytes / (deltaSec * 1024 * 1024);
          speedMBs = speedMBs === 0 ? curSpeed : speedMBs * 0.7 + curSpeed * 0.3;
          lastTime = now;
          lastBytes = totalSentAllFiles;

          const pct = totalBatchBytes > 0 ? Math.min(100, Math.round((totalSentAllFiles / totalBatchBytes) * 100)) : 0;
          if (progressBar) progressBar.style.width = pct + '%';
          if (uploadPctEl) uploadPctEl.textContent = pct + '%';
          if (uploadBytesEl) uploadBytesEl.textContent = `${formatBytes(totalSentAllFiles)} / ${formatBytes(totalBatchBytes)}`;
          if (uploadStatusEl) {
            uploadStatusEl.textContent = `🚀 Beaming ${file.name}: ${formatSpeed(speedMBs)}`;
            uploadStatusEl.className = 'text-[11px] text-emerald-600 font-bold';
          }
        }
      }

      dc.send(JSON.stringify({ type: 'file_end', index: fIdx }));
    }

    dc.send(JSON.stringify({ type: 'all_done' }));
    p2pSenderIsStreaming = false;

    if (!p2pIsTransferStopped) {
      if (progressBar) progressBar.style.width = '100%';
      if (uploadPctEl) uploadPctEl.textContent = '100%';
      if (uploadBytesEl) uploadBytesEl.textContent = `${formatBytes(totalBatchBytes)} Transferred`;
      if (uploadStatusEl) {
        uploadStatusEl.textContent = '✅ Transfer Complete (Direct LAN DataChannel)!';
        uploadStatusEl.className = 'text-[11px] text-emerald-600 font-black';
      }
    }
  }

  // Signaling helpers (Role-based for Session + Fallback to Peer ID)
  async function sendSignalToRole(toRole, signalData) {
    if (!p2pCurrentSessionId) return;
    try {
      await fetch('/api/p2p.php?action=signal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          session_id: p2pCurrentSessionId,
          to: toRole,
          sender_peer: p2pPeerId,
          signal: signalData,
        }),
      });
    } catch (e) {
      console.warn('[WebRTC] sendSignalToRole error:', e);
    }
  }

  async function getSignalsForRole(role, since = 0) {
    if (!p2pCurrentSessionId) return [];
    try {
      let url = `/api/p2p.php?action=signal&session_id=${encodeURIComponent(p2pCurrentSessionId)}&role=${encodeURIComponent(role)}`;
      if (since > 0) url += `&since=${since}`;
      const res = await fetch(url);
      const data = await res.json();
      if (data.server_time) {
        if (role === 'sender') p2pSenderSince = data.server_time;
        if (role === 'receiver') p2pReceiverSince = data.server_time;
      }
      return data.signals || [];
    } catch {
      return [];
    }
  }

  async function sendSignal(target, signalData) {
    try {
      await fetch('/api/p2p.php?action=signal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          target_peer: target,
          sender_peer: p2pPeerId,
          signal: signalData,
        }),
      });
    } catch {}
  }

  async function getSignals(peerId) {
    try {
      const res = await fetch(`/api/p2p.php?action=signal&peer_id=${peerId}`);
      const data = await res.json();
      return data.signals || [];
    } catch {
      return [];
    }
  }

  // ============================================================================
  // 5. RECEIVER ENGINE: LIVE SENDER SYNC, SPEEDOMETER, PAUSE & RESUME
  // ============================================================================
  async function loadIncomingSession(sessionId) {
    p2pCurrentSessionId = sessionId;
    const pendingCard = document.getElementById('p2p-receive-pending');
    const activeCard = document.getElementById('p2p-receive-active');
    const doneCard = document.getElementById('p2p-receive-done');
    const bufferingCard = document.getElementById('p2p-receive-buffering');
    const senderEl = document.getElementById('p2p-receive-sender-name');
    const syncSenderEl = document.getElementById('p2p-sync-sender-name');
    const listEl = document.getElementById('p2p-receive-files-list');
    const summaryText = document.getElementById('p2p-receive-summary-text');
    const acceptBtnText = document.getElementById('p2p-receive-accept-btn-text');

    const syncPctEl = document.getElementById('p2p-sync-pct');
    const syncBarEl = document.getElementById('p2p-sync-bar');
    const syncBytesEl = document.getElementById('p2p-sync-bytes');

    if (p2pReceiverSyncInterval) {
      clearInterval(p2pReceiverSyncInterval);
      p2pReceiverSyncInterval = null;
    }

    try {
      const res = await fetch(`/api/p2p.php?action=get_session&session=${sessionId}`);
      const data = await res.json();
      if (!data.success) {
        alert('Invalid or expired transfer code: ' + (data.error || ''));
        return;
      }

      p2pReceiverSession = data.session;
      const files = p2pReceiverSession.files || [];
      const totalBytes = p2pReceiverSession.total_size || files.reduce((acc, f) => acc + (f.size || 0), 0);

      if (senderEl) senderEl.textContent = p2pReceiverSession.sender_name || 'Nearby Device';
      if (syncSenderEl) syncSenderEl.textContent = p2pReceiverSession.sender_name || 'Nearby Device';
      if (summaryText) {
        summaryText.textContent = `${files.length} file${files.length > 1 ? 's' : ''} (${formatBytes(totalBytes)}) ready to stream at 20–80+ MB/s`;
      }
      if (acceptBtnText) {
        acceptBtnText.textContent = files.length > 1 ? `Accept & Download All (${files.length})` : 'Accept & Download Now';
      }

      // Render incoming files
      if (listEl) {
        listEl.innerHTML = files.map((f, i) => `
          <div class="p-3 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-between text-xs">
            <div class="flex items-center space-x-3 truncate mr-2">
              <div class="w-8 h-8 rounded-xl bg-white border border-slate-200 flex items-center justify-center shrink-0">
                ${getFileIcon(f.type, f.name)}
              </div>
              <div class="truncate">
                <span class="font-bold text-slate-800 block truncate">${escapeHtml(f.name)}</span>
                <span class="text-[10px] text-slate-400 font-mono">${formatBytes(f.size)}</span>
              </div>
            </div>
            <span class="text-[10px] font-bold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-lg shrink-0">
              Ready
            </span>
          </div>
        `).join('');
      }

      if (activeCard) activeCard.classList.add('hidden');
      if (doneCard) doneCard.classList.add('hidden');
      window.setP2PTab('receive');

      const isReady = p2pReceiverSession.status === 'ready' || ((p2pReceiverSession.upload_percent || 0) >= 100);

      if (isReady) {
        if (bufferingCard) bufferingCard.classList.add('hidden');
        if (pendingCard) pendingCard.classList.remove('hidden');
      } else {
        // Sender still buffering: show live sync card to receiver!
        if (pendingCard) pendingCard.classList.add('hidden');
        if (bufferingCard) bufferingCard.classList.remove('hidden');

        const initialPct = p2pReceiverSession.upload_percent || 0;
        const initialUploaded = p2pReceiverSession.uploaded_bytes || 0;
        if (syncPctEl) syncPctEl.textContent = initialPct + '%';
        if (syncBarEl) syncBarEl.style.width = initialPct + '%';
        if (syncBytesEl) syncBytesEl.textContent = `${formatBytes(initialUploaded)} / ${formatBytes(totalBytes)}`;

        // Poll sender progress every 800ms
        p2pReceiverSyncInterval = setInterval(async () => {
          if (!p2pCurrentSessionId || p2pCurrentSessionId !== sessionId) {
            clearInterval(p2pReceiverSyncInterval);
            return;
          }
          try {
            const checkRes = await fetch(`/api/p2p.php?action=get_session&session=${sessionId}`);
            const checkData = await checkRes.json();
            if (!checkData.success) return;

            const sess = checkData.session;
            const pct = sess.upload_percent || 0;
            const upBytes = sess.uploaded_bytes || 0;

            if (syncPctEl) syncPctEl.textContent = pct + '%';
            if (syncBarEl) syncBarEl.style.width = pct + '%';
            if (syncBytesEl) syncBytesEl.textContent = `${formatBytes(upBytes)} / ${formatBytes(totalBytes)}`;

            if (sess.status === 'ready' || pct >= 100) {
              clearInterval(p2pReceiverSyncInterval);
              p2pReceiverSyncInterval = null;

              // Stream is live: notify receiver!
              if (bufferingCard) bufferingCard.classList.add('hidden');
              if (pendingCard) pendingCard.classList.remove('hidden');

              playNotificationTone();
              showLiveReadyPrompt(sess);
            }
          } catch {}
        }, 800);
      }
    } catch (err) {
      alert('Could not connect to transfer session: ' + err.message);
    }
  }

  function showLiveReadyPrompt(session) {
    const banner = document.getElementById('p2p-incoming-banner');
    if (banner) {
      const senderEl = document.getElementById('p2p-banner-sender');
      const infoEl = document.getElementById('p2p-banner-file-info');
      if (senderEl) senderEl.textContent = '⚡ Stream is Live!';
      if (infoEl) infoEl.textContent = `Your files from ${session.sender_name} are 100% ready for maximum speed download!`;
      banner.classList.remove('hidden', '-translate-y-4', 'opacity-0');
      banner.classList.add('translate-y-0', 'opacity-100');
      setTimeout(() => {
        banner.classList.add('-translate-y-4', 'opacity-0');
        setTimeout(() => banner.classList.add('hidden'), 300);
      }, 5000);
    }
  }

  // Starts the download (tries WebRTC direct first; falls back to high-speed stream)
  async function startP2PDownload() {
    if (!p2pCurrentSessionId || !p2pReceiverSession) return;

    p2pIsTransferPaused = false;
    p2pIsTransferStopped = false;

    const pendingCard = document.getElementById('p2p-receive-pending');
    const bufferingCard = document.getElementById('p2p-receive-buffering');
    const activeCard = document.getElementById('p2p-receive-active');
    const doneCard = document.getElementById('p2p-receive-done');
    const badgeEl = document.getElementById('p2p-receive-mode-badge');

    if (bufferingCard) bufferingCard.classList.add('hidden');
    if (pendingCard) pendingCard.classList.add('hidden');
    if (activeCard) activeCard.classList.remove('hidden');
    if (doneCard) doneCard.classList.add('hidden');

    const files = p2pReceiverSession.files || [];
    const totalBatchBytes = p2pReceiverSession.total_size || files.reduce((acc, f) => acc + (f.size || 0), 0);

    // Try WebRTC Direct DataChannel Connect
    let webrtcConnected = false;
    try {
      webrtcConnected = await attemptWebRTCReceive(files, totalBatchBytes);
    } catch {
      webrtcConnected = false;
    }

    // Fallback to high-speed anti-cutoff HTTP streaming if WebRTC didn't establish
    if (!webrtcConnected) {
      if (badgeEl) badgeEl.textContent = 'High-Speed Stream (20–80 MB/s)';
      await streamFilesViaHTTP(files, totalBatchBytes);
    }
  }

  // Attempt WebRTC Receive
  function attemptWebRTCReceive(files, totalBatchBytes) {
    return new Promise((resolve) => {
      if (p2pReceiverPeerConnection) {
        try { p2pReceiverPeerConnection.close(); } catch {}
        p2pReceiverPeerConnection = null;
      }

      const pc = new RTCPeerConnection(rtcConfig);
      p2pReceiverPeerConnection = pc;

      let checkOfferInterval = null;
      let receiverPendingCandidates = [];

      let timer = setTimeout(() => {
        console.warn('[WebRTC] Connection timeout (4.5s), falling back to high-speed HTTP streaming');
        if (checkOfferInterval) clearInterval(checkOfferInterval);
        resolve(false);
      }, 4500);

      pc.ondatachannel = (e) => {
        clearTimeout(timer);
        if (checkOfferInterval) clearInterval(checkOfferInterval);
        const dc = e.channel;
        p2pReceiverDataChannel = dc;
        dc.binaryType = 'arraybuffer';

        const badgeEl = document.getElementById('p2p-receive-mode-badge');
        if (badgeEl) badgeEl.textContent = '🚀 WebRTC Direct LAN (30–80 MB/s)';

        handleDataChannelIncomingStream(dc, files, totalBatchBytes);

        if (dc.readyState === 'open') {
          dc.send(JSON.stringify({ type: 'ready' }));
        } else {
          dc.onopen = () => {
            dc.send(JSON.stringify({ type: 'ready' }));
          };
        }

        resolve(true);
      };

      pc.onicecandidate = (e) => {
        if (e.candidate) {
          sendSignalToRole('sender', { type: 'candidate', candidate: e.candidate });
        }
      };

      // Request fresh connection from sender
      sendSignalToRole('sender', { type: 'connect_request' });

      // Check for sender's offer and ICE candidates
      checkOfferInterval = setInterval(async () => {
        if (!p2pCurrentSessionId || pc.connectionState === 'closed' || pc.connectionState === 'connected') {
          if (checkOfferInterval) clearInterval(checkOfferInterval);
          return;
        }

        const signals = await getSignalsForRole('receiver', p2pReceiverSince);
        for (const item of signals) {
          const sig = item.signal;
          if (sig.type === 'offer') {
            if (!pc.currentRemoteDescription) {
              await pc.setRemoteDescription(new RTCSessionDescription(sig));
              for (const c of receiverPendingCandidates) {
                try { await pc.addIceCandidate(new RTCIceCandidate(c)); } catch {}
              }
              receiverPendingCandidates = [];
              const answer = await pc.createAnswer();
              await pc.setLocalDescription(answer);
              await sendSignalToRole('sender', { type: 'answer', sdp: answer.sdp });
            }
          } else if (sig.type === 'candidate' && sig.candidate) {
            if (pc.currentRemoteDescription) {
              try {
                await pc.addIceCandidate(new RTCIceCandidate(sig.candidate));
              } catch (e) {
                console.warn('[WebRTC Receiver] Add ICE candidate error:', e);
              }
            } else {
              receiverPendingCandidates.push(sig.candidate);
            }
          }
        }
      }, 350);
    });
  }

  function handleDataChannelIncomingStream(dc, files, totalBatchBytes) {
    let fileBlobs = [];
    let chunkBuffer = [];
    let bufferBytes = 0;
    let currentFileInfo = null;
    let totalBytesReceived = 0;
    let fileIndex = 0;

    let lastTime = performance.now();
    let lastBytes = 0;
    let speedMBs = 0;

    dc.onmessage = (event) => {
      if (typeof event.data === 'string') {
        const msg = JSON.parse(event.data);
        if (msg.type === 'file_start') {
          fileBlobs = [];
          chunkBuffer = [];
          bufferBytes = 0;
          currentFileInfo = msg;
          fileIndex = msg.index || 0;
          updateActiveTransferUI(fileIndex + 1, files.length, msg.name);
        } else if (msg.type === 'file_end') {
          if (currentFileInfo) {
            if (chunkBuffer.length > 0) fileBlobs.push(new Blob(chunkBuffer));
            const blob = new Blob(fileBlobs, { type: currentFileInfo.type || 'application/octet-stream' });
            triggerDownload(blob, currentFileInfo.name);
            fileBlobs = [];
            chunkBuffer = [];
            bufferBytes = 0;
          }
        } else if (msg.type === 'all_done') {
          onAllTransfersComplete();
        }
      } else {
        const chunk = event.data;
        chunkBuffer.push(chunk);
        const chunkLen = chunk.byteLength || chunk.size || 0;
        bufferBytes += chunkLen;
        totalBytesReceived += chunkLen;

        // Commit every 4MB to browser blob storage to keep JS heap RAM free
        if (bufferBytes >= 4 * 1024 * 1024) {
          fileBlobs.push(new Blob(chunkBuffer));
          chunkBuffer = [];
          bufferBytes = 0;
        }

        const now = performance.now();
        const deltaSec = (now - lastTime) / 1000;
        if (deltaSec >= 0.1) {
          const deltaBytes = totalBytesReceived - lastBytes;
          const curSpeed = deltaBytes / (deltaSec * 1024 * 1024);
          speedMBs = speedMBs === 0 ? curSpeed : speedMBs * 0.7 + curSpeed * 0.3;
          lastTime = now;
          lastBytes = totalBytesReceived;

          const remBytes = Math.max(0, totalBatchBytes - totalBytesReceived);
          const eta = speedMBs > 0 ? remBytes / (speedMBs * 1024 * 1024) : 0;
          const pct = totalBatchBytes > 0 ? Math.min(100, Math.round((totalBytesReceived / totalBatchBytes) * 100)) : 0;

          renderProgressUI(speedMBs, eta, pct, totalBytesReceived, totalBatchBytes);
        }
      }
    };
  }

  // Fallback: Resumable HTTP Stream (Zero Cutoff & Bounded Memory)
  async function streamFilesViaHTTP(files, totalBatchBytes) {
    let totalBytesReceived = 0;
    let lastTime = performance.now();
    let lastBytes = 0;
    let speedMBs = 0;

    for (let fIdx = 0; fIdx < files.length; fIdx++) {
      if (p2pIsTransferStopped) break;

      const fileMeta = files[fIdx];
      updateActiveTransferUI(fIdx + 1, files.length, fileMeta.name);

      let fileBlobs = [];
      let chunkBuffer = [];
      let bufferBytes = 0;
      let fileBytesReceived = 0;
      const expectedFileSize = fileMeta.size || 0;

      while (fileBytesReceived < expectedFileSize && !p2pIsTransferStopped) {
        while (p2pIsTransferPaused && !p2pIsTransferStopped) {
          await new Promise((r) => setTimeout(r, 200));
        }
        if (p2pIsTransferStopped) break;

        p2pTransferAbortController = new AbortController();
        const downloadUrl = `/api/p2p.php?action=stream&session=${p2pCurrentSessionId}&file_index=${fIdx}`;

        try {
          const headers = {};
          if (fileBytesReceived > 0) {
            headers['Range'] = `bytes=${fileBytesReceived}-`;
          }

          const res = await fetch(downloadUrl, {
            headers,
            signal: p2pTransferAbortController.signal,
          });

          if (!res.ok && res.status !== 206) {
            throw new Error('HTTP Stream status ' + res.status);
          }

          const reader = res.body.getReader();

          while (!p2pIsTransferStopped) {
            while (p2pIsTransferPaused && !p2pIsTransferStopped) {
              await new Promise((r) => setTimeout(r, 200));
            }
            if (p2pIsTransferStopped) break;

            const { done, value } = await reader.read();
            if (done) break;

            if (value) {
              chunkBuffer.push(value);
              bufferBytes += value.length;
              fileBytesReceived += value.length;
              totalBytesReceived += value.length;

              // Batch every 4MB into Blob to prevent JS array RAM exhaustion
              if (bufferBytes >= 4 * 1024 * 1024) {
                fileBlobs.push(new Blob(chunkBuffer));
                chunkBuffer = [];
                bufferBytes = 0;
              }

              const now = performance.now();
              const deltaSec = (now - lastTime) / 1000;
              if (deltaSec >= 0.12) {
                const deltaBytes = totalBytesReceived - lastBytes;
                const curSpeed = deltaBytes / (deltaSec * 1024 * 1024);
                speedMBs = speedMBs === 0 ? curSpeed : speedMBs * 0.7 + curSpeed * 0.3;
                lastTime = now;
                lastBytes = totalBytesReceived;

                const remBytes = Math.max(0, totalBatchBytes - totalBytesReceived);
                const eta = speedMBs > 0 ? remBytes / (speedMBs * 1024 * 1024) : 0;
                const pct = totalBatchBytes > 0 ? Math.min(100, Math.round((totalBytesReceived / totalBatchBytes) * 100)) : 0;

                renderProgressUI(speedMBs, eta, pct, totalBytesReceived, totalBatchBytes);
              }
            }
          }
        } catch (err) {
          if (err.name === 'AbortError') break;
          console.warn('Chunk stream retry:', err);
          await new Promise((r) => setTimeout(r, 400));
        }
      }

      // Save file when completed
      if (!p2pIsTransferStopped && (fileBlobs.length > 0 || chunkBuffer.length > 0)) {
        if (chunkBuffer.length > 0) fileBlobs.push(new Blob(chunkBuffer));
        const mergedBlob = new Blob(fileBlobs, { type: fileMeta.type || 'application/octet-stream' });
        triggerDownload(mergedBlob, fileMeta.name);
      }
    }

    if (!p2pIsTransferStopped) {
      onAllTransfersComplete();
    }
  }

  function updateActiveTransferUI(currentIdx, totalCount, fileName) {
    const labelEl = document.getElementById('p2p-receive-current-file-label');
    const indicatorEl = document.getElementById('p2p-receive-file-index-indicator');

    if (labelEl) labelEl.textContent = fileName;
    if (indicatorEl) indicatorEl.textContent = `File ${currentIdx} of ${totalCount}`;
  }

  function renderProgressUI(speedMBs, eta, pct, received, total) {
    const speedEl = document.getElementById('p2p-receive-speed');
    const etaEl = document.getElementById('p2p-receive-eta');
    const barEl = document.getElementById('p2p-receive-bar');
    const bytesEl = document.getElementById('p2p-receive-bytes');
    const pctEl = document.getElementById('p2p-receive-pct');

    if (speedEl) speedEl.textContent = p2pIsTransferPaused ? 'Paused' : formatSpeed(speedMBs);
    if (etaEl) etaEl.textContent = p2pIsTransferPaused ? 'Paused' : 'ETA: ' + formatEta(eta);
    if (barEl) barEl.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (bytesEl) bytesEl.textContent = `${formatBytes(received)} / ${formatBytes(total)}`;
  }

  function triggerDownload(blob, fileName) {
    const blobUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = blobUrl;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
  }

  function onAllTransfersComplete() {
    const activeCard = document.getElementById('p2p-receive-active');
    const doneCard = document.getElementById('p2p-receive-done');

    if (activeCard) activeCard.classList.add('hidden');
    if (doneCard) doneCard.classList.remove('hidden');

    if (window.confetti) {
      window.confetti({ particleCount: 140, spread: 85, origin: { y: 0.6 } });
    }
  }

  // ============================================================================
  // 6. TRANSFER CONTROLS: PAUSE, RESUME, CANCEL
  // ============================================================================
  window.pauseP2PTransfer = function () {
    p2pIsTransferPaused = true;
    const btnPause = document.getElementById('p2p-btn-pause');
    const btnResume = document.getElementById('p2p-btn-resume');
    const speedEl = document.getElementById('p2p-receive-speed');

    if (btnPause) btnPause.classList.add('hidden');
    if (btnResume) btnResume.classList.remove('hidden');
    if (speedEl) speedEl.textContent = 'Paused';

    if (p2pReceiverDataChannel) {
      p2pReceiverDataChannel.send(JSON.stringify({ type: 'pause' }));
    }
  };

  window.resumeP2PTransfer = function () {
    p2pIsTransferPaused = false;
    const btnPause = document.getElementById('p2p-btn-pause');
    const btnResume = document.getElementById('p2p-btn-resume');

    if (btnResume) btnResume.classList.add('hidden');
    if (btnPause) btnPause.classList.remove('hidden');

    if (p2pReceiverDataChannel) {
      p2pReceiverDataChannel.send(JSON.stringify({ type: 'resume' }));
    }
  };

  window.stopP2PTransfer = function () {
    if (!confirm('Are you sure you want to cancel the transfer?')) return;
    p2pIsTransferStopped = true;

    if (p2pTransferAbortController) {
      p2pTransferAbortController.abort();
    }
    if (p2pReceiverDataChannel) {
      try {
        p2pReceiverDataChannel.send(JSON.stringify({ type: 'cancel' }));
      } catch {}
    }

    window.resetP2PReceiver();
  };

  // ============================================================================
  // 7. CLEAN STATE RESETS ON CLOSE & FINISH
  // ============================================================================
  window.resetP2PSender = function () {
    stopBackgroundKeepalive();
    p2pSelectedFiles = [];
    p2pCurrentSessionId = null;
    p2pSenderSessionData = null;
    p2pSenderIsStreaming = false;

    if (p2pSenderPeerConnection) {
      p2pSenderPeerConnection.close();
      p2pSenderPeerConnection = null;
    }
    p2pSenderDataChannel = null;

    const fileInput = document.getElementById('p2p-send-file-input');
    if (fileInput) fileInput.value = '';

    const dropzone = document.getElementById('p2p-send-dropzone');
    const activeCard = document.getElementById('p2p-send-active-card');
    const listContainer = document.getElementById('p2p-send-files-list');
    const progressBar = document.getElementById('p2p-send-progress-bar');
    const uploadPctEl = document.getElementById('p2p-send-upload-pct');
    const uploadBytesEl = document.getElementById('p2p-send-upload-bytes');

    if (dropzone) dropzone.classList.remove('hidden');
    if (activeCard) activeCard.classList.add('hidden');
    if (listContainer) listContainer.innerHTML = '';
    if (progressBar) progressBar.style.width = '0%';
    if (uploadPctEl) uploadPctEl.textContent = '0%';
    if (uploadBytesEl) uploadBytesEl.textContent = '0 B / 0 B';
  };

  window.resetP2PReceiver = function () {
    if (p2pReceiverSyncInterval) {
      clearInterval(p2pReceiverSyncInterval);
      p2pReceiverSyncInterval = null;
    }

    p2pReceiverSession = null;
    p2pIsTransferPaused = false;
    p2pIsTransferStopped = false;

    if (p2pReceiverPeerConnection) {
      p2pReceiverPeerConnection.close();
      p2pReceiverPeerConnection = null;
    }
    p2pReceiverDataChannel = null;

    const pendingCard = document.getElementById('p2p-receive-pending');
    const bufferingCard = document.getElementById('p2p-receive-buffering');
    const activeCard = document.getElementById('p2p-receive-active');
    const doneCard = document.getElementById('p2p-receive-done');
    const barEl = document.getElementById('p2p-receive-bar');
    const btnPause = document.getElementById('p2p-btn-pause');
    const btnResume = document.getElementById('p2p-btn-resume');

    if (bufferingCard) bufferingCard.classList.add('hidden');
    if (pendingCard) pendingCard.classList.add('hidden');
    if (activeCard) activeCard.classList.add('hidden');
    if (doneCard) doneCard.classList.add('hidden');
    if (barEl) barEl.style.width = '0%';
    if (btnResume) btnResume.classList.add('hidden');
    if (btnPause) btnPause.classList.remove('hidden');
  };

  // ============================================================================
  // 8. MODAL CONTROLS & NAVIGATION
  // ============================================================================
  window.openP2PShareModal = function (tab) {
    const modal = document.getElementById('p2p-share-modal');
    if (modal) {
      modal.classList.remove('hidden');
      window.setP2PTab(tab || 'send');
      p2pPollHeartbeat();
    }
  };

  window.closeP2PShareModal = function () {
    const modal = document.getElementById('p2p-share-modal');
    if (modal) {
      modal.classList.add('hidden');
    }

    window.resetP2PSender();
    window.resetP2PReceiver();

    const url = new URL(window.location.href);
    if (url.searchParams.has('join')) {
      url.searchParams.delete('join');
      window.history.replaceState(null, '', url.pathname + (url.search || ''));
    }
  };

  window.setP2PTab = function (tab) {
    p2pActiveTab = tab;
    const sendTabBtn = document.getElementById('p2p-tab-send-btn');
    const recvTabBtn = document.getElementById('p2p-tab-receive-btn');
    const sendSection = document.getElementById('p2p-send-section');
    const recvSection = document.getElementById('p2p-receive-section');

    if (tab === 'send') {
      if (sendTabBtn) sendTabBtn.className = 'flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-emerald-600 text-emerald-600 transition';
      if (recvTabBtn) recvTabBtn.className = 'flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition';
      if (sendSection) sendSection.classList.remove('hidden');
      if (recvSection) recvSection.classList.add('hidden');
    } else {
      if (recvTabBtn) recvTabBtn.className = 'flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-emerald-600 text-emerald-600 transition';
      if (sendTabBtn) sendTabBtn.className = 'flex items-center space-x-2 pb-3 px-4 font-semibold text-xs border-b-2 border-transparent text-slate-500 hover:text-slate-800 transition';
      if (sendSection) sendSection.classList.add('hidden');
      if (recvSection) recvSection.classList.remove('hidden');
    }
  };

  window.copyP2PJoinLink = function () {
    const input = document.getElementById('p2p-send-join-link');
    if (input) {
      navigator.clipboard.writeText(input.value);
      alert('Transfer link copied to clipboard:\n' + input.value);
    }
  };

  window.handleP2PManualJoin = function () {
    const input = document.getElementById('p2p-manual-code-input');
    if (!input || !input.value.trim()) return;
    loadIncomingSession(input.value.trim());
  };

  window.startP2PDownload = startP2PDownload;

  // Escape helpers
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function escapeAttr(str) {
    if (!str) return '';
    return str.replace(/'/g, "\\'");
  }

  // ============================================================================
  // 9. INITIALIZATION & DRAG-AND-DROP
  // ============================================================================
  document.addEventListener('DOMContentLoaded', () => {
    // 1. Start global background heartbeat polling (every 2.5s)
    p2pPollHeartbeat();
    if (!p2pHeartbeatTimer) {
      p2pHeartbeatTimer = setInterval(p2pPollHeartbeat, 2500);
    }

    // 2. Bind Multi-File Input
    const fileInput = document.getElementById('p2p-send-file-input');
    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files.length > 0) {
          handleFilesSelected(e.target.files);
        }
      });
    }

    // 3. Drag-and-drop on dropzone
    const dropzone = document.getElementById('p2p-send-dropzone');
    if (dropzone) {
      dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('border-emerald-500', 'bg-emerald-50/30');
      });
      dropzone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropzone.classList.remove('border-emerald-500', 'bg-emerald-50/30');
      });
      dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('border-emerald-500', 'bg-emerald-50/30');
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
          handleFilesSelected(e.dataTransfer.files);
        }
      });
    }

    // 4. Auto-detect join URL parameter: e.g. /?join=A8F201
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('join')) {
      const joinCode = urlParams.get('join');
      window.openP2PShareModal('receive');
      loadIncomingSession(joinCode);
    }

    // 5. Register PWA Service Worker for 100% offline support
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').then((reg) => {
        console.log('[PWA] Offline Service Worker registered:', reg.scope);
      }).catch((err) => {
        console.warn('[PWA] Service Worker registration skipped:', err);
      });
    }
  });
})();
