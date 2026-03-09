<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '/vendor/autoload.php';

// ── Configuration ─────────────────────────────────────────────────────────────

$config = require __DIR__ . '/config.default.php';

$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    $config = array_replace($config, require $localConfig);
}

// ── General helpers ───────────────────────────────────────────────────────────

/**
 * Resolve a user-supplied relative path to an absolute path within the media root.
 * Returns false on directory-traversal attempts or non-existent parent directories.
 */
function resolvePath(string $relPath, string $mediaBase): string|false
{
    $relPath = ltrim($relPath, '/\\');
    $full    = $mediaBase . DIRECTORY_SEPARATOR . $relPath;
    $real    = realpath($full);

    if ($real === false) {
        $parentReal = realpath(dirname($full));
        if ($parentReal === false) return false;
        if (!str_starts_with($parentReal . DIRECTORY_SEPARATOR, $mediaBase . DIRECTORY_SEPARATOR)) return false;
        return $full;
    }

    if (!str_starts_with($real . DIRECTORY_SEPARATOR, $mediaBase . DIRECTORY_SEPARATOR)
        && $real !== $mediaBase) {
        return false;
    }

    return $real;
}

/** Return media type ('photo'|'video'|'audio') for a filename, or null. */
function getMediaType(string $filename, array $extensions): ?string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    foreach ($extensions as $type => $exts) {
        if (in_array($ext, $exts, true)) return $type;
    }
    return null;
}

/**
 * Return the best available capture timestamp for a media file.
 * For JPEG photos reads EXIF DateTimeOriginal; falls back to filemtime.
 */
function getMediaCaptureDate(string $fullPath, string $type): int
{
    if ($type === 'photo' && function_exists('exif_read_data')) {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg'], true)) {
            $exif = @exif_read_data($fullPath);
            foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
                $dt = $exif[$key] ?? null;
                if ($dt) {
                    $obj = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
                    if ($obj !== false) return $obj->getTimestamp();
                }
            }
        }
    }
    return @filemtime($fullPath) ?: 0;
}

/** Strip RFC 7230-illegal characters from header values. */
function h(string $value): string
{
    return preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', ' ', $value);
}

/** Build a 400×400 "No preview" placeholder PNG. */
function placeholderPng(): string
{
    $img = imagecreatetruecolor(400, 400);
    $bg  = imagecolorallocate($img, 30, 30, 30);
    $fg  = imagecolorallocate($img, 120, 120, 120);
    imagefilledrectangle($img, 0, 0, 400, 400, $bg);
    imagestring($img, 3, 130, 190, 'No preview', $fg);
    ob_start(); imagepng($img); imagedestroy($img);
    return ob_get_clean();
}

/** Resize a GD image to fit within maxW×maxH, maintaining aspect ratio. */
function resizeGd($src, int $maxW, int $maxH): \GdImage
{
    $srcW  = imagesx($src);
    $srcH  = imagesy($src);
    $ratio = min($maxW / $srcW, $maxH / $srcH, 1.0);
    $dstW  = (int) round($srcW * $ratio);
    $dstH  = (int) round($srcH * $ratio);

    $dst = imagecreatetruecolor($dstW, $dstH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);
    return $dst;
}

// ── Cache helpers ─────────────────────────────────────────────────────────────

/**
 * Sanitize a single path segment:
 *   – German/Latin umlauts → ASCII equivalents
 *   – lowercase
 *   – anything not [a-z0-9-] → underscore
 *   – collapse multiple underscores
 */
function sanitizeName(string $name): string
{
    static $map = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue',
        'à' => 'a',  'á' => 'a',  'â' => 'a',  'ã' => 'a',  'å' => 'a',
        'è' => 'e',  'é' => 'e',  'ê' => 'e',  'ë' => 'e',
        'ì' => 'i',  'í' => 'i',  'î' => 'i',  'ï' => 'i',
        'ò' => 'o',  'ó' => 'o',  'ô' => 'o',  'õ' => 'o',
        'ù' => 'u',  'ú' => 'u',  'û' => 'u',
        'ñ' => 'n',  'ç' => 'c',  'ý' => 'y',
    ];
    $name = strtr($name, $map);
    $name = mb_strtolower($name, 'UTF-8');
    $name = preg_replace('/[^a-z0-9\-]+/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    $name = trim($name, '_');
    return $name !== '' ? $name : 'x';
}

/**
 * Compute the cache filesystem path, public URL, and directory for a relPath.
 * relPath is relative to media_base_path.
 * Cached thumbnails are always JPEG regardless of source type.
 */
function cacheInfoForRelPath(string $relPath, array $config): array
{
    $relPath = ltrim($relPath, '/');
    $parts   = explode('/', $relPath);
    $file    = array_pop($parts);                    // last segment = filename
    $stem    = sanitizeName(pathinfo($file, PATHINFO_FILENAME));

    $dirs    = array_values(array_filter(array_map('sanitizeName', $parts), fn($d) => $d !== ''));
    $relDir  = implode('/', $dirs);

    $base    = rtrim($config['cache_path'], '/');
    $urlBase = rtrim($config['cache_url'],  '/');

    $dir = $base    . ($relDir !== '' ? '/' . $relDir : '');
    $url = $urlBase . ($relDir !== '' ? '/' . $relDir : '') . '/' . $stem . '.jpg';

    return ['dir' => $dir, 'fsPath' => $dir . '/' . $stem . '.jpg', 'url' => $url];
}

