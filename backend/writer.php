<?php
/**
 * @var string $plugin_root set by acp/core/addons/data-writer.php
 */

require_once $plugin_root . 'cache.php';

if (isset($_POST['save_imgres_prefs'])) {

    $config_content = file_get_contents($plugin_root . 'config.tpl');

    $quality = max(1, min(100, (int) sanitizeUserInputs($_POST['quality'])));
    $max_age = trim(sanitizeUserInputs($_POST['max_cache_age_days']));
    $max_size = trim(sanitizeUserInputs($_POST['max_cache_size_mb']));
    $max_files = trim(sanitizeUserInputs($_POST['max_cache_files']));

    $config_content = str_replace('{quality}', (string) $quality, $config_content);
    $config_content = str_replace('{max_cache_age_days}', $max_age === '' ? '' : (string) max(0, (int) $max_age), $config_content);
    $config_content = str_replace('{max_cache_size_mb}', $max_size === '' ? '' : (string) max(0, (int) $max_size), $config_content);
    $config_content = str_replace('{max_cache_files}', $max_files === '' ? '' : (string) max(0, (int) $max_files), $config_content);

    if (file_put_contents($plugin_root . 'config.php', $config_content, LOCK_EX)) {
        show_toast('Einstellungen gespeichert', 'success');
    }

    header('HX-Trigger: cache_changed'); // limits may have changed - refresh the stats readout
    exit;
}

if (isset($_POST['clear_image_cache'])) {
    $result = imgres_sweep_cache(true);
    show_toast($result['deleted'] . ' Cache-Dateien gelöscht', 'success');
    header('HX-Trigger: cache_changed');
    exit;
}
