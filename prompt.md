# Photo Browser Prompt

Create a photo browser application with a PHP backend and Vue.js frontend.

## Overview

- **Base URL**: `https://192.168.0.20/photos`
- **Platform**: Synology DiskStation DS214+ running DSM 7.1.1
- **Media source**: `/volume1/Gemeinsame Dateien/Gemeinsame Dateien/Fotos` (top-level directory with n-depth subfolders)

## Deliverables

Generate the following files:

1. **Application code** (PHP backend + Vue.js frontend)
2. **`INSTALL.md`** — Comprehensive installation instructions for Synology DiskStation

---

## Backend (PHP)

**Framework**: Slim Framework (keep it lean)

**Configuration** (in a config file):

- Base media path: `/volume1/Gemeinsame Dateien/Gemeinsame Dateien/Fotos`
- FFmpeg path: `/var/packages/ffmpeg/target/bin/ffmpeg`
- Thumbnail max dimension: 800px (max. width or height can be separately adjusted)
- Supported file extensions (configurable arrays):
  - Photos: `jpg`, `jpeg`, `png`, `gif`, `webp`
  - Videos: `mp4`, `mov`, `avi`, `mkv`
  - Audio: `mp3`, `wav`, `flac`

### API Endpoints

#### 1. GET `/api/browse?path=` — List folder contents

- Parameter: `path` (relative path from media root, empty = root folder)
- Returns JSON with:
  - `folders`: array of `{ name, path }`
  - `files`: array of `{ name, path, type (photo|video|audio) }`
- Sorted alphabetically by filename
- Only return supported file types

#### 2. GET `/api/thumbnail?path=` — Get thumbnail

- For images: Resize on-the-fly using ImageMagick (imagick) or GD to max 800px width/height, maintain aspect ratio
- For videos: Extract frame using FFmpeg, resize to max 800px
- For audio: Return a generic audio placeholder icon
- For errors: Return a placeholder image with error reason in response header (e.g., `X-Thumbnail-Error: Unsupported format`)
- No caching for now

#### 3. GET `/api/media?path=` — Get original media file

- Stream the original file with appropriate `Content-Type` header
- Support range requests for video/audio seeking

---

## Frontend (Vue.js)

**Setup**:

- Vue 3 via CDN (no build step)
- Single Page Application
- History-based routing with base path `/photos`

### Layout

- **Breadcrumb navigation** at the top (e.g., `Fotos > 2024 > Vacation > Beach`)
  - Each breadcrumb segment is clickable to navigate to that folder
- **Responsive grid** below showing folders and files
  - Folders appear first, then files
  - Folders display as simple blocks/icons (no preview image)
  - Photos/videos display their thumbnails
  - Audio files display a generic audio icon
  - On thumbnail error: show placeholder icon with error reason in `title` attribute (tooltip)

### URL Structure

- Folder view: `/photos/browse/path/to/folder`
- File open in lightbox: `/photos/browse/path/to/folder/view/filename.jpg`
- Root folder: `/photos/browse/` or `/photos/browse`

### Grid Behavior

- Clicking a **folder** → navigates into that folder, updates URL
- Clicking a **file** → opens lightbox, adds `/view/filename` to URL

### Lightbox

- Opens when clicking any media file (photo/video/audio)
- Displays the original media via `/api/media?path=`
- **Navigation**:
  - Previous/Next buttons
  - Arrow Left/Right keys → previous/next media
  - Escape key → close lightbox
- **For videos**:
  - Auto-play on open (muted)
  - Space key → play/pause
  - Progress bar at bottom showing playback position
- **For audio**:
  - Display animated waveform visualization while playing
  - Space key → play/pause
  - Progress bar at bottom showing playback position
- **URL updates** when navigating between files in lightbox (e.g., `/photos/browse/2024/Vacation/view/next-image.jpg`)
- Closing lightbox removes `/view/filename` from URL, returning to `/photos/browse/2024/Vacation`

---

## INSTALL.md Requirements

Generate a comprehensive `INSTALL.md` file with step-by-step installation instructions for Synology DiskStation (DSM 7.x). Structure it as follows:

### 1. Prerequisites

- **Web Station**: How to install from Package Center
- **PHP**: Which PHP version to install (via Web Station), required extensions:
  - `imagick` or `gd` (for image manipulation)
  - `exif` (for image metadata)
  - `json` (for API responses)
- **FFmpeg**: Step-by-step instructions for installing via SynoCommunity:
  1. Adding SynoCommunity as a package source (`https://packages.synocommunity.com`)
  2. Allowing packages from any publisher
  3. Installing the `ffmpeg` package
  4. Verifying the installation path (`/var/packages/ffmpeg/target/bin/ffmpeg`)

### 2. Directory Structure

Show the expected file/folder layout:

```
/volume1/web/photos/
├── index.html
├── css/
│   └── app.css
├── js/
│   └── app.js
├── api/
│   ├── index.php
│   ├── composer.json
│   └── vendor/
└── assets/
    └── icons/
```

Include:

- How to create `/volume1/web/photos`
- Setting correct permissions for web server user

### 3. PHP Dependencies (Composer)

- Installing Composer on Synology
- Running `composer install` in the `/api` directory

### 4. Configuration

Explain config file settings:

- `MEDIA_BASE_PATH`
- `FFMPEG_PATH`
- `THUMBNAIL_MAX_SIZE`
- `ALLOWED_EXTENSIONS`

### 5. Web Server Configuration

**Nginx configuration:**

- Route `/photos/browse/*` to `index.html`
- Route `/photos/api/*` to `api/index.php`
- Proper `try_files` directive for SPA routing
- Where to find/edit Nginx config on Synology DSM

**Apache configuration (.htaccess):**

- `RewriteEngine On`
- Rewrite rules for SPA routing
- Exception for `/api/*` requests
- How to enable `.htaccess` on Synology

### 6. Testing the Installation

Step-by-step verification checklist:

1. Open `https://192.168.0.20/photos/browse/`
2. Verify root folder loads
3. Test folder navigation
4. Test lightbox with image
5. Test video playback
6. Test page refresh with URL state preservation

### 7. Troubleshooting

Common issues and solutions:

- Blank page
- API 404 errors
- Thumbnails not loading
- Video thumbnail failures
- Permission denied errors
- "Class not found" errors

Include Synology log file paths for debugging.

---

## Additional Requirements

- URL reflects current state so page reload preserves the view
- No caching for now — all thumbnails and media served fresh
- PHP passes through all media since frontend cannot access the filesystem directly
