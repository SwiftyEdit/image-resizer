<?php
/**
 * @var string $this_addon_root set by acp/core/addons/edit-plugin.php
 */

require_once $this_addon_root . '/cache.php';

echo '<div class="card p-3 mb-3">';
echo '<p>On-the-fly image resizing and format optimization for the <code>{img}</code> Smarty tag: responsive width variants, automatic AVIF/WebP/JPEG negotiation, and disk caching. Requires the GD extension.</p>';

$gd = imgres_gd_status();
echo '<div class="d-flex gap-2 flex-wrap">';
echo '<span class="badge ' . ($gd['gd'] ? 'text-bg-success' : 'text-bg-danger') . '">GD ' . ($gd['gd'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '<span class="badge ' . ($gd['webp'] ? 'text-bg-success' : 'text-bg-secondary') . '">WebP ' . ($gd['webp'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '<span class="badge ' . ($gd['avif'] ? 'text-bg-success' : 'text-bg-secondary') . '">AVIF ' . ($gd['avif'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '</div>';
echo '</div>';

echo '<div hx-get="/admin-xhr/addons/plugin/image-resizer/read/?show=stats" hx-trigger="load, cache_changed from:body">Loading data ...</div>';
