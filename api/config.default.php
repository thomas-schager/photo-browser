<?php
/**
 * Default configuration for Photo Browser.
 *
 * Copy this file to config.local.php and override only the values that
 * differ from these defaults for your installation. config.local.php is
 * gitignored and will never be committed to the repository.
 */
return [

    // Absolute path to the root folder that contains your photos and videos.
    // All browsing is constrained to this directory and its subdirectories.
    'media_base_path' => '/volume1/photo',

    // Path to the ffmpeg binary used for video thumbnail extraction.
    // 'ffmpeg' resolves via PATH; set an absolute path if needed.
    'ffmpeg_path' => 'ffmpeg',

    // Maximum dimensions (in pixels) of generated thumbnails.
    'thumb_max_width'  => 800,
    'thumb_max_height' => 800,

    // File extensions recognised per media type (lowercase, without leading dot).
    'extensions' => [
        'photo' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'video' => ['mp4', 'mov', 'avi', 'mkv'],
        'audio' => ['mp3', 'wav', 'flac'],
    ],

    // ── Thumbnail cache ───────────────────────────────────────────────────────

    // Filesystem path where generated thumbnails are stored.
    // Must be writable by the web-server process.
    'cache_path' => __DIR__ . '/../cache',

    // Public URL prefix under which the cache directory is served directly
    // by the web server (bypassing PHP).
    'cache_url' => '/photos/cache',

    // PIN required to trigger bulk thumbnail generation from the browser.
    // Leave empty to disable the feature. Set a strong value in config.local.php.
    'cache_pin' => '999',

    // ── Blacklists ────────────────────────────────────────────────────────────

    // Directory names to hide everywhere (e.g. Synology system folders).
    'blacklist_dir_names'  => ['@eaDir', '@Recycle', '#recycle'],

    // Specific directory paths to hide (relative to media_base_path).
    'blacklist_dir_paths'  => [],

    // Specific file paths to hide (relative to media_base_path).
    'blacklist_file_paths' => [],

];