/**
 * Return the filesystem path to _index.json for a folder.
 * folderRelPath is the folder's path relative to media_base_path ('' = root).
 */
function indexJsonPath(string $folderRelPath, array $config): string
{
    $folderRelPath = ltrim($folderRelPath, '/');
    if ($folderRelPath === '' || $folderRelPath === '.') {
        return rtrim($config['cache_path'], '/') . '/_index.json';
    }
    $parts     = explode('/', $folderRelPath);
    $sanitized = implode('/', array_filter(array_map('sanitizeName', $parts), fn($d) => $d !== ''));
    return rtrim($config['cache_path'], '/') . '/' . $sanitized . '/_index.json';
}

/** Read a cache index file. Returns default structure if missing or malformed. */
function readIndex(string $path): array
{
    if (!is_readable($path)) return ['updated_at' => 0, 'files' => [], 'folders' => []];
    $data = json_decode(@file_get_contents($path), true);
    if (!is_array($data)) return ['updated_at' => 0, 'files' => [], 'folders' => []];
    return array_merge(['updated_at' => 0, 'files' => [], 'folders' => []], $data);
}

/**
 * Write a cache index file, creating directories as needed.
 * Also ensures the cache root has an .htaccess that disables directory listing.
 */
function writeIndex(string $path, array $data, string $cacheRoot): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

    // Prevent directory listing in the cache tree.
    $htaccess = rtrim($cacheRoot, '/') . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n");
    }

    return file_put_contents(
        $path,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ) !== false;
}

/**
 * Atomically add or update a single file entry in a cache index.
 *
 * Uses an exclusive flock so that concurrent thumbnail-generation processes
 * (the browser opens several parallel connections) cannot clobber each other's
 * index entries with a stale read-then-write.  The lock is held only for the
 * short re-read + write, never during the expensive thumbnail generation itself.
 */
function updateIndexFile(string $indexPath, string $fname, array $entry, string $cacheRoot): void
{
    $dir = dirname($indexPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $htaccess = rtrim($cacheRoot, '/') . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n");
    }

    $fh = @fopen($indexPath, 'c+');   // open for r/w, create if missing, no truncate
    if ($fh === false) return;
    if (!flock($fh, LOCK_EX)) { fclose($fh); return; }

    // Re-read inside the lock to get the latest state written by other processes.
    $content = stream_get_contents($fh);
    $data    = json_decode($content ?: '', true);
    if (!is_array($data)) {
        $data = ['updated_at' => 0, 'files' => [], 'folders' => []];
    } else {
        $data = array_merge(['updated_at' => 0, 'files' => [], 'folders' => []], $data);
    }

    $data['files'][$fname] = $entry;
    $data['updated_at']    = time();

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

// ── Thumbnail generation functions ────────────────────────────────────────────

/**
 * Generate a JPEG thumbnail for a video file.
 * Strategy 1: Synology @eaDir pre-generated thumbnails (no FFmpeg needed).
 * Strategy 2: FFmpeg image2 muxer → temp file.
 * Returns JPEG bytes on success, false on failure.
 */
function generateVideoThumb(string $fullPath, array $config): string|false
{
    $maxW   = (int) $config['thumb_max_width'];
    $maxH   = (int) $config['thumb_max_height'];
    $tmpDir = '/var/services/tmp';

    // ── Strategy 1: Synology @eaDir ──────────────────────────────────────────
    $videoDir  = dirname($fullPath);
    $baseName  = basename($fullPath);
    $baseStem  = pathinfo($fullPath, PATHINFO_FILENAME);
    $eaBase    = $videoDir . DIRECTORY_SEPARATOR . '@eaDir' . DIRECTORY_SEPARATOR;
    $thumbFound = null;

    foreach ([$baseName, $baseStem] as $sub) {
        $d = $eaBase . $sub . DIRECTORY_SEPARATOR;
        foreach (['SYNOPHOTO_THUMB_XL.jpg', 'SYNO@.videoThumbnail',
                  'SYNOPHOTO_THUMB_L.jpg',  'SYNOPHOTO_THUMB_M.jpg',
                  'SYNOPHOTO_THUMB_SM.jpg'] as $t) {
            if (is_readable($d . $t)) { $thumbFound = $d . $t; break 2; }
        }
    }

    if ($thumbFound !== null) {
        $src = @imagecreatefromjpeg($thumbFound);
        if ($src !== false) {
            $dst = resizeGd($src, $maxW, $maxH);
            ob_start(); imagejpeg($dst, null, 85); imagedestroy($dst);
            $jpg = ob_get_clean();
            if ($jpg) return $jpg;
        }
    }

    // ── Strategy 2: FFmpeg image2 → temp file ────────────────────────────────
    $ffmpegEsc = escapeshellarg($config['ffmpeg_path']);
    $inEsc     = escapeshellarg($fullPath);
    $tmpFrame  = $tmpDir . '/vthumb_' . bin2hex(random_bytes(6)) . '.jpg';
    $tmpEsc    = escapeshellarg($tmpFrame);
    $errFile   = $tmpDir . '/vterr_' . bin2hex(random_bytes(4)) . '.txt';
    $errEsc    = escapeshellarg($errFile);

    foreach (['-ss 5 ', ''] as $seek) {
        shell_exec("{$ffmpegEsc} -y {$seek}-i {$inEsc} -vframes 1 -f image2 {$tmpEsc} 2>{$errEsc}");
        @unlink($errFile);
        if (file_exists($tmpFrame) && filesize($tmpFrame) > 0) break;
    }

    $jpegData = file_exists($tmpFrame) ? (@file_get_contents($tmpFrame) ?: '') : '';
    @unlink($tmpFrame);

    if ($jpegData === '') return false;

    $src = @imagecreatefromstring($jpegData);
    if ($src === false) return false;

    $dst = resizeGd($src, $maxW, $maxH);
    ob_start(); imagejpeg($dst, null, 85); imagedestroy($dst);
    $jpg = ob_get_clean();
    return ($jpg && $jpg !== '') ? $jpg : false;
}

/**
 * Rotate/flip a GD image to match its EXIF Orientation tag.
 * Used as a fallback when Imagick is not available.
 */
function applyGdOrientation(\GdImage $img, int $orientation): \GdImage
{
    switch ($orientation) {
        case 2:
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 3:
            $r = imagerotate($img, 180, 0);
            if ($r !== false) { imagedestroy($img); $img = $r; }
            break;
        case 4:
            imageflip($img, IMG_FLIP_VERTICAL);
            break;
        case 5:
            $r = imagerotate($img, -90, 0);
            if ($r !== false) { imagedestroy($img); $img = $r; }
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 6:
            $r = imagerotate($img, -90, 0);
            if ($r !== false) { imagedestroy($img); $img = $r; }
            break;
        case 7:
            $r = imagerotate($img, 90, 0);
            if ($r !== false) { imagedestroy($img); $img = $r; }
            imageflip($img, IMG_FLIP_HORIZONTAL);
            break;
        case 8:
            $r = imagerotate($img, 90, 0);
            if ($r !== false) { imagedestroy($img); $img = $r; }
            break;
    }
    return $img;
}

/**
 * Generate a JPEG thumbnail for a photo file.
 * Tries Imagick first (better quality/EXIF handling), falls back to GD.
 * Returns JPEG bytes on success, false on failure.
 */
function generatePhotoThumb(string $fullPath, int $maxW, int $maxH): string|false
{
    if (extension_loaded('imagick')) {
        try {
            $im = new \Imagick($fullPath);
            $im->autoOrient();                        // apply EXIF rotation before stripping
            $im->setImageCompressionQuality(85);
            $im->stripImage();
            $im->thumbnailImage($maxW, $maxH, true);
            $im->setImageFormat('jpeg');
            $data = $im->getImageBlob();
            $im->clear();
            if (!empty($data)) return $data;
        } catch (\Exception $e) {
            // fall through to GD
        }
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $src = match($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
        'png'         => @imagecreatefrompng($fullPath),
        'gif'         => @imagecreatefromgif($fullPath),
        'webp'        => @imagecreatefromwebp($fullPath),
        default       => false,
    };
    if ($src === false) return false;

    // GD does not apply EXIF orientation automatically; correct it for JPEG.
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif        = @exif_read_data($fullPath);
        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation > 1) $src = applyGdOrientation($src, $orientation);
    }

    $dst = resizeGd($src, $maxW, $maxH);
    ob_start(); imagejpeg($dst, null, 85); imagedestroy($dst);
    $jpg = ob_get_clean();
    return ($jpg && $jpg !== '') ? $jpg : false;
}

