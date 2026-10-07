<?php
// ========================================================
// VideoTranscoder - Multi-Resolution Engine (YouTube Style)
// Supports 4K, 1440p (2K), 1080p, 720p, 480p, 360p, 240p, 144p
// ========================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

class VideoTranscoder {

    private static ?bool $ffmpegAvailable = null;

    public static function isFfmpegAvailable(): bool {
        if (self::$ffmpegAvailable === null) {
            $path = trim((string)shell_exec('which ffmpeg 2>/dev/null'));
            self::$ffmpegAvailable = !empty($path);
        }
        return self::$ffmpegAvailable;
    }

    public static function probeVideo(string $filePath): ?array {
        if (!file_exists($filePath) || !self::isFfmpegAvailable()) {
            return null;
        }

        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,duration -of default=noprint_wrappers=1 %s 2>/dev/null',
            escapeshellarg($filePath)
        );
        $output = shell_exec($cmd);
        if (!$output) return null;

        $lines = explode("\n", trim($output));
        $data = [];
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false) {
                [$k, $v] = explode('=', $line, 2);
                $data[trim($k)] = trim($v);
            }
        }

        return [
            'width' => isset($data['width']) ? (int)$data['width'] : null,
            'height' => isset($data['height']) ? (int)$data['height'] : null,
            'duration' => isset($data['duration']) ? (float)$data['duration'] : null,
        ];
    }

    public static function getAvailableQualities(int $height = 1080): array {
        $all = [
            '4k'    => 2160,
            '1440p' => 1440,
            '1080p' => 1080,
            '720p'  => 720,
            '480p'  => 480,
            '360p'  => 360,
            '240p'  => 240,
            '144p'  => 144,
        ];

        $available = [];
        foreach ($all as $label => $minH) {
            // Include quality if video resolution is at least near this height
            if ($height >= ($minH * 0.85)) {
                $available[] = $label;
            }
        }

        // Always provide at least standard web resolutions
        if (empty($available)) {
            $available = ['480p', '360p', '240p', '144p'];
        }

        return $available;
    }

    public static function getOriginalPath(int $fileId): string {
        $dir = __DIR__ . '/../cache/originals';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir . '/' . $fileId . '.mp4';
    }

    public static function getTranscodedPath(int $fileId, string $quality): string {
        $dir = __DIR__ . '/../cache/transcoded';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        return $dir . '/' . $fileId . '_' . $quality . '.mp4';
    }

    public static function hasTranscoded(int $fileId, string $quality): bool {
        $path = self::getTranscodedPath($fileId, $quality);
        return file_exists($path) && filesize($path) > 1024;
    }

    public static function ensureOriginalDownloaded(array $file, PDO $pdo): ?string {
        $fileId = (int)$file['id'];
        $origPath = self::getOriginalPath($fileId);

        if (file_exists($origPath) && filesize($origPath) > 1024) {
            return $origPath;
        }

        $accessToken = GoogleDriveManager::getValidAccessToken($file, $pdo);
        if (!$accessToken) return null;

        $googleFileId = $file['google_file_id'];
        $url = "https://www.googleapis.com/drive/v3/files/{$googleFileId}?alt=media";

        $fp = fopen($origPath . '.tmp', 'w');
        if (!$fp) return null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($success && $httpCode === 200 && filesize($origPath . '.tmp') > 1024) {
            rename($origPath . '.tmp', $origPath);
            return $origPath;
        }

        @unlink($origPath . '.tmp');
        return null;
    }

    public static function transcode(string $sourcePath, int $fileId, string $quality): ?string {
        if (!file_exists($sourcePath) || !self::isFfmpegAvailable()) {
            return null;
        }

        $destPath = self::getTranscodedPath($fileId, $quality);
        if (file_exists($destPath) && filesize($destPath) > 1024) {
            return $destPath;
        }

        $resolutions = [
            '144p'  => ['h' => 144,  'crf' => 29, 'ab' => '48k'],
            '240p'  => ['h' => 240,  'crf' => 28, 'ab' => '64k'],
            '360p'  => ['h' => 360,  'crf' => 28, 'ab' => '64k'],
            '480p'  => ['h' => 480,  'crf' => 27, 'ab' => '80k'],
            '720p'  => ['h' => 720,  'crf' => 26, 'ab' => '96k'],
            '1080p' => ['h' => 1080, 'crf' => 24, 'ab' => '128k'],
            '1440p' => ['h' => 1440, 'crf' => 22, 'ab' => '160k'],
            '4k'    => ['h' => 2160, 'crf' => 22, 'ab' => '192k'],
        ];

        $target = $resolutions[$quality] ?? $resolutions['480p'];
        $tmpDest = $destPath . '.tmp.' . uniqid() . '.mp4';

        // Scale keeping aspect ratio, ensuring width is even (divisible by 2)
        $scaleFilter = sprintf('scale=-2:%d', $target['h']);

        $cmd = sprintf(
            'ffmpeg -y -i %s -vf %s -c:v libx264 -preset ultrafast -tune fastdecode -crf %d -c:a aac -b:a %s -movflags +faststart %s 2>/dev/null',
            escapeshellarg($sourcePath),
            escapeshellarg($scaleFilter),
            $target['crf'],
            $target['ab'],
            escapeshellarg($tmpDest)
        );

        shell_exec($cmd);

        if (file_exists($tmpDest) && filesize($tmpDest) > 1024) {
            rename($tmpDest, $destPath);
            return $destPath;
        }

        @unlink($tmpDest);
        return null;
    }

    public static function serveLocalFile(string $filePath, string $mimeType = 'video/mp4', bool $download = false, ?string $downloadName = null): void {
        if (!file_exists($filePath)) {
            http_response_code(404);
            die('File not found.');
        }

        $fileSize = filesize($filePath);
        $etag = '"' . md5_file($filePath) . '"';

        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=604800, stale-while-revalidate=86400');
        header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 604800));

        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Accept-Ranges: bytes');
        header("Content-Type: $mimeType");

        $disposition = $download ? 'attachment' : 'inline';
        $filename = $downloadName ?: basename($filePath);
        header("Content-Disposition: $disposition; filename=\"" . rawurlencode($filename) . "\"");

        $range = $_SERVER['HTTP_RANGE'] ?? null;
        if ($range && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            $start = (int)$matches[1];
            $end = !empty($matches[2]) ? (int)$matches[2] : ($fileSize - 1);
            $length = $end - $start + 1;

            http_response_code(206);
            header("Content-Range: bytes $start-$end/$fileSize");
            header("Content-Length: $length");

            $fp = fopen($filePath, 'rb');
            fseek($fp, $start);

            $bytesLeft = $length;
            $chunkSize = 64 * 1024;

            while (!feof($fp) && $bytesLeft > 0 && !connection_aborted()) {
                $readLen = min($chunkSize, $bytesLeft);
                $buffer = fread($fp, $readLen);
                echo $buffer;
                flush();
                $bytesLeft -= strlen($buffer);
            }
            fclose($fp);
            exit;
        }

        header("Content-Length: $fileSize");
        readfile($filePath);
        exit;
    }
}
