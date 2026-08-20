<?php
/**
 * Actual image resize/optimization endpoint. Required by
 * public/dispatch.php?p=image-resizer, never called directly.
 *
 * Runs WITHOUT the SwiftyEdit app bootstrap (see public/dispatch.php) - only the
 * SE_* path constants from config.php are available here. Do not assume
 * $db_content/$se_settings/session/etc. exist. The only core helper reused
 * here is se_resolve_within() (path confinement), pulled in directly below
 * instead of the full functions.php chain.
 *
 * Query params (built by hooks-frontend/register.php):
 *   src   - original image path, e.g. "/images/foo.jpg"
 *   w     - target width in pixels
 *   ratio - optional target aspect ratio, e.g. "4:3"
 *   fit   - "cover" (crop-fill) or "contain" (whole image visible), default "cover"
 *   v     - cache-buster only (source mtime at render time), never trusted
 *           for logic - the real mtime is always re-read from disk below.
 *
 * Known limitations (v1, not yet addressed):
 *   - Animated GIFs are served as-is, never resized (GD would flatten them
 *     to a single frame).
 *
 * Quality and cache age/size/file-count limits are configurable in the ACP
 * (backend/settings.php), persisted via config.php, and enforced here via
 * cache.php's imgres_maybe_lazy_sweep() after every cache write.
 */

require SE_ROOT . 'app/functions/functions.sanitizer.php';
require __DIR__ . '/cache.php';

const IMGRES_MIN_WIDTH = 10;
const IMGRES_MAX_WIDTH = 4000;

imgres_handle_request();

/**
 * Entry point - validates input, serves from cache if possible, otherwise
 * resizes, caches, and serves.
 */
function imgres_handle_request()
{
    $srcParam = $_GET['src'] ?? '';
    $width = imgres_clamp_width((int) ($_GET['w'] ?? 0));
    $ratio = imgres_parse_ratio($_GET['ratio'] ?? '');
    $fit = ($_GET['fit'] ?? '') === 'contain' ? 'contain' : 'cover';

    $sourcePath = imgres_resolve_source($srcParam);
    if ($sourcePath === null) {
        http_response_code(404);
        exit;
    }

    // No GD, or the request doesn't even ask for a resize - just serve the
    // original file with the same caching headers a variant would get.
    if (!extension_loaded('gd') || $width === null) {
        imgres_send_file($sourcePath, mime_content_type($sourcePath) ?: 'application/octet-stream');
    }

    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) {
        // Not a raster format GD understands (e.g. SVG) - pass through.
        imgres_send_file($sourcePath, mime_content_type($sourcePath) ?: 'application/octet-stream');
    }
    [$srcWidth, $srcHeight, $sourceType] = $imageInfo;

    // Animated GIFs would lose their animation if run through GD - keep
    // them untouched rather than silently breaking them.
    if ($sourceType === IMAGETYPE_GIF) {
        imgres_send_file($sourcePath, 'image/gif');
    }

    $format = imgres_negotiate_format();
    $mtime = filemtime($sourcePath);

    $cacheKey = md5($sourcePath . '|' . $mtime . '|' . $width . '|' . $ratio . '|' . $fit . '|' . $format);
    $cachePath = IMGRES_CACHE_DIR . '/' . substr($cacheKey, 0, 2) . '/' . $cacheKey . '.' . $format;

    if (is_file($cachePath)) {
        imgres_send_file($cachePath, imgres_mime_for_format($format));
    }

    $resized = imgres_resize($sourcePath, $sourceType, $srcWidth, $srcHeight, $width, $ratio, $fit, $format);
    if ($resized === null) {
        // Resize failed for any reason - never show a broken image, fall
        // back to the original file instead.
        imgres_send_file($sourcePath, mime_content_type($sourcePath) ?: 'application/octet-stream');
    }

    imgres_write_cache($cachePath, $resized, $format);
    imagedestroy($resized);
    imgres_maybe_lazy_sweep();

    imgres_send_file($cachePath, imgres_mime_for_format($format));
}

/**
 * Resolve "?src=/images/foo.jpg" to a real, confined file path, or null if
 * it's missing/outside the allowed directory. Only /images/... (the public
 * upload path) is ever served - this is not a general-purpose file proxy.
 */
function imgres_resolve_source($src)
{
    if (!is_string($src) || !str_starts_with($src, '/images/')) {
        return null;
    }

    $relative = substr($src, strlen('/images/'));
    $resolved = se_resolve_within(SE_PUBLIC . '/assets/images', $relative);

    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    return $resolved;
}

function imgres_clamp_width($width)
{
    if ($width <= 0) {
        return null;
    }
    return max(IMGRES_MIN_WIDTH, min(IMGRES_MAX_WIDTH, $width));
}

