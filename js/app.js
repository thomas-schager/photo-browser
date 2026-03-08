/* Photo Browser — Vue 3 CDN SPA
   Base:   /photos
   Routes:
     /photos/browse[/path...]             → folder view
     /photos/browse[/path...]/view/name   → lightbox
   Query params:
     ?page=N  → page number (default 1)
*/

const BASE = '/photos';
const API  = BASE + '/api';

const PAGE_SIZE = 30;

// ── Helpers ──────────────────────────────────────────────────────────────────

function encodePath(p) {
  return p.split('/').map(encodeURIComponent).join('/');
}

function parseRoute(pathname) {
  var p = pathname;
  if (p.startsWith(BASE)) p = p.slice(BASE.length);
  if (!p.startsWith('/browse')) return { folderPath: '', viewFile: null };
  p = p.slice('/browse'.length);
  if (p.startsWith('/')) p = p.slice(1);
  var viewIdx = p.indexOf('/view/');
  if (viewIdx !== -1) {
    return {
      folderPath: decodeURIComponent(p.slice(0, viewIdx)),
      viewFile:   decodeURIComponent(p.slice(viewIdx + 6)),
    };
  }
  return { folderPath: decodeURIComponent(p), viewFile: null };
}

function parsePageFromSearch(search) {
  var n = parseInt(new URLSearchParams(search).get('page'), 10);
  return (isFinite(n) && n >= 1) ? n : 1;
}

function buildBrowseUrl(folderPath, viewFile, page) {
  var url = BASE + '/browse';
  if (folderPath) url += '/' + encodePath(folderPath);
  if (viewFile)   url += '/view/' + encodePath(viewFile);
  if (page && page > 1) url += '?page=' + page;
  return url;
}

function fmtTime(secs) {
  if (!isFinite(secs) || isNaN(secs)) return '0:00';
  var m = Math.floor(secs / 60);
  var s = Math.floor(secs % 60).toString().padStart(2, '0');
  return m + ':' + s;
}

function pinchDist(touches) {
  var dx = touches[0].clientX - touches[1].clientX;
  var dy = touches[0].clientY - touches[1].clientY;
  return Math.sqrt(dx * dx + dy * dy) || 1;
}

// ── Inline SVG icons ─────────────────────────────────────────────────────────

var ICON_FOLDER = '<svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#4a9eff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>';
var ICON_AUDIO  = '<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#a0a0ff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
var ICON_VIDEO  = '<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#ff8c4a" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10,8 16,12 10,16" fill="#ff8c4a" stroke="none"/></svg>';
var ICON_PHOTO  = '<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#4aff9e" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
var ICON_CACHE  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>';
var ICON_SORT   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 15V3"/><path d="M3 7l4-4 4 4"/><path d="M17 9v12"/><path d="M13 17l4 4 4-4"/></svg>';
var ICON_SIZE   = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>';

// ── App component ─────────────────────────────────────────────────────────────

