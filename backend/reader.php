<?php
/**
 * @var string $plugin_root set by acp/core/addons/data-reader.php
 */

require_once $plugin_root . 'cache.php';

if (($_GET['show'] ?? '') === 'stats') {
    $files = imgres_scan_cache_files(IMGRES_CACHE_DIR);
    $totalBytes = array_sum(array_column($files, 'size'));

    echo '<div class="d-flex gap-2 flex-wrap">';
    echo '<span class="badge text-bg-secondary">' . count($files) . ' Dateien im Cache</span>';
    echo '<span class="badge text-bg-secondary">' . readable_filesize($totalBytes) . '</span>';
    echo '</div>';
    exit;
}
