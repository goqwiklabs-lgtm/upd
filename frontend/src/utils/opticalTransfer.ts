// ========================================================
// Air-Gapped Optical Data Transfer Protocol (QR Files)
// 100% Client-Side, Zero Network, High-Speed Optical Stream
// Includes Fountain Codes / Peeling Erasure Decoder
// ========================================================

import { deflateSync, inflateSync } from 'fflate';

export interface OpticalPacket {
  fileId: string;            // 8-char hex identifier
  totalBlocks: number;       // Total number of systematic chunks
  seqId: number;             // Current chunk sequence (1..K for systematic, >K for parity)
  fileName: string;          // Original filename
  fileSize: number;          // Original uncompressed size
  compressedSize: number;    // Compressed byte size
  sha256: string;            // Hex digest of original uncompressed file
  payloadBase64: string;      // Base64 chunk data
  isParity?: boolean;        // Whether this is a Fountain / Erasure parity block
  parityIndices?: number[];  // Sequence IDs XORed together in this block
}

export interface PreparedTransfer {
  fileId: string;
  fileName: string;
  originalSize: number;
  compressedSize: number;
  compressionRatio: number;
  sha256: string;
  totalBlocks: number;
  redundancyBlocks: number;
  packets: string[];
}

export class OpticalProtocol {
  // Protocol Version Signature prefix
  public static readonly MAGIC_PREFIX = 'CDQR1:';

  /**
   * Computes SHA-256 checksum of raw binary using Web Crypto API
   */
  public static async computeSha256(data: Uint8Array): Promise<string> {
    const hashBuffer = await crypto.subtle.digest('SHA-256', data as ArrayBufferView<ArrayBuffer>);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  /**
   * Compresses binary payload in browser using Deflate
   */
  public static compress(data: Uint8Array): Uint8Array {
    return deflateSync(data, { level: 9 });
  }

  /**
   * Decompresses binary payload using Inflate
   */
  public static decompress(compressed: Uint8Array): Uint8Array {
    return inflateSync(compressed);
  }

  /**
   * Bitwise XOR of two buffers of arbitrary lengths (padded to max length)
   */
  public static xorBuffers(a: Uint8Array, b: Uint8Array): Uint8Array {
    const len = Math.max(a.length, b.length);
    const out = new Uint8Array(len);
    for (let i = 0; i < len; i++) {
      const byteA = i < a.length ? a[i] : 0;
      const byteB = i < b.length ? b[i] : 0;
      out[i] = byteA ^ byteB;
    }
    return out;
  }

  /**
   * Encodes Uint8Array to compact Base64
   */
  public static bytesToBase64(bytes: Uint8Array): string {
    let binaryStr = '';
    const len = bytes.length;
    for (let i = 0; i < len; i++) {
      binaryStr += String.fromCharCode(bytes[i]);
    }
    return btoa(binaryStr);
  }

  /**
   * Decodes Base64 to Uint8Array
   */
  public static base64ToBytes(base64: string): Uint8Array {
    const binaryStr = atob(base64);
    const len = binaryStr.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i++) {
      bytes[i] = binaryStr.charCodeAt(i);
    }
    return bytes;
  }

