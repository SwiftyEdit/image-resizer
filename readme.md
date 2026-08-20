# Image Resizer

On-the-fly image resizing and format optimization for the `{img}` Smarty tag:
responsive width variants, automatic AVIF/WebP/JPEG negotiation, and disk
caching. Requires the GD extension.

Without this plugin active, `{img}` still renders (a plain `<img src>`, no
variants) - it never becomes a hard dependency for a theme that uses it.

## Usage

In any Smarty template:

```
{img src="/images/foo.jpg" widths="400,800,1200" ratio="4:3" fit="cover"
     sizes="(max-width: 600px) 100vw, 800px" alt="..." class="..." loading="lazy"}
```

- `src` (required) - image path as already used elsewhere, e.g. `/images/foo.jpg`
- `widths` - comma-separated target widths in px; generates one `srcset` entry per width
- `ratio` - optional target aspect ratio, e.g. `4:3`
- `fit` - `cover` (crop-fill, default) or `contain` (whole image visible, no crop)
- `sizes` - passed straight through to the `sizes` attribute (pure layout concern, not computed by this plugin)
- `alt`, `class`, `id`, `loading`, `title`, `width`, `height` - passed straight through

Format negotiation (AVIF -> WebP -> JPEG) happens per request via the
`Accept` header, not via `<picture>` - each `srcset` URL picks its own best
format server-side.

## Features

- Crop-fill (`cover`) or fit-inside (`contain`) resizing via GD
- Automatic AVIF/WebP/JPEG selection based on browser support, with graceful
  fallback if a format isn't available on the server
- Animated GIFs are served unmodified (GD would flatten them to a single frame)
- Disk cache with far-future, correctly invalidated `Cache-Control: immutable`
  responses (cache-busted by the source file's modification time)
- Configurable JPEG/WebP/AVIF quality, and cache limits by age (days), total
  size (MB), and file count - useful on shared hosting plans that cap the
  number of files/inodes rather than disk space
- Manual "clear cache now" button plus a low-probability automatic sweep on
  cache writes (no cron required)

## Requirements

- PHP GD extension. AVIF output additionally requires a GD build with AVIF
  support (`imageavif()`) - falls back to WebP/JPEG otherwise.

## License

GPL-3.0-or-later, see `license.txt`.