/**
 * Parse "W:H" into a float ratio (width/height), or null if absent/invalid.
 */
function imgres_parse_ratio($ratio)
{
    if (!preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', (string) $ratio, $m)) {
        return null;
    }
    $w = (float) $m[1];
    $h = (float) $m[2];
    if ($w <= 0 || $h <= 0) {
        return null;
    }
    return $w / $h;
}

/**
 * Pick the smallest/best format the requesting browser accepts, restricted
 * to what this GD build actually supports.
 */
function imgres_negotiate_format()
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

    if (function_exists('imageavif') && str_contains($accept, 'image/avif')) {
        return 'avif';
    }
    if (function_exists('imagewebp') && str_contains($accept, 'image/webp')) {
        return 'webp';
    }
    return 'jpeg';
}

function imgres_mime_for_format($format)
{
    return match ($format) {
        'avif' => 'image/avif',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => 'image/jpeg',
    };
}

/**
 * Load, crop/scale, and re-encode the source image. Returns a GD image
 * resource/object ready to be saved, or null on any failure.
 */
function imgres_resize($sourcePath, $sourceType, $srcWidth, $srcHeight, $targetWidth, $ratio, $fit, $format)
{
    $srcImage = match ($sourceType) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
        IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if ($srcImage === false) {
        return null;
    }

    $targetHeight = $ratio !== null
        ? (int) round($targetWidth / $ratio)
        : (int) round($targetWidth * $srcHeight / $srcWidth);
    $targetHeight = max(1, $targetHeight);

    if ($ratio !== null && $fit === 'cover') {
        // Crop-fill: pick the largest centered region of the source that
        // already has the target aspect ratio, then scale that region
        // directly onto the full target canvas in one resample call.
        $sourceRatio = $srcWidth / $srcHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $srcHeight;
            $cropWidth = (int) round($srcHeight * $targetRatio);
        } else {
            $cropWidth = $srcWidth;
            $cropHeight = (int) round($srcWidth / $targetRatio);
        }
        $cropX = (int) round(($srcWidth - $cropWidth) / 2);
        $cropY = (int) round(($srcHeight - $cropHeight) / 2);
    } else {
        // "contain" (or no ratio at all): use the whole source image, no
        // crop. For "contain" this can under-fill the ratio box on one
        // axis - that's the point (nothing gets cut off).
        if ($ratio !== null && $fit === 'contain') {
            $scale = min($targetWidth / $srcWidth, $targetHeight / $srcHeight);
            $targetWidth = max(1, (int) round($srcWidth * $scale));
            $targetHeight = max(1, (int) round($srcHeight * $scale));
        }
        $cropX = 0;
        $cropY = 0;
        $cropWidth = $srcWidth;
        $cropHeight = $srcHeight;
    }

    $hasAlpha = $sourceType === IMAGETYPE_PNG || $sourceType === IMAGETYPE_WEBP;
    $outputSupportsAlpha = $format === 'webp' || $format === 'avif';

    $dstImage = imagecreatetruecolor($targetWidth, $targetHeight);

    if ($hasAlpha && $outputSupportsAlpha) {
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefilledrectangle($dstImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    } else {
        // Output format has no alpha channel (or source has none) - flatten
        // onto white so transparent PNGs don't turn black in JPEG output.
        $white = imagecolorallocate($dstImage, 255, 255, 255);
        imagefilledrectangle($dstImage, 0, 0, $targetWidth, $targetHeight, $white);
    }

    imagecopyresampled(
        $dstImage, $srcImage,
        0, 0, $cropX, $cropY,
        $targetWidth, $targetHeight, $cropWidth, $cropHeight
    );

    imagedestroy($srcImage);

    return $dstImage;
}

/**
 * Encode $image to a temp file and rename() it into place atomically, so
 * concurrent requests for the same variant never see a partially-written
 * cache file.
 */
function imgres_write_cache($cachePath, $image, $format)
{
    $dir = dirname($cachePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $tmpPath = $cachePath . '.' . uniqid('', true) . '.tmp';
    $quality = (int) imgres_load_prefs()['quality'];

    match ($format) {
        'avif' => imageavif($image, $tmpPath, $quality),
        'webp' => imagewebp($image, $tmpPath, $quality),
        default => imagejpeg($image, $tmpPath, $quality),
    };

    rename($tmpPath, $cachePath);
}

/**
 * Stream a file with far-future caching headers and exit. Cache-Control:
 * immutable is only correct because URLs carry a "v" cache-buster tied to
 * the source file's mtime (see hooks-frontend/register.php) - the URL
 * itself changes whenever the underlying image changes.
 */
function imgres_send_file($path, $mime)
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Vary: Accept');
    readfile($path);
    exit;
}