  /**
   * Prepares a file for optical QR transmission.
   * Reads, compresses, computes SHA-256, generates systematic chunks,
   * and appends Fountain Erasure Coding parity packets for zero-loss recovery.
   */
  public static async prepareFile(
    file: File,
    chunkSizeBytes = 360,
    redundancyPercent = 25
  ): Promise<PreparedTransfer> {
    const rawBuffer = new Uint8Array(await file.arrayBuffer());
    const originalSize = rawBuffer.length;
    const sha256 = await this.computeSha256(rawBuffer);

    // 1. Deflate compression
    const compressed = this.compress(rawBuffer);
    const compressedSize = compressed.length;
    const compressionRatio = Math.round((1 - compressedSize / originalSize) * 100);

    // 2. Generate unique 8-character File ID
    const fileId = Math.random().toString(16).substring(2, 10).toUpperCase();

    // 3. Slice into systematic chunks
    const totalBlocks = Math.ceil(compressedSize / chunkSizeBytes);
    const rawChunks: Uint8Array[] = [];
    const packets: string[] = [];

    for (let i = 0; i < totalBlocks; i++) {
      const start = i * chunkSizeBytes;
      const end = Math.min(start + chunkSizeBytes, compressedSize);
      const chunk = compressed.slice(start, end);
      rawChunks.push(chunk);

      const packetObj: OpticalPacket = {
        fileId,
        totalBlocks,
        seqId: i + 1,
        fileName: file.name,
        fileSize: originalSize,
        compressedSize,
        sha256,
        payloadBase64: this.bytesToBase64(chunk),
      };

      packets.push(this.MAGIC_PREFIX + JSON.stringify(packetObj));
    }

    // 4. Generate Fountain Codes / Erasure Coding Parity Packets
    // If we have > 1 block, generate parity blocks by combining 2 or 3 adjacent/random blocks
    const redundancyBlocks = totalBlocks > 1
      ? Math.max(1, Math.round(totalBlocks * (redundancyPercent / 100)))
      : 0;

    for (let r = 0; r < redundancyBlocks; r++) {
      // Pick 2 or 3 block indices (1-indexed)
      const degree = (r % 2 === 0 || totalBlocks < 3) ? 2 : 3;
      const selectedIndices: number[] = [];

      // Combine systematic blocks deterministically or cyclically
      const baseIdx = (r * 2) % totalBlocks;
      selectedIndices.push(baseIdx + 1);

      const secondIdx = (baseIdx + 1) % totalBlocks;
      if (!selectedIndices.includes(secondIdx + 1)) {
        selectedIndices.push(secondIdx + 1);
      }

      if (degree === 3 && totalBlocks >= 3) {
        const thirdIdx = (baseIdx + 2) % totalBlocks;
        if (!selectedIndices.includes(thirdIdx + 1)) {
          selectedIndices.push(thirdIdx + 1);
        }
      }

      // Compute XOR sum
      let parityBuffer = rawChunks[selectedIndices[0] - 1];
      for (let s = 1; s < selectedIndices.length; s++) {
        parityBuffer = this.xorBuffers(parityBuffer, rawChunks[selectedIndices[s] - 1]);
      }

      const parityPacketObj: OpticalPacket = {
        fileId,
        totalBlocks,
        seqId: totalBlocks + r + 1,
        fileName: file.name,
        fileSize: originalSize,
        compressedSize,
        sha256,
        payloadBase64: this.bytesToBase64(parityBuffer),
        isParity: true,
        parityIndices: selectedIndices,
      };

      packets.push(this.MAGIC_PREFIX + JSON.stringify(parityPacketObj));
    }

    return {
      fileId,
      fileName: file.name,
      originalSize,
      compressedSize,
      compressionRatio,
      sha256,
      totalBlocks,
      redundancyBlocks,
      packets,
    };
  }

  /**
   * Parses an optical QR raw string into a structured packet
   */
  public static parsePacket(rawText: string): OpticalPacket | null {
    if (!rawText.startsWith(this.MAGIC_PREFIX)) {
      return null;
    }

    try {
      const jsonStr = rawText.substring(this.MAGIC_PREFIX.length);
      const parsed = JSON.parse(jsonStr) as OpticalPacket;

      if (
        !parsed.fileId ||
        !parsed.totalBlocks ||
        !parsed.seqId ||
        !parsed.payloadBase64 ||
        !parsed.sha256
      ) {
        return null;
      }

      return parsed;
    } catch {
      return null;
    }
  }
}

/**
 * Optical Stream Assembly Engine
 * Handles real-time chunk accumulation, Fountain / Peeling erasure decoding,
 * matrix visualization, and SHA-256 integrity reassembly.
 */
