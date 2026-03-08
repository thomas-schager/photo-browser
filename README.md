# Photo Browser

A self-hosted photo and video browser for **Synology DiskStation NAS** (DSM 7.x). Browse your photo library through a clean web UI — no cloud, no indexing daemon, no database.

## Features

- Grid view of folders and media files with auto-generated thumbnails
- Lightbox for photos (keyboard navigation, arrow keys / Escape)
- Video playback with auto-play and Space bar toggle
- Audio playback with waveform display
- **Metadata panel** — tap ⓘ or swipe up (mobile) to view EXIF / video details grouped like Google Photos: date & time, camera, file info, GPS location
- **Folder preview pin** — set any photo or video as the permanent preview thumbnail for its folder
- Thumbnail cache with three PIN-protected management options: refresh, rebuild, and delete
- Per-folder sort order (by date or name, ascending/descending) — persisted per folder
- EXIF-aware date sorting (reads `DateTimeOriginal` from JPEG files)
- Handles EXIF orientation for correctly rotated thumbnails
- Blacklist support to hide specific folders or files
- SPA routing — page refresh and direct links always work
- Cache-busted assets — browser always loads the latest CSS/JS after an upload

## Tech Stack

| Layer    | Technology |
|----------|------------|
| Frontend | Vue 3 (CDN), vanilla CSS |
| Backend  | PHP 8.1+, Slim Framework 4 |
| Server   | Synology Web Station, Apache 2.4 |

## Project Structure

```
Photo_Browser/
├── index.php            ← SPA entry point (cache-busting wrapper)
├── .htaccess            ← SPA routing rules
├── favicon.svg
├── css/
│   └── app.css
├── js/
│   └── app.js
└── api/
    ├── index.php        ← Slim API (browse, thumbnail, media, metadata, cache, sort, folder/preview)
    ├── config.default.php
    ├── config.local.php ← your local overrides (gitignored)
    ├── composer.json
    └── vendor/          ← built locally with Composer (gitignored)
```

## Quick Start

### 1. Install PHP dependencies

```bash
cd api
composer install --no-dev --optimize-autoloader
```

### 2. Configure

Copy the default config and override only what you need:

```bash
cp api/config.default.php api/config.local.php
```

Then edit `api/config.local.php`:

```php
return [
    // Absolute path to your photo library on the NAS
    'media_base_path' => '/volume1/photo',

    // Full path to the ffmpeg binary (SynoCommunity default)
    'ffmpeg_path' => '/var/packages/ffmpeg/target/bin/ffmpeg',

    // PIN to protect the bulk thumbnail generation endpoint
    'cache_pin' => 'your-pin-here',
];
```

`config.local.php` is gitignored and will never be committed.

### 3. Upload to the NAS

Copy the following to `volume1/web/photos/` on your NAS (via File Station or scp):

```
index.php
.htaccess
favicon.svg
css/
js/
api/          ← including vendor/ built in step 1
```

### 4. Open in browser

```
https://<NAS-IP>/photos/browse/
```

## Configuration Reference

All options and their defaults are documented in [api/config.default.php](api/config.default.php). Override any value in `api/config.local.php`.

| Key | Default | Description |
|-----|---------|-------------|
| `media_base_path` | `/volume1/photo` | Root of the photo library. Browsing is restricted to this path and below. |
| `ffmpeg_path` | `ffmpeg` | Path to the FFmpeg binary for video thumbnails. |
| `thumb_max_width` | `800` | Maximum thumbnail width in pixels. |
| `thumb_max_height` | `800` | Maximum thumbnail height in pixels. |
| `cache_path` | `../cache` | Filesystem path for the thumbnail cache. Must be writable by the web server. |
| `cache_url` | `/photos/cache` | Public URL prefix for the cache directory. |
| `cache_pin` | `999` | PIN to authorise cache management (refresh, rebuild, delete). Set a strong value in `config.local.php`. |
| `blacklist_dir_names` | `['@eaDir', '@Recycle', '#recycle']` | Directory names to hide everywhere. |
| `blacklist_dir_paths` | `[]` | Specific directory paths to hide (relative to `media_base_path`). |
| `blacklist_file_paths` | `[]` | Specific file paths to hide (relative to `media_base_path`). |
| `extensions.photo` | `jpg jpeg png gif webp` | Recognised photo extensions. |
| `extensions.video` | `mp4 mov avi mkv` | Recognised video extensions. |
| `extensions.audio` | `mp3 wav flac` | Recognised audio extensions. |

## Full Installation Guide

For a detailed step-by-step guide covering Web Station setup, PHP configuration, FFmpeg installation, permissions, and troubleshooting, see [INSTALL.md](INSTALL.md).

## Requirements

- Synology DSM 7.x with Web Station
- PHP ≥ 8.1 with extensions: `gd`, `exif` (and optionally `imagick`)
- Apache HTTP Server 2.4 (required for `.htaccess` / SPA routing)
- FFmpeg (via SynoCommunity) for video thumbnails
- Composer (on your local machine, to build `vendor/`)
