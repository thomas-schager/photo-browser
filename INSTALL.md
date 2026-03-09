# Photo Browser — Installation Guide (Synology DSM 7.x)

Target: Synology DiskStation DS214+ · DSM 7.1.1
Base URL after installation: `https://192.168.0.20/photos`

All steps are performed either on your **local Mac** or through the **DSM web interface / File Station**. No SSH required.

---

## 1. Prerequisites

### 1.1 Web Station

1. Open **Package Center** in DSM.
2. Search for **Web Station** and click **Install**.
3. Open Web Station → **Web Service Portal** → verify a portal is active (create one if not).

### 1.2 PHP

1. In **Web Station** → **PHP Settings**, click **Create**.
2. Choose **PHP 8.1** (or 8.2 if available). Enable the following extensions in the profile:
   - `gd` — image resizing (enabled by default)
   - `imagick` — higher-quality resizing (optional; enable if listed — the app falls back to GD automatically if not available)
   - `exif` — image metadata
   - `json` — built-in from PHP 8.x, no action needed
3. Save the profile and assign it to your Web Service Portal.

### 1.3 Apache HTTP Server

The SPA routing requires `.htaccess` support, which means the portal must use **Apache** as the HTTP backend (not Nginx).

1. In Web Station → **Web Service Portal** → click **Edit** on your portal.
2. Under **HTTP back-end server**, select **Apache HTTP Server 2.4**.
3. Save.

### 1.4 FFmpeg (via SynoCommunity)

FFmpeg is required for video thumbnails.

**Step 1 — Add SynoCommunity as a package source:**
1. Open **Package Center** → **Settings** → **Package Sources** tab.
2. Click **Add** and enter:
   - Name: `SynoCommunity`
   - Location: `https://packages.synocommunity.com`
3. Click **OK**.

**Step 2 — Allow third-party packages:**
1. Still in **Settings** → **General** tab.
2. Set **Trust Level** to **Synology Inc. and trusted publishers**.

**Step 3 — Install FFmpeg:**
1. Go to the **Community** tab in Package Center.
2. Search for `ffmpeg` and click **Install**.
3. Accept the license and complete installation.

After installation, FFmpeg will be at `/var/packages/ffmpeg/target/bin/ffmpeg` — this is the default path already set in the config.

---

## 2. Build locally

### 2.1 Install Composer (if not already installed)

**macOS via Homebrew:**
```bash
brew install composer
```

**macOS manual:**
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2.2 Install PHP dependencies

In your terminal, from the project root:

```bash
cd /path/to/Photo_Browser/api
composer install --no-dev --optimize-autoloader
```

This creates `api/vendor/` containing Slim Framework 4 and all dependencies.

### 2.3 Configure

Copy the default configuration and override only the values that differ:

```bash
cp api/config.default.php api/config.local.php
```

Then edit `api/config.local.php`. A minimal setup for Synology looks like this:

```php
return [
    // Absolute path to your photo library root on the NAS
    'media_base_path' => '/volume1/photo',

    // Full path to the ffmpeg binary (default after SynoCommunity install)
    'ffmpeg_path' => '/var/packages/ffmpeg/target/bin/ffmpeg',

    // PIN to protect the bulk thumbnail generation endpoint
    'cache_pin' => 'your-pin-here',
];
```

`api/config.local.php` is gitignored and will never be committed to the repository. All available options and their defaults are documented in [api/config.default.php](api/config.default.php).

**`media_base_path`** — Root of the photo library. The app prevents navigating above this path.

**`ffmpeg_path`** — Only change this if FFmpeg was installed somewhere other than the SynoCommunity default (`/var/packages/ffmpeg/target/bin/ffmpeg`).

**`thumb_max_width` / `thumb_max_height`** — Maximum thumbnail dimensions in pixels; width and height can be set independently.

**`extensions`** — Add or remove file extensions as needed.

**`cache_pin`** — PIN required to use the cache management options in the browser (refresh, rebuild, or delete thumbnails). Leave empty to disable cache management.

---

## 3. Upload to the NAS

### 3.1 Expected structure on the NAS

```
/volume1/web/photos/
├── index.php            ← SPA entry point
├── .htaccess
├── favicon.svg
├── css/
│   └── app.css
├── js/
│   └── app.js
└── api/
    ├── index.php
    ├── config.default.php
    ├── config.local.php ← your local config (not in the repository)
    ├── composer.json
    └── vendor/          ← built in step 2, upload this folder
```

### 3.2 Create the destination folder

Open **File Station** in DSM and navigate to `volume1/web/`. Create a new folder called `photos`.

### 3.3 Upload all files

In File Station, open `volume1/web/photos/` and drag-and-drop the following from your local project folder:

- `index.php`
- `.htaccess`
- `favicon.svg`
- `css/` folder
- `js/` folder
- `api/` folder (including `config.local.php` and the `vendor/` subdirectory built in step 2)

### 3.4 .htaccess files

Both `.htaccess` files are already included in the repository and uploaded as part of step 3.3:

- **`/volume1/web/photos/.htaccess`** — routes `/browse/…` requests to `index.php` for SPA navigation.
- **`/volume1/web/photos/api/.htaccess`** — routes all non-file requests through Slim's front controller.

No manual editing is required unless you change the base URL from `/photos`.