/**
 * Recursively collect all non-audio media file paths under $dir.
 * Paths are relative to media_base_path (i.e. relBase/filename).
 */
function collectFilesRecursively(string $dir, string $relBase, array $config, array &$results): void
{
    $entries = @scandir($dir);
    if (!$entries) return;
    sort($entries);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (in_array($entry, $config['blacklist_dir_names'], true)) continue;

        $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
        $relEntry = $relBase !== '' ? $relBase . '/' . $entry : $entry;

        if (is_dir($fullPath)) {
            collectFilesRecursively($fullPath, $relEntry, $config, $results);
        } elseif (is_file($fullPath)) {
            $type = getMediaType($entry, $config['extensions']);
            if ($type !== null && $type !== 'audio') {
                $results[] = ['path' => $relEntry, 'type' => $type];
            }
        }
    }
}

/**
 * Recursively delete cached files inside $path.
 * When $keepRoot is true the directory itself and its .htaccess are preserved.
 * Returns the number of files deleted.
 */
function deleteCachePath(string $path, bool $keepRoot): int
{
    if (!is_dir($path)) return 0;
    $deleted = 0;
    $entries = @scandir($path);
    if ($entries === false) return 0;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if ($keepRoot && $entry === '.htaccess') continue;

        $full = $path . '/' . $entry;
        if (is_dir($full)) {
            $deleted += deleteCachePath($full, false);
            @rmdir($full);
        } elseif (is_file($full)) {
            if (@unlink($full)) $deleted++;
        }
    }
    return $deleted;
}

// ── Metadata extraction ───────────────────────────────────────────────────────

/** Convert a GPS rational array + ref to a signed decimal degree. */
function gpsToDecimal(array $coord, string $ref): float
{
    $deg = 0.0;
    foreach ($coord as $i => $part) {
        $nums = explode('/', (string)$part);
        $val  = (count($nums) === 2 && (float)$nums[1] > 0)
            ? (float)$nums[0] / (float)$nums[1]
            : (float)$part;
        $deg += $val / (60 ** $i);
    }
    return in_array(strtoupper($ref), ['S', 'W'], true) ? -$deg : $deg;
}

/**
 * Extract available metadata from a photo file.
 * Always returns file_name and file_size; adds EXIF fields for JPEG.
 */
