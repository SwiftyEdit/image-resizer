<?php
/**
 * @var string $this_addon_root set by acp/core/addons/edit-plugin.php
 * @var string $hidden_csrf_token set by acp/core/access.php
 */

require_once $this_addon_root . '/cache.php';

$prefs = imgres_load_prefs();

echo '<div id="response"></div>';
echo $hidden_csrf_token;

$gd = imgres_gd_status();

echo '<div class="card p-3 mb-3">';
echo '<h5>Server</h5>';
echo '<div class="d-flex gap-2 flex-wrap">';
echo '<span class="badge ' . ($gd['gd'] ? 'text-bg-success' : 'text-bg-danger') . '">GD ' . ($gd['gd'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '<span class="badge ' . ($gd['webp'] ? 'text-bg-success' : 'text-bg-secondary') . '">WebP ' . ($gd['webp'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '<span class="badge ' . ($gd['avif'] ? 'text-bg-success' : 'text-bg-secondary') . '">AVIF ' . ($gd['avif'] ? 'verfügbar' : 'nicht verfügbar') . '</span>';
echo '</div>';
if (!$gd['gd']) {
    echo '<p class="text-danger small mt-2 mb-0">Ohne die PHP-GD-Erweiterung liefert dieses Plugin Bilder unverändert aus (kein Resizing, keine Formatoptimierung). Bitte GD auf dem Server aktivieren.</p>';
} elseif (!$gd['avif']) {
    echo '<p class="text-muted small mt-2 mb-0">AVIF wird auf diesem Server nicht unterstützt - Bilder werden stattdessen als WebP bzw. JPEG ausgeliefert.</p>';
}
echo '</div>';

echo '<div class="card p-3 mb-3">';
echo '<h5>Cache</h5>';
echo '<div hx-get="/admin-xhr/addons/plugin/image-resizer/read/?show=stats" hx-trigger="load, cache_changed from:body" class="mb-3">Loading ...</div>';
echo '<button type="button" hx-post="/admin-xhr/addons/plugin/image-resizer/write/" hx-target="#response" hx-include="[name=\'csrf_token\']" hx-confirm="Alle zwischengespeicherten Bildvarianten wirklich löschen? Sie werden beim nächsten Aufruf automatisch neu erzeugt." name="clear_image_cache" value="1" class="btn btn-outline-danger">Cache jetzt leeren</button>';
echo '</div>';

echo '<div class="card p-3">';
echo '<h5>Einstellungen</h5>';
echo '<form hx-post="/admin-xhr/addons/plugin/image-resizer/write/" hx-target="#response" hx-include="[name=\'csrf_token\']" method="POST">';

echo '<div class="mb-3">';
echo '<label class="form-label">JPEG/WebP/AVIF Qualität (1-100)</label>';
echo '<input class="form-control" type="number" min="1" max="100" name="quality" value="' . htmlspecialchars($prefs['quality']) . '">';
echo '</div>';

echo '<div class="mb-3">';
echo '<label class="form-label">Varianten löschen nach (Tagen, leer = kein Limit)</label>';
echo '<input class="form-control" type="number" min="0" name="max_cache_age_days" value="' . htmlspecialchars($prefs['max_cache_age_days']) . '">';
echo '</div>';

echo '<div class="mb-3">';
echo '<label class="form-label">Maximale Cache-Größe in MB (leer = kein Limit)</label>';
echo '<input class="form-control" type="number" min="0" name="max_cache_size_mb" value="' . htmlspecialchars($prefs['max_cache_size_mb']) . '">';
echo '</div>';

echo '<div class="mb-3">';
echo '<label class="form-label">Maximale Anzahl Cache-Dateien (leer = kein Limit, relevant bei Hosting-Limits auf Datei-/Inode-Anzahl statt Speicherplatz)</label>';
echo '<input class="form-control" type="number" min="0" name="max_cache_files" value="' . htmlspecialchars($prefs['max_cache_files']) . '">';
echo '</div>';

echo '<p class="text-muted small">Alter/Größe/Anzahl werden zusätzlich zum manuellen Leeren automatisch mit geringer Wahrscheinlichkeit bei jedem neu erzeugten Bild geprüft (kein Cron nötig) - kein Limit greift sofort, sondern erst mit der Zeit.</p>';

echo '<button type="submit" class="btn btn-default" name="save_imgres_prefs">Speichern</button>';
echo '</form>';
echo '</div>';