export class OpticalReceiverSession {
  public fileId = '';
  public fileName = '';
  public originalSize = 0;
  public compressedSize = 0;
  public sha256 = '';
  public totalBlocks = 0;

  // Key: sequence ID (1-based), Value: Uint8Array chunk
  private receivedChunks: Map<number, Uint8Array> = new Map();

  // Pending parity equations for Peeling Decoder: { indices: Set<number>, payload: Uint8Array }
  private pendingParity: Array<{ indices: Set<number>; payload: Uint8Array }> = [];

  public startTime = 0;
  public lastFrameTime = 0;
  public totalFramesScanned = 0;
  public duplicateFrames = 0;
  public parityRecoveries = 0;

  public isComplete = false;
  public error: string | null = null;
  public assembledBlob: Blob | null = null;
  public assemblyPromise: Promise<boolean> | null = null;
  public onCompleteCallback?: () => void;

  /**
   * Processes a newly decoded QR packet
   * Returns true if a new block was added/recovered, false if duplicate or waiting
   */
  public ingestPacket(packet: OpticalPacket): boolean {
    // If starting a new file or switching file
    if (!this.fileId || this.fileId !== packet.fileId) {
      this.reset(packet);
    }

    this.totalFramesScanned++;
    this.lastFrameTime = performance.now();

    const chunkBytes = OpticalProtocol.base64ToBytes(packet.payloadBase64);

    // Case 1: Systematic Block (1 <= seqId <= totalBlocks)
    if (!packet.isParity && packet.seqId <= this.totalBlocks) {
      if (this.receivedChunks.has(packet.seqId)) {
        this.duplicateFrames++;
        return false;
      }

      this.receivedChunks.set(packet.seqId, chunkBytes);
      this.runPeelingDecoder(packet.seqId, chunkBytes);

      if (this.receivedChunks.size === this.totalBlocks && !this.isComplete) {
        this.assemblyPromise = this.finishAssembly();
      }
      return true;
    }

    // Case 2: Fountain / Erasure Parity Block
    if (packet.isParity && packet.parityIndices && packet.parityIndices.length > 0) {
      // Simplify equation by XORing out all already known blocks
      const unknownIndices = new Set<number>();
      let simplifiedPayload = chunkBytes;

      for (const idx of packet.parityIndices) {
        if (this.receivedChunks.has(idx)) {
          // Known: XOR it out
          simplifiedPayload = OpticalProtocol.xorBuffers(
            simplifiedPayload,
            this.receivedChunks.get(idx)!
          );
        } else {
          unknownIndices.add(idx);
        }
      }

      // If degree is 0, equation contains no new information
      if (unknownIndices.size === 0) {
        this.duplicateFrames++;
        return false;
      }

      // If degree is 1: Instant recovery of missing block!
      if (unknownIndices.size === 1) {
        const recoveredIdx = Array.from(unknownIndices)[0];
        this.receivedChunks.set(recoveredIdx, simplifiedPayload);
        this.parityRecoveries++;
        this.runPeelingDecoder(recoveredIdx, simplifiedPayload);

        if (this.receivedChunks.size === this.totalBlocks && !this.isComplete) {
          this.assemblyPromise = this.finishAssembly();
        }
        return true;
      }

      // Degree > 1: Store equation for future peeling cascade
      this.pendingParity.push({
        indices: unknownIndices,
        payload: simplifiedPayload,
      });
      return false;
    }

    return false;
  }

