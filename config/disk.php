<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server-side thumbnails
    |--------------------------------------------------------------------------
    |
    | The file grid shows a real raster thumbnail for formats the browser cannot
    | render as a tile on its own. Today only PDFs are supported (first page ->
    | PNG via poppler's `pdftoppm`, invoked out-of-process); the shape is kept
    | extensible for video/other formats later.
    |
    */

    'thumbnails' => [

        // Master switch. Off => ThumbnailService::render() always returns null and the endpoint
        // 404s, so the grid falls back to the type glyph.
        'enabled' => (bool) env('DISK_THUMBNAILS_ENABLED', true),

        // Path to poppler's `pdftoppm` binary (assumed on PATH). Invoked with ARRAY args through
        // Symfony Process — never a shell — so the stored file is never interpolated into a command.
        'pdftoppm_path' => env('DISK_PDFTOPPM_PATH', 'pdftoppm'),

        // Longest edge of the rendered PNG, in pixels (`pdftoppm -scale-to`). A grid tile is small,
        // so this is a thumbnail, not a full-resolution render.
        'scale' => (int) env('DISK_THUMBNAIL_SCALE', 480),

        // Hard timeout (seconds) for one `pdftoppm` run. A pathological PDF is abandoned (render
        // returns null -> 404) instead of tying up the request.
        'timeout' => (int) env('DISK_THUMBNAIL_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-user edit drafts (autosave)
    |--------------------------------------------------------------------------
    |
    | The preview editor autosaves its in-progress state server-side so a refresh
    | or crash never loses work; the MAIN file is overwritten only on explicit
    | Save. Drafts are transient — the `disk:reap-stale-drafts` reaper prunes them
    | (row + storage dir) once past the retention window.
    |
    */

    'drafts' => [

        // How long (seconds) an untouched draft survives before the reaper prunes it. Default 24h.
        'retention' => (int) env('DISK_DRAFT_RETENTION', 86400),

        // Hard cap (bytes) on a single autosave's payload (manifest + uploaded base blobs). An
        // oversize PUT is refused (422) rather than filling the tenant disk. Default 64 MB.
        'max_bytes' => (int) env('DISK_DRAFT_MAX_BYTES', 67108864),
    ],

];
