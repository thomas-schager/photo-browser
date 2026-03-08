<?php
/**
 * Cache directory write test
 * Verifies that the web server user can write to the thumbnail cache directory.
 * All four operations must SUCCEED for the test to pass.
 */

$cacheRoot  = realpath(__DIR__ . '/../cache') ?: (__DIR__ . '/../cache');
$testDir    = $cacheRoot . '/_test_' . bin2hex(random_bytes(4));
$testFile   = $testDir . '/write_test.tmp';

// ── Run tests ─────────────────────────────────────────────────

$results = [];

// 1. Cache root exists
$rootExists = is_dir($cacheRoot);
$results[] = [
    'label'   => 'Cache root exists: <code>' . htmlspecialchars($cacheRoot) . '</code>',
    'ok'      => $rootExists,
    'detail'  => $rootExists ? 'Directory found.' : 'Directory does not exist — it will be created on first thumbnail generation.',
];

// 2. Cache root is writable
$rootWritable = is_writable($cacheRoot) || (!$rootExists && is_writable(dirname($cacheRoot)));
$results[] = [
    'label'   => 'Cache root is writable',
    'ok'      => $rootWritable,
    'detail'  => $rootWritable ? 'Write permission confirmed.' : 'Not writable — check permissions on the cache directory.',
];

// 3. Can create a subdirectory
$dirCreated = @mkdir($testDir, 0755, true);
$results[] = [
    'label'   => 'Create subdirectory in cache',
    'ok'      => $dirCreated,
    'detail'  => $dirCreated ? 'Subdirectory created successfully.' : 'mkdir() failed — the web server user cannot create directories here.',
];

// 4. Can write a file
$fileWritten = false;
if ($dirCreated) {
    $fileWritten = @file_put_contents($testFile, 'cache write test ' . date('c')) !== false;
}
$results[] = [
    'label'   => 'Write a file inside cache subdirectory',
    'ok'      => $fileWritten,
    'detail'  => $fileWritten ? 'File written successfully.' : 'file_put_contents() failed — cannot write files to the cache.',
];

// 5. Can delete the file and directory
$cleaned = false;
if ($dirCreated) {
    if ($fileWritten) @unlink($testFile);
    $cleaned = @rmdir($testDir);
}
$results[] = [
    'label'   => 'Clean up test directory',
    'ok'      => $cleaned,
    'detail'  => $cleaned ? 'Cleanup succeeded.' : 'Could not remove test directory (non-critical).',
];

// ── Overall ───────────────────────────────────────────────────

// The critical checks are 2–4; cleanup failure (5) is non-critical.
$critical   = array_slice($results, 1, 3);   // indices 1, 2, 3
$allGood    = !in_array(false, array_column($critical, 'ok'), true);

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Cache Write Test</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #111;
      color: #ddd;
      padding: 40px 20px;
    }
    h1 { font-size: 20px; margin-bottom: 8px; color: #fff; }
    .subtitle { font-size: 13px; color: #666; margin-bottom: 32px; }
    .cases { display: flex; flex-direction: column; gap: 12px; max-width: 640px; }
    .case {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      background: #1a1a1a;
      border: 1px solid #2a2a2a;
      border-radius: 8px;
      padding: 14px 16px;
    }
    .badge {
      flex-shrink: 0;
      width: 28px; height: 28px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 15px; font-weight: 700;
    }
    .badge.pass  { background: #1a3a1a; color: #4caf50; }
    .badge.fail  { background: #3a1a1a; color: #f44336; }
    .badge.warn  { background: #3a2e1a; color: #ff9800; }
    .case-body   { flex: 1; }
    .case-label  { font-size: 14px; color: #ccc; margin-bottom: 4px; }
    .case-label code { background: #252525; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
    .case-detail { font-size: 12px; color: #666; }
    .case-detail.fail { color: #f44336; }
    .overall {
      max-width: 640px; margin-top: 28px; padding: 18px 20px;
      border-radius: 8px; display: flex; align-items: center; gap: 14px;
      font-size: 15px; font-weight: 600;
    }
    .overall.pass { background: #1a3a1a; border: 1px solid #2d6b2d; color: #4caf50; }
    .overall.fail { background: #3a1a1a; border: 1px solid #6b2d2d; color: #f44336; }
    .overall-icon { font-size: 22px; }
    .note { font-size: 12px; color: #444; margin-top: 24px; max-width: 640px; }
  </style>
</head>
<body>

<h1>Cache Write Test</h1>
<p class="subtitle">
  All critical operations must <strong>succeed</strong> for thumbnail caching to work.
</p>

<div class="cases">
<?php foreach ($results as $i => $r):
  // Check 5 (cleanup) is non-critical — show as warning on failure
  $isCritical = $i < 4;
  if ($r['ok']) {
      $badgeClass = 'pass'; $icon = '✓';
  } elseif (!$isCritical) {
      $badgeClass = 'warn'; $icon = '!';
  } else {
      $badgeClass = 'fail'; $icon = '✗';
  }
?>
  <div class="case">
    <div class="badge <?= $badgeClass ?>"><?= $icon ?></div>
    <div class="case-body">
      <div class="case-label"><?= $r['label'] ?></div>
      <div class="case-detail <?= (!$r['ok'] && $isCritical) ? 'fail' : '' ?>"><?= htmlspecialchars($r['detail']) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="overall <?= $allGood ? 'pass' : 'fail' ?>">
  <span class="overall-icon"><?= $allGood ? '✓' : '✗' ?></span>
  <span><?= $allGood
    ? 'Cache directory is writable — thumbnail generation will work.'
    : 'Cache directory is not writable — thumbnails cannot be saved. Check directory permissions.' ?></span>
</div>

<p class="note">
  Cache root: <code><?= htmlspecialchars($cacheRoot) ?></code>
</p>

</body>
</html>
