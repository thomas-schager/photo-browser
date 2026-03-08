<?php
// Cache-busting: query strings are derived from each file's last-modified time.
// Whenever a file is uploaded/changed on the NAS, filemtime() returns the new
// timestamp and the browser treats it as a fresh URL, bypassing its cache.
$cssV = filemtime(__DIR__ . '/css/app.css');
$jsV  = filemtime(__DIR__ . '/js/app.js');
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Photo Browser</title>
  <link rel="icon" type="image/svg+xml" href="/photos/favicon.svg">
  <link rel="stylesheet" href="/photos/css/app.css?v=<?= $cssV ?>">
</head>
<body>
  <div id="app"></div>
  <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
  <script src="/photos/js/app.js?v=<?= $jsV ?>"></script>
</body>
</html>
