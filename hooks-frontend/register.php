<?php
/**
 * Registers this plugin as the provider for the 'image.variants' frontend
 * filter hook (see app/hooks/hooks-map.php and app/functions/functions.img.php).
 *
 * Loaded automatically by app/routing.php for every active plugin that has
 * a hooks-frontend/ directory - no manual registration needed elsewhere.
 *
 * This file only builds request URLs, one per requested width. All actual
 * resizing, caching, and format negotiation happens later - in the
 * bootstrap-free endpoint at public/dispatch.php?p=image-resizer ->
 * plugins/image-resizer/endpoint.php - once a browser actually requests one
 * of these URLs.
 */

se_add_frontend_hook('image.variants', 'imgres_provide_variants');

/**
 * @param mixed $value Current filter value (null unless another plugin already handled it)
 * @param array $context ['src' => string, 'widths' => string, 'ratio' => string, 'fit' => string]
 * @return mixed ['src' => string, 'srcset' => string] or $value unchanged if there's nothing to add
 */
function imgres_provide_variants($value, $context)
{
    if ($value !== null) {
        // Another plugin already provided variants - don't stomp on it.
        return $value;
    }

    $src = $context['src'] ?? '';
    $widthsRaw = $context['widths'] ?? '';

    if ($src === '' || $widthsRaw === '') {
        // No widths requested - nothing for this plugin to add, leave the
        // {img} tag to fall back to a plain <img src>.
        return $value;
    }

    // Source file has to actually exist, otherwise don't hand out variant
    // URLs at all - better to fall back to the (already broken) plain <img
    // src> than to add a resizer 404 on top of a missing source image.
    $mtime = imgres_source_mtime($src);
    if ($mtime === null) {
        return $value;
    }

    $widths = array_filter(array_map('intval', explode(',', $widthsRaw)), fn($w) => $w > 0);
    sort($widths);

    if (empty($widths)) {
        return $value;
    }

    $ratio = $context['ratio'] ?? '';
    $fit = $context['fit'] ?? 'cover';

    $srcsetParts = [];
    $largestUrl = '';

    foreach ($widths as $width) {
        $url = imgres_build_url($src, $width, $ratio, $fit, $mtime);
        $srcsetParts[] = $url . ' ' . $width . 'w';
        $largestUrl = $url; // widths are sorted ascending, so the last one is the largest
    }

    return [
        'src'    => $largestUrl,
        'srcset' => implode(', ', $srcsetParts),
    ];
}

/**
 * Resolve a "/images/..." src to its real file and return its mtime, or
 * null if it doesn't resolve to an existing file under public/assets/images/.
 */
function imgres_source_mtime($src)
{
    if (!str_starts_with($src, '/images/')) {
        return null;
    }

    $relative = substr($src, strlen('/images/'));
    $resolved = se_resolve_within(SE_PUBLIC . '/assets/images', $relative);

    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    return filemtime($resolved);
}

function imgres_build_url($src, $width, $ratio, $fit, $mtime)
{
    // "v" is a pure cache-buster for browsers/CDNs so that Cache-Control:
    // immutable (set by the endpoint) is actually true - if the source file
    // is replaced, its mtime changes and so does this URL. The endpoint
    // itself never trusts this value; it always re-reads the real mtime.
    $params = ['p' => 'image-resizer', 'src' => $src, 'w' => $width, 'v' => $mtime];

    if ($ratio !== '') {
        $params['ratio'] = $ratio;
    }
    if ($fit !== '') {
        $params['fit'] = $fit;
    }

    return '/dispatch.php?' . http_build_query($params);
}