var App = {
  name: 'App',

  data: function() {
    return {
      folderPath: '',
      folders: [],
      files: [],
      sort: 'date_asc',
      loading: false,
      fetchError: null,
      fetchNetworkError: false,

      // Pagination
      page: 1,

      // Lightbox state
      lbFile: null,
      lbIndex: -1,

      // A/V state
      currentTime: 0,
      duration: 0,
      playing: false,

      // Icon strings (accessible in template via data)
      iconFolder: ICON_FOLDER,
      iconAudio:  ICON_AUDIO,
      iconVideo:  ICON_VIDEO,
      iconPhoto:  ICON_PHOTO,
      iconCache:  ICON_CACHE,
      iconSort:   ICON_SORT,
      iconSize:   ICON_SIZE,

      // Thumbnail size: S / M / L  (persisted in localStorage)
      thumbSize: 'M',

      // Lightbox swipe / zoom
      swipeDx:        0,
      swipeTrans:     false,
      swipeAnimating: false,
      lbFullLoaded:   false,
      lbScale:        1,
      lbPanX:         0,
      lbPanY:         0,

      // ── Snackbar ──────────────────────────────────────────────────────────
      snacks:    [],
      snackNext: 0,

      // ── Cache generation modal ────────────────────────────────────────────
      cacheModal: {
        show:       false,
        folderPath: '',
        folderName: '',
        force:      false,
        pin:        '',
        busy:       false,
        done:       false,
        progress:   0,
        total:      0,
        errorCount: 0,
      },
    };
  },

  computed: {
    breadcrumbs: function() {
      var parts = this.folderPath ? this.folderPath.split('/') : [];
      var crumbs = [{ label: 'Fotos', path: '' }];
      for (var i = 0; i < parts.length; i++) {
        crumbs.push({ label: parts[i], path: parts.slice(0, i + 1).join('/') });
      }
      return crumbs;
    },

    allItems: function() {
      var items = this.folders.map(function(f) { return { kind: 'folder', item: f }; });
      return items.concat(this.files.map(function(f) { return { kind: 'file', item: f }; }));
    },

    totalPages: function() {
      return Math.max(1, Math.ceil(this.allItems.length / PAGE_SIZE));
    },

    pagedItems: function() {
      var start = (this.page - 1) * PAGE_SIZE;
      return this.allItems.slice(start, start + PAGE_SIZE);
    },

    pagedFolders: function() {
      return this.pagedItems
        .filter(function(x) { return x.kind === 'folder'; })
        .map(function(x) { return x.item; });
    },

    pagedFiles: function() {
      return this.pagedItems
        .filter(function(x) { return x.kind === 'file'; })
        .map(function(x) { return x.item; });
    },

    pageNumbers: function() {
      var total = this.totalPages;
      var cur   = this.page;
      if (total <= 7) {
        var arr = [];
        for (var i = 1; i <= total; i++) arr.push(i);
        return arr;
      }
      var pages = [1];
      if (cur - 2 > 2)         pages.push(null);
      for (var i = Math.max(2, cur - 2); i <= Math.min(total - 1, cur + 2); i++) pages.push(i);
      if (cur + 2 < total - 1) pages.push(null);
      pages.push(total);
      return pages;
    },

    paginationLabel: function() {
      if (!this.allItems.length) return '';
      var start = (this.page - 1) * PAGE_SIZE + 1;
      var end   = Math.min(this.page * PAGE_SIZE, this.allItems.length);
      return start + '–' + end + ' of ' + this.allItems.length;
    },

    sortLabel: function() {
      return { date_asc: 'Sort: Oldest first', date_desc: 'Sort: Newest first',
               name_asc: 'Sort: Name A–Z',    name_desc: 'Sort: Name Z–A' }[this.sort] || 'Sort';
    },

    certAcceptUrl: function() {
      return API + '/browse?path=' + encodeURIComponent(this.folderPath);
    },

    lbSrc: function() {
      if (!this.lbFile) return '';
      return API + '/media?path=' + encodeURIComponent(this.lbFile.path);
    },

    lbThumbSrc: function() {
      if (!this.lbFile) return '';
      return API + '/thumbnail?path=' + encodeURIComponent(this.lbFile.path);
    },

    progressPct: function() {
      if (!this.duration) return 0;
      return (this.currentTime / this.duration) * 100;
    },
  },

  methods: {
    // ── Folder navigation ──────────────────────────────────────────────────

    navigateTo: function(folderPath) {
      this.folderPath = folderPath;
      this.page = 1;
      history.pushState(null, '', buildBrowseUrl(folderPath));
      this.closeLightbox(false);
      this.loadFolder();
    },

    loadFolder: function() {
      var self = this;
      self.loading          = true;
      self.fetchError       = null;
      self.fetchNetworkError = false;
      self.folders    = [];
      self.files      = [];
      return fetch(API + '/browse?path=' + encodeURIComponent(self.folderPath))
        .then(function(res) {
          if (!res.ok) throw new Error('HTTP ' + res.status);
          return res.json();
        })
        .then(function(data) {
          self.folders = data.folders  || [];
          self.files   = data.files    || [];
          self.sort    = data.sort     || 'date_asc';
          if (self.page > self.totalPages) self.page = 1;
          // Surface any server-side warnings as snacks.
          (data.warnings || []).forEach(function(w) { self.addSnack(w, 'error'); });
        })
        .catch(function(e) {
          self.fetchError        = e.message;
          self.fetchNetworkError = (e instanceof TypeError);
        })
        .finally(function() {
          self.loading = false;
          self.$nextTick(function() {
            var trail = self.$refs.crumbTrail;
            if (trail) trail.scrollLeft = trail.scrollWidth;
          });
        });
    },

    // ── Pagination ─────────────────────────────────────────────────────────

    goToPage: function(n) {
      if (n < 1 || n > this.totalPages || n === this.page) return;
      this.closeLightbox(false);
      this.page = n;
      history.pushState(null, '', buildBrowseUrl(this.folderPath, null, n > 1 ? n : null));
      window.scrollTo(0, 0);
    },

    // ── Lightbox ──────────────────────────────────────────────────────────

    openFile: function(file) {
      this.stopMedia();
      this.lbIndex      = this.pagedFiles.findIndex(function(f) { return f.path === file.path; });
      this.lbFile       = file;
      this.lbFullLoaded = false;
      this.lbScale      = 1;
      this.lbPanX       = 0;
      this.lbPanY       = 0;
      this.currentTime  = 0;
      this.duration     = 0;
      this.playing      = false;
      history.pushState(null, '', buildBrowseUrl(this.folderPath, file.name, this.page > 1 ? this.page : null));
    },

    closeLightbox: function(updateUrl) {
      if (updateUrl === undefined) updateUrl = true;
      this.stopMedia();
      this.lbFile  = null;
      this.lbIndex = -1;
      if (updateUrl) history.pushState(null, '', buildBrowseUrl(this.folderPath, null, this.page > 1 ? this.page : null));
    },

    prevFile: function() {
      if (this.lbIndex > 0) this.openFile(this.pagedFiles[this.lbIndex - 1]);
    },

    nextFile: function() {
      if (this.lbIndex < this.pagedFiles.length - 1) this.openFile(this.pagedFiles[this.lbIndex + 1]);
    },

    stopMedia: function() {
      var el = this.$refs.mediaEl;
      if (el && typeof el.pause === 'function') el.pause();
      this.playing = false;
    },

    togglePlay: function() {
      var self = this;
      var el   = this.$refs.mediaEl;
      if (!el) return;
      if (el.paused) { el.play().then(function() { self.playing = true; }).catch(function() {}); }
      else           { el.pause(); self.playing = false; }
    },

    seekTo: function(e) {
      var el = this.$refs.mediaEl;
      if (!el || !this.duration) return;
      var rect = e.currentTarget.getBoundingClientRect();
      el.currentTime = ((e.clientX - rect.left) / rect.width) * this.duration;
    },

    onMediaLoaded: function() {
      var self = this;
      var el   = this.$refs.mediaEl;
      if (!el) return;
      self.duration = el.duration || 0;
      if (self.lbFile && self.lbFile.type === 'video') {
        el.play().then(function() { self.playing = true; }).catch(function() {});
      }
    },

    onLbImgLoad: function() {
      // Called when the thumbnail <img> finishes loading.
      // Preload the full-size image in the background; swap src when ready.
      if (this.lbFullLoaded) return;  // already swapped (shouldn't happen, but guard anyway)
      var self     = this;
      var filePath = this.lbFile && this.lbFile.path;
      var full     = new Image();
      full.onload = function() {
        // Guard: user may have swiped to a different file while we were loading.
        if (self.lbFile && self.lbFile.path === filePath) {
          self.lbFullLoaded = true;
        }
      };
      full.src = this.lbSrc;
    },

    onTimeUpdate: function() {
      var el = this.$refs.mediaEl;
      if (!el) return;
      this.currentTime = el.currentTime;
      this.duration    = el.duration || this.duration;
      this.playing     = !el.paused;
    },

    // ── Thumbnail helpers ──────────────────────────────────────────────────

    thumbSrc: function(file) {
      return API + '/thumbnail?path=' + encodeURIComponent(file.path);
    },

    thumbIcon: function(file) {
      if (file.type === 'video') return this.iconVideo;
      if (file.type === 'audio') return this.iconAudio;
      return this.iconPhoto;
    },

    onThumbLoad: function(e) {
      var img = e.target;
      img.style.display = 'block';
      var shimmer = img.previousElementSibling;
      if (shimmer) shimmer.style.display = 'none';
    },

    onThumbError: function(e, file) {
      file._thumbFailed = true;
      var img = e.target;
      img.style.display = 'none';
      var shimmer = img.previousElementSibling;
      if (shimmer) shimmer.style.display = 'none';
      var placeholder = img.nextElementSibling;
      if (placeholder) placeholder.style.display = 'flex';
    },

    // ── Snackbar ───────────────────────────────────────────────────────────

    addSnack: function(msg, type, duration) {
      var self = this;
      duration = duration || 5000;
      var id   = self.snackNext++;
      self.snacks.push({ id: id, msg: msg, type: type || 'info' });
      setTimeout(function() { self.removeSnack(id); }, duration);
    },

    removeSnack: function(id) {
      var idx = this.snacks.findIndex(function(s) { return s.id === id; });
      if (idx !== -1) this.snacks.splice(idx, 1);
    },

    // ── Cache generation ───────────────────────────────────────────────────

    openCacheModal: function(folder) {
      var m       = this.cacheModal;
      m.folderPath = folder.path;
      m.folderName = folder.name;
      m.force      = false;
      m.pin        = '';
      m.busy       = false;
      m.done       = false;
      m.progress   = 0;
      m.total      = 0;
      m.errorCount = 0;
      m.show       = true;
    },

    closeCacheModal: function() {
      if (!this.cacheModal.busy) this.cacheModal.show = false;
    },

    startCacheGeneration: async function() {
      var self  = this;
      var modal = self.cacheModal;
      if (!modal.pin) return;

      modal.busy       = true;
      modal.done       = false;
      modal.progress   = 0;
      modal.total      = 0;
      modal.errorCount = 0;

      // 1. Fetch the full recursive file list.
      var listRes;
      try {
        listRes = await fetch(
          API + '/cache/list?path=' + encodeURIComponent(modal.folderPath) +
          '&pin=' + encodeURIComponent(modal.pin)
        );
      } catch (e) {
        self.addSnack('Network error: ' + e.message, 'error');
        modal.busy = false;
        return;
      }

      var listData = await listRes.json();

      if (listRes.status === 401) {
        self.addSnack('Wrong PIN', 'error');
        modal.busy = false;
        return;
      }
      if (!listRes.ok) {
        self.addSnack(listData.error || 'Failed to retrieve file list', 'error');
        modal.busy = false;
        return;
      }

      var files   = listData.files || [];
      modal.total = files.length;

      // 2. Process files one at a time.
      for (var i = 0; i < files.length; i++) {
        modal.progress = i;
        try {
          var buildRes = await fetch(API + '/cache/build', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ path: files[i].path, force: modal.force, pin: modal.pin }),
          });
          var buildData = await buildRes.json();
          if (buildRes.status === 401) {
            self.addSnack('Wrong PIN', 'error');
            modal.busy = false;
            return;
          }
          if (!buildRes.ok || !buildData.ok) {
            modal.errorCount++;
          }
        } catch (e) {
          modal.errorCount++;
        }
      }

      modal.progress = files.length;
      modal.done     = true;
      modal.busy     = false;

      var generated = files.length - modal.errorCount;
      var msg       = 'Thumbnails: ' + generated + ' generated';
      if (modal.errorCount > 0) msg += ', ' + modal.errorCount + ' failed';
      self.addSnack(msg, modal.errorCount > 0 ? 'error' : 'success', 7000);

      setTimeout(function() { self.closeCacheModal(); }, 1800);
    },

    // ── Sort ──────────────────────────────────────────────────────────────

    setSortOrder: function(value) {
      var self = this;
      self.sort = value;
      self.page = 1;
      self.closeLightbox(false);
      history.pushState(null, '', buildBrowseUrl(self.folderPath));
      fetch(API + '/sort', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ path: self.folderPath, sort: value }),
      }).then(function() {
        self.loadFolder();
      }).catch(function(e) {
        self.addSnack('Failed to save sort setting: ' + e.message, 'error');
      });
    },

    // ── Lightbox swipe + pinch-zoom ───────────────────────────────────────

    onLbTouchStart: function(e) {
      if (this.swipeAnimating) return;

      if (e.touches.length === 2) {
        // Pinch start — cancel any in-progress swipe
        this._pinchActive = true;
        this._pinchDist0  = pinchDist(e.touches);
        this._pinchScale0 = this.lbScale;
        this._swipeDir    = null;
        this.swipeDx      = 0;
        return;
      }

      this._pinchActive = false;
      var t = e.touches[0];
      this._swipeX0   = t.clientX;
      this._swipeY0   = t.clientY;
      this._panX0     = this.lbPanX;
      this._panY0     = this.lbPanY;
      this._swipeDir  = null;
      this.swipeTrans = false;
      if (this.lbScale <= 1) this.swipeDx = 0;
    },

    onLbTouchMove: function(e) {
      if (this.swipeAnimating) return;

      // ── Pinch zoom ──
      if (this._pinchActive && e.touches.length === 2) {
        e.preventDefault();
        var newScale = this._pinchScale0 * pinchDist(e.touches) / this._pinchDist0;
        newScale = Math.min(Math.max(newScale, 1), 8);
        this.lbScale = newScale;
        if (newScale <= 1) { this.lbPanX = 0; this.lbPanY = 0; }
        return;
      }

      // ── Pan when zoomed ──
      if (this.lbScale > 1) {
        e.preventDefault();
        var t   = e.touches[0];
        var ddx = t.clientX - this._swipeX0;
        var ddy = t.clientY - this._swipeY0;
        var maxX = (this.lbScale - 1) * window.innerWidth  / 2;
        var maxY = (this.lbScale - 1) * window.innerHeight / 2;
        this.lbPanX = Math.min(maxX, Math.max(-maxX, this._panX0 + ddx));
        this.lbPanY = Math.min(maxY, Math.max(-maxY, this._panY0 + ddy));
        return;
      }

      // ── Horizontal swipe ──
      var t2  = e.touches[0];
      var dx  = t2.clientX - this._swipeX0;
      var dy  = t2.clientY - this._swipeY0;
      if (!this._swipeDir && (Math.abs(dx) > 6 || Math.abs(dy) > 6)) {
        this._swipeDir = Math.abs(dx) >= Math.abs(dy) ? 'h' : 'v';
      }
      if (this._swipeDir !== 'h') return;
      e.preventDefault();
      if (dx > 0 && this.lbIndex <= 0)                           dx = Math.min(dx * 0.25, 50);
      if (dx < 0 && this.lbIndex >= this.pagedFiles.length - 1) dx = Math.max(dx * 0.25, -50);
      this.swipeDx = dx;
    },

    onLbTouchEnd: function() {
      if (this._pinchActive) {
        this._pinchActive = false;
        // Snap back to 1 if barely zoomed
        if (this.lbScale < 1.05) { this.lbScale = 1; this.lbPanX = 0; this.lbPanY = 0; }
        return;
      }

      // Panning — don't trigger swipe
      if (this.lbScale > 1) return;

      if (this._swipeDir !== 'h') { this.swipeDx = 0; return; }
      var self      = this;
      var dx        = this.swipeDx;
      var threshold = 60;
      var w         = window.innerWidth;

      function animateIn(fromX) {
        self.swipeTrans = false;
        self.swipeDx    = fromX;
        requestAnimationFrame(function() {
          requestAnimationFrame(function() {
            self.swipeTrans = true;
            self.swipeDx    = 0;
            setTimeout(function() { self.swipeTrans = false; self.swipeAnimating = false; }, 280);
          });
        });
      }

      if (dx < -threshold && this.lbIndex < this.pagedFiles.length - 1) {
        self.swipeAnimating = true;
        self.swipeTrans     = true;
        self.swipeDx        = -w;
        setTimeout(function() { self.nextFile(); animateIn(w); }, 230);
      } else if (dx > threshold && this.lbIndex > 0) {
        self.swipeAnimating = true;
        self.swipeTrans     = true;
        self.swipeDx        = w;
        setTimeout(function() { self.prevFile(); animateIn(-w); }, 230);
      } else {
        self.swipeTrans = true;
        self.swipeDx    = 0;
        setTimeout(function() { self.swipeTrans = false; }, 300);
      }
    },

    onLbTouchCancel: function() {
      this._pinchActive = false;
      var self = this;
      self.swipeTrans = true;
      self.swipeDx    = 0;
      setTimeout(function() { self.swipeTrans = false; }, 300);
    },

    onLbOverlayClick: function() {
      // Don't close lightbox when tapping while zoomed in
      if (this.lbScale <= 1) this.closeLightbox();
    },

    // ── Thumbnail size ────────────────────────────────────────────────────

    setThumbSize: function(value) {
      if (value === 'S' || value === 'M' || value === 'L' || value === 'XL') {
        this.thumbSize = value;
        localStorage.setItem('photoThumbSize', value);
      }
    },

    // ── Format time ───────────────────────────────────────────────────────

    fmtTime: fmtTime,

    // ── Keyboard ──────────────────────────────────────────────────────────

    onKeydown: function(e) {
      if (e.key === 'Escape') {
        if (this.cacheModal.show && !this.cacheModal.busy) { this.closeCacheModal(); return; }
        if (this.lbFile) { this.closeLightbox(); return; }
      }
      if (!this.lbFile) return;
      if (e.key === 'ArrowLeft')  { this.prevFile(); return; }
      if (e.key === 'ArrowRight') { this.nextFile(); return; }
      if (e.key === ' ' && (this.lbFile.type === 'video' || this.lbFile.type === 'audio')) {
        e.preventDefault();
        this.togglePlay();
      }
    },

    onPopstate: function() {
      var self    = this;
      var parsed  = parseRoute(location.pathname);
      var newPage = parsePageFromSearch(location.search);

      if (parsed.folderPath !== self.folderPath) {
        self.folderPath = parsed.folderPath;
        self.page       = newPage;
        self.loadFolder().then(function() {
          if (parsed.viewFile) {
            var f = self.pagedFiles.find(function(x) { return x.name === parsed.viewFile; });
            if (f) self.openFile(f);
          }
        });
      } else {
        self.page = newPage;
        if (parsed.viewFile) {
          var f = self.pagedFiles.find(function(x) { return x.name === parsed.viewFile; });
          if (f) self.openFile(f);
        } else {
          self.closeLightbox(false);
        }
      }
    },
  },

  mounted: function() {
    var self   = this;
    var parsed = parseRoute(location.pathname);
    self.folderPath = parsed.folderPath;
    self.page       = parsePageFromSearch(location.search);

    var savedSize = localStorage.getItem('photoThumbSize');
    if (savedSize === 'S' || savedSize === 'M' || savedSize === 'L' || savedSize === 'XL') self.thumbSize = savedSize;

    // Non-reactive swipe tracking
    self._swipeX0  = 0;
    self._swipeY0  = 0;
    self._swipeDir = null;

    window.addEventListener('keydown',  this.onKeydown);
    window.addEventListener('popstate', this.onPopstate);

    self.loadFolder().then(function() {
      if (parsed.viewFile) {
        var f = self.pagedFiles.find(function(x) { return x.name === parsed.viewFile; });
        if (f) self.openFile(f);
      }
    });
  },

  beforeUnmount: function() {
    window.removeEventListener('keydown',  this.onKeydown);
    window.removeEventListener('popstate', this.onPopstate);
  },

  template: `
    <div>

      <!-- ── Breadcrumb ── -->
      <nav class="breadcrumb">
        <div class="breadcrumb-path" ref="crumbTrail">
          <template v-for="(crumb, i) in breadcrumbs" :key="crumb.path">
            <span v-if="i > 0" class="breadcrumb-sep">›</span>
            <span
              class="breadcrumb-item"
              :class="{ active: i === breadcrumbs.length - 1 }"
              @click="navigateTo(crumb.path)"
            >{{ crumb.label }}</span>
          </template>
        </div>
        <div v-if="allItems.length" class="sort-btn-wrap" :title="sortLabel">
          <button class="sort-btn" aria-hidden="true" tabindex="-1" v-html="iconSort"></button>
          <select
            class="sort-select-hidden"
            :value="sort"
            @change="setSortOrder($event.target.value)"
            :aria-label="sortLabel"
          >
            <option value="date_asc">Oldest first</option>
            <option value="date_desc">Newest first</option>
            <option value="name_asc">Name A–Z</option>
            <option value="name_desc">Name Z–A</option>
          </select>
        </div>
        <div class="size-btn-wrap" title="Thumbnail size">
          <button class="size-btn" aria-hidden="true" tabindex="-1">
            <span v-html="iconSize"></span>
            <span class="size-letter">{{ thumbSize }}</span>
          </button>
          <select
            class="sort-select-hidden"
            :value="thumbSize"
            @change="setThumbSize($event.target.value)"
            aria-label="Thumbnail size"
          >
            <option value="S">Small</option>
            <option value="M">Medium</option>
            <option value="L">Large</option>
            <option value="XL">Extra large</option>
          </select>
        </div>
        <button
          class="nav-cache-btn"
          title="Generate thumbnails for this folder"
          v-html="iconCache"
          @click="openCacheModal({ path: folderPath, name: breadcrumbs[breadcrumbs.length - 1].label })"
        ></button>
      </nav>

      <!-- ── Grid ── -->
      <div v-if="loading" class="loading">Loading…</div>
      <div v-else-if="fetchError" class="loading fetch-error-box">
        <template v-if="fetchNetworkError">
          <p style="margin:0 0 12px;">Could not reach the server. Your browser may have forgotten the security exception for this site.</p>
          <a :href="certAcceptUrl" target="_blank" rel="noopener" class="cert-accept-link">Open page to re-accept certificate ↗</a>
          <p style="margin:12px 0 0;font-size:13px;color:#aaa;">After accepting the certificate in the new tab, come back here and tap Retry.</p>
          <button class="cert-retry-btn" @click="loadFolder()">Retry</button>
        </template>
        <template v-else>
          <span style="color:#f66;">Error: {{ fetchError }}</span>
        </template>
      </div>
      <div v-else-if="!folders.length && !files.length" class="empty">No media found in this folder.</div>
      <div v-else>

        <!-- Pagination (top) -->
        <div v-if="totalPages > 1" class="pagination">
          <span class="pagination-info">{{ paginationLabel }}</span>
          <button class="pg-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹</button>
          <template v-for="(n, idx) in pageNumbers" :key="idx">
            <span v-if="n === null" class="pg-ellipsis">…</span>
            <button v-else class="pg-btn" :class="{ active: n === page }" @click="goToPage(n)">{{ n }}</button>
          </template>
          <button class="pg-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">›</button>
        </div>

        <div class="grid-container" :class="'grid-size-' + thumbSize.toLowerCase()">

          <!-- Folders -->
          <div
            v-for="folder in pagedFolders"
            :key="folder.path"
            class="grid-item folder"
            @click="navigateTo(folder.path)"
            :title="folder.name"
          >
            <template v-if="folder.thumbnail">
              <div class="grid-thumb-shimmer"></div>
              <img
                class="grid-thumb"
                style="display:none"
                :src="thumbSrc({ path: folder.thumbnail })"
                :alt="folder.name"
                @load="onThumbLoad($event)"
                @error="onThumbError($event, folder)"
              >
              <div class="grid-thumb-placeholder" style="display:none" v-html="iconFolder"></div>
            </template>
            <template v-else>
              <div class="grid-thumb-placeholder" v-html="iconFolder"></div>
            </template>
            <div class="grid-label">{{ folder.name }}</div>
          </div>

          <!-- Files -->
          <div
            v-for="file in pagedFiles"
            :key="file.path"
            class="grid-item"
            @click="openFile(file)"
            :title="file.name"
          >
            <template v-if="file.type === 'photo' || file.type === 'video'">
              <div class="grid-thumb-shimmer"></div>
              <img
                class="grid-thumb"
                style="display:none"
                :src="thumbSrc(file)"
                :alt="file.name"
                @load="onThumbLoad($event)"
                @error="onThumbError($event, file)"
              >
              <div class="grid-thumb-placeholder" style="display:none" v-html="thumbIcon(file)"></div>
            </template>
            <template v-else>
              <div class="grid-thumb-placeholder" v-html="thumbIcon(file)"></div>
            </template>
            <div v-if="file.type === 'video' && !file._thumbFailed" class="video-play-overlay">
              <div class="video-play-btn">
                <svg width="14" height="16" viewBox="0 0 14 16" fill="white" style="margin-left:2px"><polygon points="0,0 14,8 0,16"/></svg>
              </div>
            </div>
            <div class="grid-label">{{ file.name }}</div>
          </div>

        </div>

        <!-- Pagination (bottom) -->
        <div v-if="totalPages > 1" class="pagination">
          <button class="pg-btn" :disabled="page <= 1" @click="goToPage(page - 1)">‹</button>
          <template v-for="(n, idx) in pageNumbers" :key="idx">
            <span v-if="n === null" class="pg-ellipsis">…</span>
            <button v-else class="pg-btn" :class="{ active: n === page }" @click="goToPage(n)">{{ n }}</button>
          </template>
          <button class="pg-btn" :disabled="page >= totalPages" @click="goToPage(page + 1)">›</button>
        </div>

      </div>

      <!-- ── Lightbox ── -->
      <div v-if="lbFile" class="lightbox-overlay" @click.self="onLbOverlayClick"
        @touchstart="onLbTouchStart"
        @touchmove="onLbTouchMove"
        @touchend="onLbTouchEnd"
        @touchcancel="onLbTouchCancel"
      >
        <button class="lightbox-close" @click="closeLightbox()">✕</button>
        <button class="lightbox-nav prev" :disabled="lbIndex <= 0" @click="prevFile()">‹</button>
        <button class="lightbox-nav next" :disabled="lbIndex >= pagedFiles.length - 1" @click="nextFile()">›</button>

        <div class="lightbox-media-wrap"
          :style="{ transform: 'translateX(' + swipeDx + 'px)', transition: swipeTrans ? 'transform .28s cubic-bezier(.4,0,.2,1)' : 'none' }"
        >
          <div class="lightbox-zoom-wrap"
            :style="{ transform: 'translate(' + lbPanX + 'px,' + lbPanY + 'px) scale(' + lbScale + ')', transformOrigin: 'center center' }"
          >
            <img
              v-if="lbFile.type === 'photo'"
              class="lightbox-img"
              :src="lbFullLoaded ? lbSrc : lbThumbSrc"
              :alt="lbFile.name"
              @load="onLbImgLoad"
            >
            <video
              v-else-if="lbFile.type === 'video'"
              ref="mediaEl"
              class="lightbox-video"
              :src="lbSrc"
              :poster="lbThumbSrc"
              controls
              preload="metadata"
              @loadedmetadata="onMediaLoaded"
              @timeupdate="onTimeUpdate"
              @play="playing = true"
              @pause="playing = false"
            ></video>
            <div v-else-if="lbFile.type === 'audio'" class="lightbox-audio-wrap">
              <div class="waveform">
                <div v-for="n in 9" :key="n" class="waveform-bar" :class="{ paused: !playing }"></div>
              </div>
              <div class="audio-filename">{{ lbFile.name }}</div>
              <audio
                ref="mediaEl"
                class="audio-player"
                :src="lbSrc"
                controls
                preload="metadata"
                @loadedmetadata="onMediaLoaded"
                @timeupdate="onTimeUpdate"
                @play="playing = true"
                @pause="playing = false"
              ></audio>
            </div>
          </div>
        </div>

        <div
          v-if="lbFile.type === 'video' || lbFile.type === 'audio'"
          class="lightbox-progress"
        >
          <span>{{ fmtTime(currentTime) }}</span>
          <div class="progress-bar-track" @click="seekTo($event)">
            <div class="progress-bar-fill" :style="{ width: progressPct + '%' }"></div>
          </div>
          <span>{{ fmtTime(duration) }}</span>
        </div>

        <div class="lightbox-filename">{{ lbFile.name }}</div>
      </div>

      <!-- ── Cache generation modal ── -->
      <div
        v-if="cacheModal.show"
        class="modal-overlay"
        @click.self="closeCacheModal()"
      >
        <div class="modal-box">
          <h3 class="modal-title">Generate thumbnails</h3>
          <p class="modal-subtitle">{{ cacheModal.folderName }}</p>

          <!-- Form (idle) -->
          <template v-if="!cacheModal.busy && !cacheModal.done">
            <p class="modal-desc">Scans this folder and its subfolders for photos and videos, and creates thumbnails for any files that don't have one yet. Existing thumbnails are left unchanged.</p>
            <label class="modal-field">
              <span class="modal-label">PIN <span class="modal-label-note">(required for security)</span></span>
              <input
                type="password"
                v-model="cacheModal.pin"
                class="modal-input"
                placeholder="Enter PIN"
                @keyup.enter="startCacheGeneration"
                autofocus
              >
            </label>
            <div class="modal-force-section">
              <label class="modal-check">
                <input type="checkbox" v-model="cacheModal.force">
                <span>Force regeneration of all files</span>
              </label>
              <p class="modal-force-hint">Unchecked: only missing or changed files are processed.</p>
              <p class="modal-force-hint">Replaces all existing thumbnails with freshly generated ones. Use this if thumbnails appear outdated or incorrect.</p>
            </div>
            <div class="modal-actions">
              <button class="modal-btn" @click="closeCacheModal()">Cancel</button>
              <button class="modal-btn primary" @click="startCacheGeneration" :disabled="!cacheModal.pin">Start</button>
            </div>
          </template>

          <!-- Progress (busy) -->
          <template v-if="cacheModal.busy">
            <div class="modal-progress-wrap">
              <div class="modal-progress-bar">
                <div
                  class="modal-progress-fill"
                  :style="{ width: (cacheModal.total ? (cacheModal.progress / cacheModal.total * 100) : 0) + '%' }"
                ></div>
              </div>
            </div>
            <p class="modal-progress-text">
              {{ cacheModal.progress }} / {{ cacheModal.total }}
              <span v-if="cacheModal.errorCount > 0" class="modal-error-count">{{ cacheModal.errorCount }} failed</span>
            </p>
          </template>

          <!-- Done -->
          <template v-if="cacheModal.done">
            <p class="modal-done">
              Done — {{ cacheModal.total - cacheModal.errorCount }} generated
              <span v-if="cacheModal.errorCount > 0">, {{ cacheModal.errorCount }} failed</span>.
            </p>
          </template>
        </div>
      </div>

      <!-- ── Snackbar stack ── -->
      <div class="snack-stack">
        <transition-group name="snack">
          <div
            v-for="s in snacks"
            :key="s.id"
            class="snack"
            :class="'snack-' + s.type"
          >
            <span class="snack-msg">{{ s.msg }}</span>
            <button class="snack-close" @click="removeSnack(s.id)">✕</button>
          </div>
        </transition-group>
      </div>

    </div>
  `,
};

Vue.createApp(App).mount('#app');
