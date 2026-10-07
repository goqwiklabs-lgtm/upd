#!/usr/bin/env node
// ============================================================================
// High-Performance P2P Offline File Streaming Server (LocalSend / Xender)
// Zero RAM Buffering • HTTP Range Resumable Streaming • Zero Internet
// ============================================================================

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const dgram = require('dgram');
const crypto = require('crypto');

const PORT = parseInt(process.env.PORT || '8080', 10);
const HOST = '0.0.0.0';
const DISCOVERY_PORT = 53317; // Standard LocalSend discovery UDP port
const DEVICE_NAME = process.env.DEVICE_NAME || `${os.hostname()} (CloudDrive Direct)`;

// Active hosted files map: fileId -> { id, name, filePath, size, type, sha256 }
const hostedFiles = new Map();
let currentAuthKey = 'sec_tok_' + crypto.randomBytes(4).toString('hex');

/**
 * Discovers all active non-internal IPv4 local network interfaces
 */
function getLocalIPv4Addresses() {
  const interfaces = os.networkInterfaces();
  const addresses = [];

  for (const name of Object.keys(interfaces)) {
    for (const iface of interfaces[name]) {
      if (iface.family === 'IPv4' && !iface.internal) {
        addresses.push({
          interface: name,
          ip: iface.address,
          netmask: iface.netmask,
        });
      }
    }
  }

  // Fallback to loopback if no network interface is active
  if (addresses.length === 0) {
    addresses.push({ interface: 'lo', ip: '127.0.0.1', netmask: '255.0.0.0' });
  }

  return addresses;
}

/**
 * Builds the offline-share/v1 optical handshake payload
 */
function buildHandshakePayload(targetIp) {
  const ipList = getLocalIPv4Addresses();
  const primaryIp = targetIp || ipList[0].ip;

  const filesArray = Array.from(hostedFiles.values()).map((f) => ({
    id: f.id,
    name: f.name,
    size: f.size,
    type: f.type,
    sha256: f.sha256,
  }));

  // Detect whether IP is a typical Hotspot AP IP (e.g. 192.168.43.x, 192.168.49.x, 172.20.10.x)
  const isDirectAP =
    primaryIp.startsWith('192.168.43.') ||
    primaryIp.startsWith('192.168.49.') ||
    primaryIp.startsWith('172.20.10.');

  return {
    protocol: 'offline-share/v1',
    deviceName: DEVICE_NAME,
    mode: isDirectAP ? 'direct_ap' : 'lan_router',
    ssid: isDirectAP ? 'Direct-Share-' + primaryIp.split('.').slice(2).join('') : undefined,
    pass: isDirectAP ? 'p2p_offline_pass' : undefined,
    ip: primaryIp,
    port: PORT,
    authKey: currentAuthKey,
    files: filesArray,
  };
}

/**
 * HTTP Streaming Server with Range Requests & Zero Memory Buffering
 */