  /**
   * Peeling Decoder Cascade:
   * Whenever a new systematic or recovered block is established,
   * substitute it into all pending parity equations. If any equation
   * reduces to 1 unknown index, solve it and cascade!
   */
  private runPeelingDecoder(newIdx: number, newBytes: Uint8Array) {
    let resolvedNew = false;

    do {
      resolvedNew = false;
      for (let i = this.pendingParity.length - 1; i >= 0; i--) {
        const eq = this.pendingParity[i];

        if (eq.indices.has(newIdx)) {
          eq.indices.delete(newIdx);
          eq.payload = OpticalProtocol.xorBuffers(eq.payload, newBytes);

          // If fully resolved
          if (eq.indices.size === 0) {
            this.pendingParity.splice(i, 1);
          } else if (eq.indices.size === 1) {
            // Solved a missing block!
            const recoveredIdx = Array.from(eq.indices)[0];
            const recoveredBytes = eq.payload;
            this.pendingParity.splice(i, 1);

            if (!this.receivedChunks.has(recoveredIdx)) {
              this.receivedChunks.set(recoveredIdx, recoveredBytes);
              this.parityRecoveries++;
              resolvedNew = true;
              newIdx = recoveredIdx;
              newBytes = recoveredBytes;
              break;
            }
          }
        }
      }
    } while (resolvedNew && this.receivedChunks.size < this.totalBlocks);
  }

  public get receivedCount(): number {
    return this.receivedChunks.size;
  }

  public get progressPercent(): number {
    if (this.totalBlocks === 0) return 0;
    return Math.min(100, Math.round((this.receivedChunks.size / this.totalBlocks) * 100));
  }

  public isBlockReceived(seqId: number): boolean {
    return this.receivedChunks.has(seqId);
  }

  public getMissingBlocks(): number[] {
    const missing: number[] = [];
    for (let i = 1; i <= this.totalBlocks; i++) {
      if (!this.receivedChunks.has(i)) {
        missing.push(i);
      }
    }
    return missing;
  }

  private reset(packet: OpticalPacket) {
    this.fileId = packet.fileId;
    this.fileName = packet.fileName;
    this.originalSize = packet.fileSize;
    this.compressedSize = packet.compressedSize;
    this.sha256 = packet.sha256;
    this.totalBlocks = packet.totalBlocks;
    this.receivedChunks.clear();
    this.pendingParity = [];
    this.startTime = performance.now();
    this.totalFramesScanned = 0;
    this.duplicateFrames = 0;
    this.parityRecoveries = 0;
    this.isComplete = false;
    this.error = null;
    this.assembledBlob = null;
    this.assemblyPromise = null;
  }

  public async finishAssembly(): Promise<boolean> {
    try {
      // 1. Rebuild concatenated compressed buffer in order 1..totalBlocks
      const chunks: Uint8Array[] = [];
      let totalLength = 0;

      for (let i = 1; i <= this.totalBlocks; i++) {
        const chunk = this.receivedChunks.get(i);
        if (!chunk) throw new Error(`Missing block #${i}`);
        chunks.push(chunk);
        totalLength += chunk.length;
      }

      const mergedCompressed = new Uint8Array(totalLength);
      let offset = 0;
      for (const chunk of chunks) {
        mergedCompressed.set(chunk, offset);
        offset += chunk.length;
      }

      // Truncate to exact compressedSize (since parity XOR might have zero-padded slightly)
      const exactCompressed = mergedCompressed.slice(0, this.compressedSize);

      // 2. Inflate Decompress
      const rawData = OpticalProtocol.decompress(exactCompressed);

      // 3. Verify SHA-256 Checksum
      const calculatedHash = await OpticalProtocol.computeSha256(rawData);
      if (calculatedHash.toLowerCase() !== this.sha256.toLowerCase()) {
        throw new Error(
          `SHA-256 Checksum Mismatch!\nExpected: ${this.sha256}\nGot: ${calculatedHash}`
        );
      }

      this.assembledBlob = new Blob([rawData as unknown as BlobPart], {
        type: 'application/octet-stream',
      });
      this.isComplete = true;
      if (this.onCompleteCallback) {
        this.onCompleteCallback();
      }
      return true;
    } catch (err: unknown) {
      this.error = err instanceof Error ? err.message : 'Reassembly error';
      console.error('Assembly error:', err);
      return false;
    }
  }
}
