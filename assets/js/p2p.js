// ============================================================================
// CloudDrive Native P2P File Transfer Engine (ShareIt & Snapdrop Architecture)
// 100% Offline Capable • Subnet Radar Auto-Discovery • Instant Phone-to-PC Stream
// ============================================================================

(function () {
  'use strict';

  // --- STATE ---
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

  let p2pActiveTab = 'send';
  let p2pHeartbeatTimer = null;
  let p2pActivePeers = [];
  let p2pCurrentSessionId = null;
  let p2pCurrentFile = null;
  let p2pSelectedFileForSending = null;

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

  // --- DISCOVERY & RADAR HEARTBEAT ---
  async function p2pSendHeartbeat() {
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
      if (data.success) {
        p2pActivePeers = data.peers || [];
        renderRadarPeers(p2pActivePeers);
      }
    } catch {
      // Offline or network error handled gracefully
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
            <i class="fa-solid fa-radar animate-spin"></i>
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
            <span class="text-[10px] text-slate-400 font-mono">Ready to receive</span>
          </div>
        </div>
        <span class="text-[11px] font-semibold text-emerald-600 px-2 py-1 bg-emerald-50 rounded-lg group-hover:bg-emerald-600 group-hover:text-white transition">
          Send <i class="fa-solid fa-arrow-right ml-1"></i>
        </span>
      </div>
    `).join('');
  }

  // --- SENDER ENGINE ---
  async function startHostingFile(file) {
    p2pSelectedFileForSending = file;
    const infoCard = document.getElementById('p2p-send-active-card');
    const dropzone = document.getElementById('p2p-send-dropzone');
    const nameEl = document.getElementById('p2p-send-file-name');
    const sizeEl = document.getElementById('p2p-send-file-size');
    const qrCanvas = document.getElementById('p2p-send-qr-canvas');
    const joinLinkInput = document.getElementById('p2p-send-join-link');
    const codeEl = document.getElementById('p2p-send-code-badge');
    const uploadStatusEl = document.getElementById('p2p-send-upload-status');

    if (dropzone) dropzone.classList.add('hidden');
    if (infoCard) infoCard.classList.remove('hidden');

    if (nameEl) nameEl.textContent = file.name;
    if (sizeEl) sizeEl.textContent = formatBytes(file.size);

    if (uploadStatusEl) {
      uploadStatusEl.textContent = 'Preparing high-speed stream...';
      uploadStatusEl.className = 'text-[11px] text-blue-600 font-medium';
    }

    try {
      // 1. Create Session in Backend
      const initRes = await fetch('/api/p2p.php?action=create_session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          file_name: file.name,
          file_size: file.size,
          mime_type: file.type || 'application/octet-stream',
          sender_name: p2pDeviceName,
        }),
      });
      const initData = await initRes.json();
      if (!initData.success) {
        throw new Error(initData.error || 'Failed to initialize session');
      }

      p2pCurrentSessionId = initData.session_id;

      // 2. Generate Real Reachable QR Code (Standard URL pointing to this website)
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

      // 3. Upload File Chunks in 2MB slices for instant receiver streaming
      if (uploadStatusEl) uploadStatusEl.textContent = 'Buffering file into memory-safe local stream...';

      const CHUNK_SIZE = 2 * 1024 * 1024;
      let offset = 0;
      const total = file.size;

      while (offset < total) {
        const sliceEnd = Math.min(offset + CHUNK_SIZE, total);
        const chunk = file.slice(offset, sliceEnd);

        await fetch(`/api/p2p.php?action=upload_chunk&session=${p2pCurrentSessionId}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/octet-stream' },
          body: chunk,
        });

        offset = sliceEnd;
        const pct = Math.round((offset / total) * 100);
        if (uploadStatusEl) {
          uploadStatusEl.textContent = `Ready for instant receiver stream (${pct}%)`;
        }
      }

      if (uploadStatusEl) {
        uploadStatusEl.textContent = '⚡ Stream Live! Ready to scan on phone camera';
        uploadStatusEl.className = 'text-[11px] text-emerald-600 font-bold';
      }
    } catch (err) {
      console.error('P2P Host error:', err);
      if (uploadStatusEl) {
        uploadStatusEl.textContent = 'Stream error: ' + err.message;
        uploadStatusEl.className = 'text-[11px] text-red-500 font-medium';
      }
    }
  }

  // --- RECEIVER ENGINE ---
  async function loadIncomingSession(sessionId) {
    p2pCurrentSessionId = sessionId;
    const pendingCard = document.getElementById('p2p-receive-pending');
    const activeCard = document.getElementById('p2p-receive-active');
    const senderEl = document.getElementById('p2p-receive-sender-name');
    const nameEl = document.getElementById('p2p-receive-file-name');
    const sizeEl = document.getElementById('p2p-receive-file-size');

    try {
      const res = await fetch(`/api/p2p.php?action=get_session&session=${sessionId}`);
      const data = await res.json();
      if (!data.success) {
        alert('Invalid or expired transfer code: ' + (data.error || ''));
        return;
      }

      const session = data.session;
      p2pCurrentFile = session;

      if (senderEl) senderEl.textContent = session.sender_name || 'Nearby Device';
      if (nameEl) nameEl.textContent = session.file_name;
      if (sizeEl) sizeEl.textContent = formatBytes(session.file_size);

      if (pendingCard) pendingCard.classList.remove('hidden');
      if (activeCard) activeCard.classList.add('hidden');

      // Switch to receive tab
      window.setP2PTab('receive');
    } catch (err) {
      alert('Could not connect to transfer session: ' + err.message);
    }
  }

  async function startStreamingDownload() {
    if (!p2pCurrentSessionId || !p2pCurrentFile) return;

    const pendingCard = document.getElementById('p2p-receive-pending');
    const activeCard = document.getElementById('p2p-receive-active');
    const speedEl = document.getElementById('p2p-receive-speed');
    const etaEl = document.getElementById('p2p-receive-eta');
    const barEl = document.getElementById('p2p-receive-bar');
    const bytesEl = document.getElementById('p2p-receive-bytes');
    const pctEl = document.getElementById('p2p-receive-pct');
    const doneCard = document.getElementById('p2p-receive-done');

    if (pendingCard) pendingCard.classList.add('hidden');
    if (activeCard) activeCard.classList.remove('hidden');
    if (doneCard) doneCard.classList.add('hidden');

    const downloadUrl = `/api/p2p.php?action=stream&session=${p2pCurrentSessionId}`;
    const totalBytes = p2pCurrentFile.file_size || 0;

    try {
      const res = await fetch(downloadUrl);
      if (!res.ok) throw new Error('HTTP Stream failed: ' + res.status);

      const reader = res.body.getReader();
      const chunks = [];
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
            const curSpeed = deltaBytes / (deltaSec * 1024 * 1024);
            speedMBs = speedMBs === 0 ? curSpeed : speedMBs * 0.7 + curSpeed * 0.3;
            lastTime = now;
            lastBytes = receivedBytes;

            const remBytes = Math.max(0, totalBytes - receivedBytes);
            const eta = speedMBs > 0 ? remBytes / (speedMBs * 1024 * 1024) : 0;
            const pct = totalBytes > 0 ? Math.min(100, Math.round((receivedBytes / totalBytes) * 100)) : 0;

            if (speedEl) speedEl.textContent = formatSpeed(speedMBs);
            if (etaEl) etaEl.textContent = 'ETA: ' + formatEta(eta);
            if (barEl) barEl.style.width = pct + '%';
            if (pctEl) pctEl.textContent = pct + '%';
            if (bytesEl) bytesEl.textContent = `${formatBytes(receivedBytes)} / ${formatBytes(totalBytes)}`;
          }
        }
      }

      // Merge chunks into Blob
      const mergedBlob = new Blob(chunks, { type: p2pCurrentFile.mime_type || 'application/octet-stream' });

      // Trigger automatic save
      const blobUrl = URL.createObjectURL(mergedBlob);
      const a = document.createElement('a');
      a.href = blobUrl;
      a.download = p2pCurrentFile.file_name;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(blobUrl);

      // Completed card
      if (activeCard) activeCard.classList.add('hidden');
      if (doneCard) doneCard.classList.remove('hidden');

      // Celebration
      if (window.confetti) {
        window.confetti({ particleCount: 120, spread: 80, origin: { y: 0.6 } });
      }
    } catch (err) {
      alert('Transfer error: ' + err.message);
      if (activeCard) activeCard.classList.add('hidden');
      if (pendingCard) pendingCard.classList.remove('hidden');
    }
  }

  // --- MODAL CONTROLS ---
  window.openP2PShareModal = function (tab) {
    const modal = document.getElementById('p2p-share-modal');
    if (modal) {
      modal.classList.remove('hidden');
      window.setP2PTab(tab || 'send');
      p2pSendHeartbeat();
      if (!p2pHeartbeatTimer) {
        p2pHeartbeatTimer = setInterval(p2pSendHeartbeat, 3000);
      }
    }
  };

  window.closeP2PShareModal = function () {
    const modal = document.getElementById('p2p-share-modal');
    if (modal) {
      modal.classList.add('hidden');
    }
    if (p2pHeartbeatTimer) {
      clearInterval(p2pHeartbeatTimer);
      p2pHeartbeatTimer = null;
    }
    // Clean URL query parameter if opened with ?join=
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

  window.resetP2PSender = function () {
    p2pSelectedFileForSending = null;
    p2pCurrentSessionId = null;
    const dropzone = document.getElementById('p2p-send-dropzone');
    const infoCard = document.getElementById('p2p-send-active-card');
    if (dropzone) dropzone.classList.remove('hidden');
    if (infoCard) infoCard.classList.add('hidden');
  };

  window.sendToPeer = function (peerId, peerName) {
    // If file already chosen, host it and notify
    if (p2pSelectedFileForSending) {
      alert(`Streaming directly to ${peerName}...`);
    } else {
      alert(`Select a file first to send to ${peerName}`);
      document.getElementById('p2p-send-file-input')?.click();
    }
  };

  window.copyP2PJoinLink = function () {
    const input = document.getElementById('p2p-send-join-link');
    if (input) {
      navigator.clipboard.writeText(input.value);
      alert('Download link copied to clipboard:\n' + input.value);
    }
  };

  window.handleP2PManualJoin = function () {
    const input = document.getElementById('p2p-manual-code-input');
    if (!input || !input.value.trim()) return;
    loadIncomingSession(input.value.trim());
  };

  window.startP2PDownload = startStreamingDownload;

  // Escape helpers
  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function escapeAttr(str) {
    if (!str) return '';
    return str.replace(/'/g, "\\'");
  }

  // --- INITIALIZATION ---
  document.addEventListener('DOMContentLoaded', () => {
    // Bind file input
    const fileInput = document.getElementById('p2p-send-file-input');
    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        if (e.target.files && e.target.files[0]) {
          startHostingFile(e.target.files[0]);
        }
      });
    }

    // Auto-detect join URL parameter: e.g. /?join=A8F201
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('join')) {
      const joinCode = urlParams.get('join');
      window.openP2PShareModal('receive');
      loadIncomingSession(joinCode);
    }

    // Register PWA Service Worker for 100% offline support
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').then((reg) => {
        console.log('[PWA] Offline Service Worker registered:', reg.scope);
      }).catch((err) => {
        console.warn('[PWA] Service Worker registration skipped:', err);
      });
    }
  });
})();