function extractPhotoMetadata(string $fullPath): array
{
    $meta = [
        'file_name' => basename($fullPath),
        'file_size' => @filesize($fullPath) ?: null,
    ];

    $info = @getimagesize($fullPath);
    if ($info) { $meta['width'] = $info[0]; $meta['height'] = $info[1]; }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
        return $meta;
    }

    $exif = @exif_read_data($fullPath, 'ANY_TAG', false);
    if (!is_array($exif)) return $meta;

    // Capture date
    foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
        $dt = $exif[$key] ?? null;
        if ($dt) {
            $obj = \DateTime::createFromFormat('Y:m:d H:i:s', $dt);
            if ($obj !== false) { $meta['capture_datetime'] = $obj->format('Y-m-d H:i:s'); break; }
        }
    }

    // Camera
    if (!empty($exif['Make']))  $meta['camera_make']  = trim($exif['Make']);
    if (!empty($exif['Model'])) $meta['camera_model'] = trim($exif['Model']);

    // Aperture (FNumber stored as fraction string)
    if (!empty($exif['FNumber'])) {
        $p = explode('/', (string)$exif['FNumber']);
        if (count($p) === 2 && (float)$p[1] > 0) $meta['aperture'] = round((float)$p[0] / (float)$p[1], 1);
    }

    // Shutter speed
    if (!empty($exif['ExposureTime'])) $meta['shutter_speed'] = (string)$exif['ExposureTime'];

    // ISO
    if (!empty($exif['ISOSpeedRatings'])) {
        $iso = $exif['ISOSpeedRatings'];
        $meta['iso'] = is_array($iso) ? (int)$iso[0] : (int)$iso;
    }

    // Focal length
    if (!empty($exif['FocalLength'])) {
        $p = explode('/', (string)$exif['FocalLength']);
        $meta['focal_length'] = (count($p) === 2 && (float)$p[1] > 0)
            ? round((float)$p[0] / (float)$p[1])
            : round((float)$exif['FocalLength']);
    }

    // GPS
    if (!empty($exif['GPSLatitude']) && !empty($exif['GPSLongitude'])) {
        $meta['gps_lat'] = round(gpsToDecimal($exif['GPSLatitude'],  $exif['GPSLatitudeRef']  ?? 'N'), 6);
        $meta['gps_lng'] = round(gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E'), 6);
        if (!empty($exif['GPSAltitude'])) {
            $p = explode('/', (string)$exif['GPSAltitude']);
            if (count($p) === 2 && (float)$p[1] > 0) $meta['gps_alt'] = round((float)$p[0] / (float)$p[1]);
        }
    }

    return $meta;
}

/**
 * Extract available metadata from a video file using FFprobe.
 * Falls back to file_name + file_size if FFprobe is unavailable.
 */
function extractVideoMetadata(string $fullPath, array $config): array
{
    $meta = [
        'file_name' => basename($fullPath),
        'file_size' => @filesize($fullPath) ?: null,
    ];

    $ffprobe = dirname($config['ffmpeg_path']) . '/ffprobe';
    if (!is_executable($ffprobe)) return $meta;

    $cmd = escapeshellarg($ffprobe)
         . ' -v quiet -print_format json -show_streams -show_format '
         . escapeshellarg($fullPath) . ' 2>/dev/null';
    $out = @shell_exec($cmd);
    if (!$out) return $meta;

    $data = json_decode($out, true);
    if (!is_array($data)) return $meta;

    $fmt = $data['format'] ?? [];
    if (!empty($fmt['duration'])) $meta['duration'] = round((float)$fmt['duration']);

    $creationTime = $fmt['tags']['creation_time'] ?? ($fmt['tags']['com.apple.quicktime.creationdate'] ?? '');
    if ($creationTime) {
        $obj = date_create($creationTime);
        if ($obj) $meta['capture_datetime'] = $obj->format('Y-m-d H:i:s');
    }

    foreach ($data['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video') {
            if (!empty($stream['width']))      $meta['width']  = (int)$stream['width'];
            if (!empty($stream['height']))     $meta['height'] = (int)$stream['height'];
            if (!empty($stream['codec_name'])) $meta['codec']  = $stream['codec_name'];
            if (!empty($stream['r_frame_rate'])) {
                $p = explode('/', $stream['r_frame_rate']);
                if (count($p) === 2 && (float)$p[1] > 0) {
                    $fps = round((float)$p[0] / (float)$p[1], 1);
                    if ($fps > 0) $meta['fps'] = $fps;
                }
            }
            break;
        }
    }

    return $meta;
}

// ── Slim app setup ────────────────────────────────────────────────────────────

$app = AppFactory::create();
$app->setBasePath('/photos/api');

// CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin',  '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
        ->withHeader('Access-Control-Max-Age',       '86400');
});

$app->addErrorMiddleware(false, true, true);

// Handle CORS preflight for POST routes
$app->options('/{routes:.+}', function (Request $request, Response $response): Response {
    return $response;
});

// ── Route: GET /browse ────────────────────────────────────────────────────────