const server = http.createServer((req, res) => {
  // Global CORS headers for cross-device browser access
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, HEAD');
  res.setHeader('Access-Control-Allow-Headers', '*');
  res.setHeader('Access-Control-Expose-Headers', 'Content-Range, Content-Length, Accept-Ranges, Content-Disposition');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  const reqUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
  const pathname = reqUrl.pathname;

  // 1. Status / Health Check
  if (pathname === '/' || pathname === '/status') {
    const ipList = getLocalIPv4Addresses();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(
      JSON.stringify({
        status: 'online',
        deviceName: DEVICE_NAME,
        port: PORT,
        interfaces: ipList,
        hostedFilesCount: hostedFiles.size,
        manifestUrl: '/manifest',
      }, null, 2)
    );
    return;
  }

  // 2. Optical Handshake Manifest
  if (pathname === '/manifest') {
    const requestedIp = reqUrl.searchParams.get('ip');
    const payload = buildHandshakePayload(requestedIp);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(payload, null, 2));
    return;
  }

  // 3. Register File to Host
  if (pathname === '/register' && req.method === 'POST') {
    let body = '';
    req.on('data', (chunk) => (body += chunk));
    req.on('end', () => {
      try {
        const data = JSON.parse(body);
        const { id, name, filePath, size, type, sha256 } = data;

        if (!id || !name || !filePath) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ error: 'Missing id, name, or filePath' }));
          return;
        }

        if (!fs.existsSync(filePath)) {
          res.writeHead(404, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ error: `File path does not exist on disk: ${filePath}` }));
          return;
        }

        const stat = fs.statSync(filePath);
        const fileEntry = {
          id,
          name,
          filePath,
          size: size || stat.size,
          type: type || 'application/octet-stream',
          sha256: sha256 || null,
        };

        hostedFiles.set(id, fileEntry);
        console.log(`[P2P Host] Registered file: "${name}" (${(fileEntry.size / (1024 * 1024)).toFixed(2)} MB)`);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: true, file: fileEntry }));
      } catch (err) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: err.message }));
      }
    });
    return;
  }

  // 4. Memory-Safe Binary Stream Download with Range Support
  if (pathname.startsWith('/download/')) {
    const fileId = pathname.substring('/download/'.length);
    const token = reqUrl.searchParams.get('token') || req.headers['x-auth-token'];

    // Auth verification
    if (token && token !== currentAuthKey) {
      res.writeHead(403, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Unauthorized transfer token' }));
      return;
    }

    const file = hostedFiles.get(fileId);
    if (!file || !fs.existsSync(file.filePath)) {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'File not found or unlinked' }));
      return;
    }

    const stat = fs.statSync(file.filePath);
    const fileSize = stat.size;
    const rangeHeader = req.headers.range;

    // Standard headers for high-speed binary download
    const commonHeaders = {
      'Content-Type': file.type || 'application/octet-stream',
      'Content-Disposition': `attachment; filename="${encodeURIComponent(file.name)}"`,
      'Accept-Ranges': 'bytes',
      'Cache-Control': 'no-store, no-cache, must-revalidate',
      'Connection': 'keep-alive',
    };

    if (file.sha256) {
      commonHeaders['X-File-SHA256'] = file.sha256;
    }

    // Handle HTTP Range Requests (Pause / Resume / Interrupted Chunk Resuming)
    if (rangeHeader) {
      const parts = rangeHeader.replace(/bytes=/, '').split('-');
      const start = parseInt(parts[0], 10);
      const end = parts[1] ? parseInt(parts[1], 10) : fileSize - 1;

      if (start >= fileSize || end >= fileSize || start > end) {
        res.writeHead(416, {
          'Content-Range': `bytes */${fileSize}`,
        });
        res.end();
        return;
      }

      const chunkSize = end - start + 1;
      // Memory-Safe Stream: Never buffers into RAM, piped directly to TCP socket
      const stream = fs.createReadStream(file.filePath, {
        start,
        end,
        highWaterMark: 2 * 1024 * 1024, // 2MB stream buffer
      });

      res.writeHead(206, {
        ...commonHeaders,
        'Content-Range': `bytes ${start}-${end}/${fileSize}`,
        'Content-Length': chunkSize,
      });

      stream.pipe(res);

      stream.on('error', (streamErr) => {
        console.error('[P2P Stream Error]:', streamErr);
        if (!res.headersSent) res.writeHead(500);
        res.end();
      });

      console.log(`[P2P Stream] Range ${start}-${end}/${fileSize} (${(chunkSize / (1024 * 1024)).toFixed(1)} MB) -> Client`);
      return;
    }

    // Full File Download Stream
    res.writeHead(200, {
      ...commonHeaders,
      'Content-Length': fileSize,
    });

    const stream = fs.createReadStream(file.filePath, {
      highWaterMark: 2 * 1024 * 1024, // 2MB buffer
    });

    stream.pipe(res);

    stream.on('error', (streamErr) => {
      console.error('[P2P Stream Error]:', streamErr);
      if (!res.headersSent) res.writeHead(500);
      res.end();
    });

    console.log(`[P2P Stream] Full stream start: "${file.name}" (${(fileSize / (1024 * 1024)).toFixed(1)} MB)`);
    return;
  }

  // Not found
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Endpoint not found' }));
});

// Start HTTP Server
server.listen(PORT, HOST, () => {
  const ips = getLocalIPv4Addresses();
  console.log('\n================================================================');
  console.log('🚀 P2P OFFLINE STREAMING SERVER READY (Zero RAM Buffering)');
  console.log(`📡 Listening on: http://${HOST}:${PORT}`);
  console.log(`💻 Device Name: ${DEVICE_NAME}`);
  console.log(`🔑 Auth Key:    ${currentAuthKey}`);
  console.log('🌐 Available IPv4 Network Addresses:');
  ips.forEach((iface) => {
    console.log(`   - [${iface.interface}] http://${iface.ip}:${PORT}`);
  });
  console.log('================================================================\n');
});

// UDP Local Discovery Service (Mode A - Shared Local Network)
const udpSocket = dgram.createSocket('udp4');

udpSocket.on('error', (err) => {
  console.warn('[UDP Discovery Warn]:', err.message);
});

udpSocket.on('message', (msg, rinfo) => {
  try {
    const packet = JSON.parse(msg.toString());
    if (packet.type === 'P2P_DISCOVER_PROBE') {
      const response = JSON.stringify({
        type: 'P2P_DISCOVER_ANNOUNCE',
        deviceName: DEVICE_NAME,
        port: PORT,
        authKey: currentAuthKey,
        protocol: 'offline-share/v1',
      });
      udpSocket.send(response, rinfo.port, rinfo.address);
    }
  } catch {
    // Ignore non-protocol packets
  }
});

udpSocket.bind(DISCOVERY_PORT, HOST, () => {
  try {
    udpSocket.setBroadcast(true);
    console.log(`[UDP Discovery] Listening for peers on 0.0.0.0:${DISCOVERY_PORT}`);
  } catch (err) {
    console.warn('[UDP Broadcast]:', err.message);
  }
});

module.exports = { server, hostedFiles, getLocalIPv4Addresses };