---

## 4. Permissions

Web Station on DSM 7 runs as the `http` system user. Files uploaded via File Station to `volume1/web/` are normally readable by the web server without any extra steps.

If you see **permission denied** errors for the photo library:

1. Open **Control Panel** → **Shared Folder**.
2. Select the shared folder that contains your photos (e.g. `Gemeinsame Dateien`).
3. Click **Edit** → **Permissions** tab.
4. Add the `http` system user (or the `administrators` group) with **Read** permission.
5. Click **Save**.

---

## 5. Testing the Installation

Work through this checklist after uploading:

1. **Root folder loads**
   Open `https://192.168.0.20/photos/browse/` in a browser.
   → You should see folder tiles for the top-level directories.

2. **Folder navigation**
   Click a folder tile.
   → URL updates to `/photos/browse/FolderName` and the grid reloads.

3. **Breadcrumb navigation**
   Click a breadcrumb segment.
   → Navigates back to that folder.

4. **Image lightbox**
   Click any photo.
   → Lightbox opens. Arrow keys move between files. Escape closes.

5. **Video playback**
   Click a video file.
   → Lightbox opens, video auto-plays (muted). Space bar toggles play/pause.

6. **Audio playback**
   Click an audio file.
   → Lightbox shows animated waveform and audio controls.

7. **Metadata panel**
   Click a photo or video in the lightbox, then tap the **ⓘ** button (desktop, bottom right of the lightbox) or scroll down past the image (mobile).
   → A panel shows date & time, camera details (JPEG), file info, and GPS if available.

8. **Folder preview pin**
   Open any photo that is not already the folder preview → tap ⓘ → scroll to the bottom of the metadata panel → tap **Set as folder preview**.
   → The folder tile in the grid now shows that photo as its cover.
   → If the file is already the folder preview (pinned or auto-selected as the first alphabetical photo), the button is replaced by a **Folder preview** label.

9. **Page refresh preserves state**
   Navigate to a folder, press F5.
   → The same folder reloads.

10. **Direct lightbox URL**
    Open a file, copy the URL, paste into a new tab.
    → Folder loads and lightbox opens for that file.

---

## 6. Troubleshooting

### Blank page at `/photos/browse/`

- Open browser DevTools → Console and check for JavaScript errors.
- Confirm static files are reachable: open `https://<NAS-IP>/photos/index.php` directly.
- Confirm the Web Service Portal is using **Apache HTTP Server 2.4** (see step 1.3) — Nginx does not process `.htaccess` files.
- Confirm `.htaccess` was uploaded to `volume1/web/photos/`.

### API returns 404 for `/photos/api/browse`

- In File Station, confirm `volume1/web/photos/api/vendor/autoload.php` exists. If not, re-run `composer install` locally and re-upload `api/vendor/`.
- Open `https://192.168.0.20/photos/api/browse?path=` directly in a browser — you should get a JSON response.
- Check **DSM → Log Center → Application → Web Station** for PHP errors.

### Thumbnails not loading (broken image icons)

- Open browser DevTools → Network tab → click a thumbnail request → check the **`X-Thumbnail-Error`** response header for the reason.
- Confirm `gd` is enabled in Web Station → PHP Settings → your PHP profile.
- In File Station, verify the photos folder is readable (see step 4 — Permissions).

### Video thumbnails always fail / show "FFmpeg not found"

- In Package Center, confirm the `ffmpeg` package status is **Running**.
- If the `X-Thumbnail-Error` header says "FFmpeg not found", the binary path may differ from the default. The expected path is `/var/packages/ffmpeg/target/bin/ffmpeg` — update `ffmpeg_path` in `api/config.local.php` if your installation differs.
- Check **DSM → Log Center → Application → Web Station** for `shell_exec` errors, which may indicate `shell_exec` is disabled in the PHP profile.

### Metadata panel shows no camera / video details

- **Photos**: EXIF metadata is only extracted from JPEG files. PNG, GIF, and WebP files show file name, size, and dimensions only.
- **Videos**: Metadata is extracted using `ffprobe`, which ships alongside FFmpeg. If the FFmpeg package is not installed or the `ffmpeg_path` config key points to the wrong directory, video metadata will not be available (the panel will still show file name and size).
- Metadata is stored in the thumbnail cache the first time a thumbnail is generated. If you generated thumbnails before this version was installed, use **Rebuild** in the cache modal to regenerate them with metadata.

### Permission denied errors

- Follow step 4 to grant the `http` user read access to the shared folder via Control Panel.
- In File Station, right-click `volume1/web/photos` → **Properties** → **Permission** tab and confirm the `http` user has read/write access.

### "Class not found" / "Failed to open stream" errors

- The `vendor/` directory was not uploaded or is incomplete. Run `composer install --no-dev --optimize-autoloader` locally in `api/`, then re-upload the `api/vendor/` folder via File Station.
- Confirm the correct PHP version is selected in Web Station (requires PHP ≥ 7.4).

### Log file locations

| Log                | Where to find it |
|--------------------|-----------------|
| PHP / web errors   | DSM → Log Center → **Application** → Web Station |
| General system log | DSM → Log Center → **System** |

---

*Photo Browser — built for Synology DiskStation DS214+ / DSM 7.x*