$app->get('/browse', function (Request $request, Response $response) use ($config): Response {
    $params  = $request->getQueryParams();
    $relPath = $params['path'] ?? '';

    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $dir = ($relPath === '' || $relPath === '/')
        ? $mediaBase
        : resolvePath($relPath, $mediaBase);

    if ($dir === false || !is_dir($dir)) {
        $body = json_encode(['error' => 'Invalid or non-existent path', 'folders' => [], 'files' => [], 'warnings' => []]);
        $response->getBody()->write($body);
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $folders  = [];
    $files    = [];
    $warnings = [];

    $entries = @scandir($dir);
    if ($entries === false) {
        $warnings[] = 'Could not read directory';
        $entries = [];
    }
    sort($entries);

    $blacklistDirNames  = $config['blacklist_dir_names'];
    $blacklistDirPaths  = array_map(fn($p) => ltrim($p, '/\\'), $config['blacklist_dir_paths']);
    $blacklistFilePaths = array_map(fn($p) => ltrim($p, '/\\'), $config['blacklist_file_paths']);

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        $fullPath = $dir . DIRECTORY_SEPARATOR . $entry;
        $relEntry = ($relPath === '' || $relPath === '/')
            ? $entry
            : ltrim($relPath, '/') . '/' . $entry;

        if (is_dir($fullPath)) {
            if (in_array($entry, $blacklistDirNames, true)) continue;
            if (in_array($relEntry, $blacklistDirPaths, true)) continue;

            // Check for a permanently pinned preview image.
            $subIdxPath = indexJsonPath($relEntry, $config);
            $subIdx     = readIndex($subIdxPath);
            if (!empty($subIdx['pinned_preview'])) {
                $pinnedFull = resolvePath($subIdx['pinned_preview'], $mediaBase);
                if ($pinnedFull !== false && is_file($pinnedFull)) {
                    $folders[] = ['name' => $entry, 'path' => $relEntry, 'thumbnail' => $subIdx['pinned_preview'], '_mtime' => @filemtime($fullPath) ?: 0];
                    continue;
                }
            }

            // First photo is preferred as the folder thumbnail; first video is the fallback.
            $folderThumb  = null;
            $folderVideo  = null;
            $subEntries   = @scandir($fullPath);
            if ($subEntries) {
                sort($subEntries);
                foreach ($subEntries as $sub) {
                    if ($sub === '.' || $sub === '..') continue;
                    if (!is_file($fullPath . DIRECTORY_SEPARATOR . $sub)) continue;
                    $subType = getMediaType($sub, $config['extensions']);
                    if ($subType === 'photo') {
                        $folderThumb = $relEntry . '/' . $sub;
                        break;
                    }
                    if ($subType === 'video' && $folderVideo === null) {
                        $folderVideo = $relEntry . '/' . $sub;
                    }
                }
            }
            if ($folderThumb === null) $folderThumb = $folderVideo;

            $folders[] = ['name' => $entry, 'path' => $relEntry, 'thumbnail' => $folderThumb, '_mtime' => @filemtime($fullPath) ?: 0];
            continue;
        }

        if (!is_file($fullPath)) continue;
        if (in_array($relEntry, $blacklistFilePaths, true)) continue;

        $type = getMediaType($entry, $config['extensions']);
        if ($type === null) continue;

        $files[] = ['name' => $entry, 'path' => $relEntry, 'type' => $type];
    }

    // ── Sort files ────────────────────────────────────────────────────────────
    $folderRel  = ($relPath === '' || $relPath === '/') ? '' : ltrim($relPath, '/');
    $indexPath  = indexJsonPath($folderRel, $config);
    $index      = readIndex($indexPath);
    $sort       = $index['sort'] ?? 'date_asc';

    if ($sort === 'name_desc') {
        $folders = array_reverse($folders);
        $files   = array_reverse($files);
    } elseif ($sort === 'date_asc' || $sort === 'date_desc') {
        // Sort folders by filesystem mtime.
        usort($folders, $sort === 'date_desc'
            ? fn($a, $b) => $b['_mtime'] - $a['_mtime']
            : fn($a, $b) => $a['_mtime'] - $b['_mtime']
        );
        // Sort files by capture date (cached EXIF, or filemtime fallback).
        $indexFiles = $index['files'] ?? [];
        foreach ($files as &$f) {
            $cached     = $indexFiles[$f['name']] ?? null;
            $f['_date'] = ($cached && isset($cached['capture_date']))
                ? (int) $cached['capture_date']
                : (($fp = resolvePath($f['path'], $mediaBase)) ? getMediaCaptureDate($fp, $f['type']) : 0);
        }
        unset($f);
        usort($files, $sort === 'date_desc'
            ? fn($a, $b) => $b['_date'] - $a['_date']
            : fn($a, $b) => $a['_date'] - $b['_date']
        );
        foreach ($files as &$f) unset($f['_date']);
        unset($f);
    }
    // name_asc: already in alphabetical order from scandir + sort()

    // Strip the temporary _mtime helper field from all folder entries.
    foreach ($folders as &$f) unset($f['_mtime']);
    unset($f);

    $response->getBody()->write(json_encode([
        'folders'        => $folders,
        'files'          => $files,
        'sort'           => $sort,
        'pinned_preview' => $index['pinned_preview'] ?? null,
        'warnings'       => $warnings,
    ]));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Route: GET /thumbnail ─────────────────────────────────────────────────────
//
// For photo/video: checks the cache index first. On a valid cache hit, issues a
// 302 redirect to the static file URL so Apache serves it directly. On a miss,
// generates the thumbnail, writes it to the cache directory, updates the index,
// then redirects to the newly created file.
//
// Audio thumbnails are not cached (the frontend uses an inline SVG instead).

$app->get('/thumbnail', function (Request $request, Response $response) use ($config): Response {
    $params  = $request->getQueryParams();
    $relPath = ltrim($params['path'] ?? '', '/');

    if ($relPath === '') {
        $response->getBody()->write(placeholderPng());
        return $response->withHeader('Content-Type', 'image/png')
                        ->withHeader('X-Thumbnail-Error', 'Missing path parameter');
    }

    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $fullPath  = resolvePath($relPath, $mediaBase);

    if ($fullPath === false || !is_file($fullPath)) {
        $response->getBody()->write(placeholderPng());
        return $response->withHeader('Content-Type', 'image/png')
                        ->withHeader('X-Thumbnail-Error', 'File not found');
    }

    $type = getMediaType(basename($fullPath), $config['extensions']);
    $maxW = (int) $config['thumb_max_width'];
    $maxH = (int) $config['thumb_max_height'];

    // Audio: serve placeholder PNG inline, no cache.
    if ($type === 'audio') {
        $img = imagecreatetruecolor(400, 400);
        $bg  = imagecolorallocate($img, 30, 30, 40);
        $fg  = imagecolorallocate($img, 160, 160, 255);
        imagefilledrectangle($img, 0, 0, 400, 400, $bg);
        imagestring($img, 5, 160, 185, '  Audio', $fg);
        ob_start(); imagepng($img); imagedestroy($img);
        $response->getBody()->write(ob_get_clean());
        return $response->withHeader('Content-Type', 'image/png');
    }

    // ── Cache lookup ──────────────────────────────────────────────────────────
    $cInfo      = cacheInfoForRelPath($relPath, $config);
    $fname      = basename($relPath);
    $folderRel  = dirname($relPath);           // '.' when file is in root
    $indexPath  = indexJsonPath($folderRel, $config);
    $index      = readIndex($indexPath);
    $srcMtime   = @filemtime($fullPath) ?: 0;

    $cached  = $index['files'][$fname] ?? null;
    $stale   = $cached !== null && (int)($cached['source_mtime'] ?? 0) !== $srcMtime;
    $diskHit = is_file($cInfo['fsPath']);

    // Serve from cache when the physical file exists and the source has not changed.
    // A missing index entry (common during concurrent generation — browsers open
    // several parallel connections that all do a read-modify-write on the same
    // _index.json) is NOT treated as a miss; the file's presence on disk is enough.
    if ($diskHit && !$stale) {
        return $response
            ->withHeader('Location', $cached !== null ? $cached['thumbnail_url'] : $cInfo['url'])
            ->withStatus(302);
    }

    // ── Generate thumbnail ────────────────────────────────────────────────────
    $jpg = ($type === 'video')
        ? generateVideoThumb($fullPath, $config)
        : generatePhotoThumb($fullPath, $maxW, $maxH);

    if ($jpg === false || $jpg === '') {
        $response->getBody()->write(placeholderPng());
        return $response->withHeader('Content-Type', 'image/png')
                        ->withHeader('X-Thumbnail-Error', 'Generation failed');
    }

    // ── Save to cache & update index ──────────────────────────────────────────
    if (!is_dir($cInfo['dir'])) @mkdir($cInfo['dir'], 0755, true);
    @file_put_contents($cInfo['fsPath'], $jpg);

    $meta = ($type === 'photo')
        ? extractPhotoMetadata($fullPath)
        : extractVideoMetadata($fullPath, $config);

    updateIndexFile($indexPath, $fname, [
        'type'          => $type,
        'source_mtime'  => $srcMtime,
        'capture_date'  => getMediaCaptureDate($fullPath, $type),
        'thumbnail_url' => $cInfo['url'],
        'meta'          => $meta,
    ], $config['cache_path']);

    return $response
        ->withHeader('Location', $cInfo['url'])
        ->withStatus(302);
});

// ── Route: GET /media ─────────────────────────────────────────────────────────

$app->get('/media', function (Request $request, Response $response) use ($config): Response {
    $params  = $request->getQueryParams();
    $relPath = $params['path'] ?? '';

    if ($relPath === '') return $response->withStatus(400);

    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $fullPath  = resolvePath($relPath, $mediaBase);

    if ($fullPath === false || !is_file($fullPath)) return $response->withStatus(404);

    $ext  = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = match($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'mp4'         => 'video/mp4',
        'mov'         => 'video/quicktime',
        'avi'         => 'video/x-msvideo',
        'mkv'         => 'video/x-matroska',
        'mp3'         => 'audio/mpeg',
        'wav'         => 'audio/wav',
        'flac'        => 'audio/flac',
        default       => 'application/octet-stream',
    };

    $fileSize    = filesize($fullPath);
    $rangeHeader = $request->getHeaderLine('Range');

    if ($rangeHeader !== '') {
        if (!preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $m)) {
            return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $fileSize);
        }
        $start = $m[1] !== '' ? (int)$m[1] : 0;
        $end   = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
        if ($end >= $fileSize) $end = $fileSize - 1;
        if ($start > $end) {
            return $response->withStatus(416)->withHeader('Content-Range', 'bytes */' . $fileSize);
        }
        $length = $end - $start + 1;
        $fh = fopen($fullPath, 'rb'); fseek($fh, $start); $data = fread($fh, $length); fclose($fh);
        $response->getBody()->write($data);
        return $response->withStatus(206)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string)$length)
            ->withHeader('Content-Range', "bytes {$start}-{$end}/{$fileSize}")
            ->withHeader('Accept-Ranges', 'bytes');
    }

    $response->getBody()->write(file_get_contents($fullPath));
    return $response
        ->withHeader('Content-Type', $mime)
        ->withHeader('Content-Length', (string)$fileSize)
        ->withHeader('Accept-Ranges', 'bytes');
});

