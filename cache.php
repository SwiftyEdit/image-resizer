<?php
/**
 * Shared helpers for the image variant cache: loading settings, scanning
 * the cache directory, and enforcing the configured age/size/file-count
 * limits.
 *
 * Bootstrap-free safe - required both from endpoint.php (no DB/session,
 * see public/dispatch.php) and from this plugin's backend/*.php ACP pages
 * (full ACP bootstrap). Never assume anything beyond the SE_* path
 * constants and plain filesystem access.
 */

const IMGRES_CACHE_DIR = SE_CONTENT . '/cache/images';
const IMGRES_DEFAULT_QUALITY = 82;

/**
 * What this server's GD build can actually do. Checked live on every call
 * (cheap - a few function_exists()/extension_loaded() checks, no I/O) so
 * the ACP always reflects the real, current server, e.g. right after a PHP
 * version/extension change - never cached/stale.
 *
 * @return array{gd:bool, webp:bool, avif:bool}
 */
function imgres_gd_status()
{
    $gd = extension_loaded('gd');
    return [
        'gd' => $gd,
        'webp' => $gd && function_exists('imagewebp'),
        'avif' => $gd && function_exists('imageavif'),
    ];
}

/**
 * Load the plugin's persisted preferences, merged over safe defaults so a
 * missing/partial config.php never causes undefined-key issues.
 */
function imgres_load_prefs()
{
    $defaults = [
        'quality' => IMGRES_DEFAULT_QUALITY,
        'max_cache_age_days' => '',
        'max_cache_size_mb' => '',
        'max_cache_files' => '',
    ];

    $configFile = SE_PLUGINS . '/image-resizer/config.php';
    $imgres_prefs = null;
    if (is_file($configFile)) {
        include $configFile;
    }

    return array_merge($defaults, is_array($imgres_prefs) ? $imgres_prefs : []);
}

/**
 * Recursively list every cache file with its mtime and size. Cost is O(n)
 * in the number of cached files - on a very large cache (hundreds of
 * thousands of files, as can happen on hosts with generous storage but no
 * strict file-count limit) this can take a noticeable moment. Acceptable
 * here because it only ever runs on an admin-triggered "clear cache" click,
 * the settings page's stats readout, or a low-probability lazy sweep (see
 * imgres_maybe_lazy_sweep()) - never on the hot image-serving path itself.
 */
function imgres_scan_cache_files($dir)
{
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = [
                'path' => $file->getPathname(),
                'mtime' => $file->getMTime(),
                'size' => $file->getSize(),
            ];
        }
    }

    return $files;
}

/**
 * Enforce the configured age/size/file-count limits, or wipe the whole
 * cache when $force is true (manual "clear now" button). Oldest files are
 * evicted first for the size/count limits.
 *
 * @return array{deleted:int, remaining:int, remaining_bytes:int}
 */
function imgres_sweep_cache($force = false)
{
    $dir = IMGRES_CACHE_DIR;
    $files = imgres_scan_cache_files($dir);
    $deleted = 0;

    if ($force) {
        foreach ($files as $file) {
            if (@unlink($file['path'])) {
                $deleted++;
            }
        }
        imgres_remove_empty_dirs($dir);
        return ['deleted' => $deleted, 'remaining' => 0, 'remaining_bytes' => 0];
    }

    $prefs = imgres_load_prefs();

    // 1) age limit - remove anything older than the cutoff outright.
    if ($prefs['max_cache_age_days'] !== '') {
        $cutoff = time() - ((int) $prefs['max_cache_age_days'] * 86400);
        foreach ($files as $key => $file) {
            if ($file['mtime'] < $cutoff && @unlink($file['path'])) {
                $deleted++;
                unset($files[$key]);
            }
        }
    }

    // Oldest-first, for the size/count limits below.
    usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);

    // 2) total size limit.
    if ($prefs['max_cache_size_mb'] !== '') {
        $limitBytes = (int) $prefs['max_cache_size_mb'] * 1024 * 1024;
        $totalBytes = array_sum(array_column($files, 'size'));
        while ($totalBytes > $limitBytes && !empty($files)) {
            $victim = array_shift($files);
            if (@unlink($victim['path'])) {
                $deleted++;
                $totalBytes -= $victim['size'];
            }
        }
    }

    // 3) file-count limit (relevant on hosts that cap inode/file counts
    // rather than disk space, e.g. shared hosting plans).
    if ($prefs['max_cache_files'] !== '') {
        $limitCount = (int) $prefs['max_cache_files'];
        while (count($files) > $limitCount && !empty($files)) {
            $victim = array_shift($files);
            if (@unlink($victim['path'])) {
                $deleted++;
            }
        }
    }

    imgres_remove_empty_dirs($dir);

    return [
        'deleted' => $deleted,
        'remaining' => count($files),
        'remaining_bytes' => array_sum(array_column($files, 'size')),
    ];
}

/**
 * Remove now-empty shard subdirectories left behind after eviction, so the
 * cache dir doesn't accumulate thousands of empty two-character folders.
 */
function imgres_remove_empty_dirs($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob(rtrim($dir, '/') . '/*', GLOB_ONLYDIR) as $sub) {
        @rmdir($sub); // best-effort: rmdir() silently no-ops on non-empty dirs
    }
}

/**
 * Low-probability opportunistic cleanup, called after writing a new cache
 * file. No cron exists in SwiftyEdit, so this is the self-maintaining
 * alternative: most requests do nothing extra, roughly 1 in 100 trigger a
 * real sweep, spreading the (potentially non-trivial, see
 * imgres_scan_cache_files()) cost out instead of paying it on every single
 * image request.
 */
function imgres_maybe_lazy_sweep()
{
    if (random_int(1, 100) === 1) {
        imgres_sweep_cache(false);
    }
}