// ── Route: GET /cache/list ────────────────────────────────────────────────────
//
// Returns a flat list of all cacheable (non-audio) files under a folder path,
// recursively. Used by the frontend to drive bulk thumbnail generation.
// PIN-protected via the 'pin' query parameter.

$app->get('/cache/list', function (Request $request, Response $response) use ($config): Response {
    $params = $request->getQueryParams();

    if (($params['pin'] ?? '') !== $config['cache_pin']) {
        $response->getBody()->write(json_encode(['error' => 'Invalid PIN']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $relPath   = ltrim($params['path'] ?? '', '/');
    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $dir       = ($relPath === '')
        ? $mediaBase
        : resolvePath($relPath, $mediaBase);

    if ($dir === false || !is_dir($dir)) {
        $response->getBody()->write(json_encode(['error' => 'Invalid path']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $files = [];
    collectFilesRecursively($dir, $relPath, $config, $files);

    $response->getBody()->write(json_encode(['files' => $files]));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Route: POST /cache/build ──────────────────────────────────────────────────
//
// Generates and caches the thumbnail for a single file.
// Body (JSON): { "pin": "…", "path": "relative/file.jpg", "force": false }
// On success:  { "ok": true,  "skipped": bool, "url": "…" }
// On failure:  { "ok": false, "error": "…" }
// PIN-protected via the 'pin' body field.

$app->post('/cache/build', function (Request $request, Response $response) use ($config): Response {
    $body  = json_decode((string) $request->getBody(), true) ?? [];
    $json  = fn(array $d, int $s = 200) => $response
        ->withStatus($s)
        ->withHeader('Content-Type', 'application/json');

    if (($body['pin'] ?? '') !== $config['cache_pin']) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid PIN']));
        return $json([], 401);
    }

    $relPath   = ltrim($body['path'] ?? '', '/');
    $force     = (bool)($body['force'] ?? false);
    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $fullPath  = resolvePath($relPath, $mediaBase);

    if ($fullPath === false || !is_file($fullPath)) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'File not found']));
        return $json([], 404);
    }

    $type = getMediaType(basename($fullPath), $config['extensions']);
    if ($type === 'audio' || $type === null) {
        $response->getBody()->write(json_encode(['ok' => true, 'skipped' => true, 'reason' => 'audio_or_unsupported']));
        return $json([]);
    }

    $cInfo     = cacheInfoForRelPath($relPath, $config);
    $fname     = basename($relPath);
    $folderRel = dirname($relPath);
    $indexPath = indexJsonPath($folderRel, $config);
    $index     = readIndex($indexPath);
    $srcMtime  = @filemtime($fullPath) ?: 0;

    // Skip if cache is valid and force is not requested.
    if (!$force) {
        $cached = $index['files'][$fname] ?? null;
        if (
            $cached !== null &&
            ($cached['source_mtime'] ?? 0) === $srcMtime &&
            is_file($cInfo['fsPath'])
        ) {
            $response->getBody()->write(json_encode([
                'ok'      => true,
                'skipped' => true,
                'reason'  => 'already_cached',
                'url'     => $cached['thumbnail_url'],
            ]));
            return $json([]);
        }
    }

    // Generate thumbnail.
    $maxW = (int) $config['thumb_max_width'];
    $maxH = (int) $config['thumb_max_height'];
    $jpg  = ($type === 'video')
        ? generateVideoThumb($fullPath, $config)
        : generatePhotoThumb($fullPath, $maxW, $maxH);

    if ($jpg === false || $jpg === '') {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Thumbnail generation failed', 'path' => $relPath]));
        return $json([]);
    }

    // Write to cache.
    if (!is_dir($cInfo['dir']) && !@mkdir($cInfo['dir'], 0755, true)) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Cannot create cache directory']));
        return $json([]);
    }
    if (@file_put_contents($cInfo['fsPath'], $jpg) === false) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Cannot write cache file']));
        return $json([]);
    }

    // Update index (locked write so concurrent bulk-generation requests don't
    // overwrite each other's entries in the same _index.json).
    $meta = ($type === 'photo')
        ? extractPhotoMetadata($fullPath)
        : extractVideoMetadata($fullPath, $config);

    updateIndexFile($indexPath, $fname, [
        'type'          => $type,
        'source_mtime'  => $srcMtime,
        'capture_date'  => getMediaCaptureDate($fullPath, $type),
        'thumbnail_url' => $cInfo['url'],
        'meta'          => $meta,
    ], $config['cache_path']);

    $response->getBody()->write(json_encode(['ok' => true, 'skipped' => false, 'url' => $cInfo['url']]));
    return $json([]);
});

// ── Route: POST /cache/delete ─────────────────────────────────────────────────
//
// Deletes all cached thumbnail files for a given folder path (recursively)
// without regenerating them.
// Body (JSON): { "pin": "…", "path": "relative/folder" }
// On success:  { "ok": true, "deleted": N }
// On failure:  { "ok": false, "error": "…" }
// PIN-protected.

$app->post('/cache/delete', function (Request $request, Response $response) use ($config): Response {
    $body = json_decode((string) $request->getBody(), true) ?? [];

    if (($body['pin'] ?? '') !== $config['cache_pin']) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid PIN']));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }

    $relPath   = ltrim($body['path'] ?? '', '/');
    $cacheRoot = rtrim($config['cache_path'], '/');

    if ($relPath === '' || $relPath === '.') {
        $deleted = deleteCachePath($cacheRoot, true);
    } else {
        $parts     = explode('/', $relPath);
        $sanitized = implode('/', array_filter(array_map('sanitizeName', $parts), fn($d) => $d !== ''));
        $cacheDir  = $cacheRoot . '/' . $sanitized;

        // Safety: ensure the resolved path is within the cache root.
        $resolved = realpath($cacheDir) ?: $cacheDir;
        if (!str_starts_with($resolved . '/', $cacheRoot . '/')) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid path']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $deleted = deleteCachePath($cacheDir, false);
        if (is_dir($cacheDir)) @rmdir($cacheDir);
    }

    $response->getBody()->write(json_encode(['ok' => true, 'deleted' => $deleted]));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Route: GET /metadata ──────────────────────────────────────────────────────
//
// Returns stored (or on-the-fly extracted) metadata for a single file.
// Reads from the cache index when available; falls back to direct extraction.
// Query params: path=relative/file.jpg

$app->get('/metadata', function (Request $request, Response $response) use ($config): Response {
    $params  = $request->getQueryParams();
    $relPath = ltrim($params['path'] ?? '', '/');

    if ($relPath === '') {
        $response->getBody()->write(json_encode(['error' => 'Missing path']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $mediaBase = rtrim($config['media_base_path'], '/\\');
    $fullPath  = resolvePath($relPath, $mediaBase);

    if ($fullPath === false || !is_file($fullPath)) {
        $response->getBody()->write(json_encode(['error' => 'File not found']));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    $fname     = basename($relPath);
    $folderRel = dirname($relPath);
    $indexPath = indexJsonPath($folderRel, $config);
    $index     = readIndex($indexPath);
    $cached    = $index['files'][$fname] ?? null;

    // Return cached metadata when available.
    if ($cached && !empty($cached['meta'])) {
        $response->getBody()->write(json_encode($cached['meta']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // Extract on-the-fly if cache miss (thumbnail not yet generated).
    $type = getMediaType($fname, $config['extensions']);
    $meta = match($type) {
        'photo' => extractPhotoMetadata($fullPath),
        'video' => extractVideoMetadata($fullPath, $config),
        default => ['file_name' => $fname, 'file_size' => @filesize($fullPath) ?: null],
    };

    $response->getBody()->write(json_encode($meta));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Route: POST /folder/preview ───────────────────────────────────────────────
//
// Permanently pins a specific file as the preview thumbnail for its parent folder.
// Body (JSON): { "file_path": "relative/path/to/file.jpg" }
// Stored as 'pinned_preview' in the folder's _index.json.
// Cleared by sending { "file_path": "" } or omitting the field.

$app->post('/folder/preview', function (Request $request, Response $response) use ($config): Response {
    $body    = json_decode((string) $request->getBody(), true) ?? [];
    $relPath = ltrim($body['file_path'] ?? '', '/');

    // Allow clearing the pin with an empty path.
    if ($relPath !== '') {
        $mediaBase = rtrim($config['media_base_path'], '/\\');
        $fullPath  = resolvePath($relPath, $mediaBase);
        if ($fullPath === false || !is_file($fullPath)) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'File not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
    }

    // Derive the parent folder's index path.
    $folderRel = $relPath !== '' ? ltrim(dirname($relPath), '.') : '';
    $indexPath = indexJsonPath($folderRel, $config);

    $dir = dirname($indexPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $htaccess = rtrim($config['cache_path'], '/') . '/.htaccess';
    if (!file_exists($htaccess)) @file_put_contents($htaccess, "Options -Indexes\n");

    $fh = @fopen($indexPath, 'c+');
    if ($fh === false) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Cannot open index']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
    if (!flock($fh, LOCK_EX)) { fclose($fh); return $response->withStatus(500); }

    $content = stream_get_contents($fh);
    $data    = json_decode($content ?: '', true);
    if (!is_array($data)) {
        $data = ['updated_at' => 0, 'files' => [], 'folders' => []];
    } else {
        $data = array_merge(['updated_at' => 0, 'files' => [], 'folders' => []], $data);
    }

    if ($relPath !== '') {
        $data['pinned_preview'] = $relPath;
    } else {
        unset($data['pinned_preview']);
    }
    $data['updated_at'] = time();

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    $response->getBody()->write(json_encode(['ok' => true]));
    return $response->withHeader('Content-Type', 'application/json');
});

// ── Route: POST /sort ─────────────────────────────────────────────────────────
//
// Persists the user's chosen sort order for a folder in its _index.json.
// Body (JSON): { "path": "relative/folder", "sort": "date_asc"|"date_desc"|"name_asc"|"name_desc" }

$app->post('/sort', function (Request $request, Response $response) use ($config): Response {
    $body  = json_decode((string) $request->getBody(), true) ?? [];
    $sort  = $body['sort'] ?? '';
    $valid = ['date_asc', 'date_desc', 'name_asc', 'name_desc'];

    if (!in_array($sort, $valid, true)) {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid sort value']));
        return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
    }

    $relPath   = ltrim($body['path'] ?? '', '/');
    $indexPath = indexJsonPath($relPath, $config);
    $index     = readIndex($indexPath);

    $index['sort']       = $sort;
    $index['updated_at'] = time();
    writeIndex($indexPath, $index, $config['cache_path']);

    $response->getBody()->write(json_encode(['ok' => true]));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
