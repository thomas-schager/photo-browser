<?php
/**
 * File permission test
 * Checks that the web server user cannot write to the web root.
 * All three operations must be DENIED for the test to pass.
 */

// Paths are one level up (web root), relative to this script in /api/
$root        = realpath(__DIR__ . '/..');
$sourcefile  = $root . '/test.txt';
$renamedFile = $root . '/test-changed.txt';
$createdFile = $root . '/test-created.txt';

// ── Run tests ─────────────────────────────────────────────────

$results = [];

// 1. Rename
$renamed = @rename($sourcefile, $renamedFile);
$results[] = [
    'label'    => 'Rename <code>test.txt</code> → <code>test-changed.txt</code>',
    'allowed'  => $renamed,
    'detail'   => $renamed ? 'File was renamed successfully.' : 'Rename was denied.',
];
// Undo if it accidentally succeeded
if ($renamed) @rename($renamedFile, $sourcefile);

// 2. Delete
$deleted = @unlink($sourcefile);
$results[] = [
    'label'   => 'Delete <code>test.txt</code>',
    'allowed' => $deleted,
    'detail'  => $deleted ? 'File was deleted.' : 'Delete was denied.',
];
// Recreate if it accidentally succeeded
if ($deleted) @file_put_contents($sourcefile, "asdf\n");

// 3. Create
$created = @file_put_contents($createdFile, 'created by test-access.php') !== false;
$results[] = [
    'label'   => 'Create new file <code>test-created.txt</code>',
    'allowed' => $created,
    'detail'  => $created ? 'File was created.' : 'Create was denied.',
];
// Remove if it accidentally succeeded
if ($created) @unlink($createdFile);

// ── Determine overall result ──────────────────────────────────

$allDenied = !array_filter(array_column($results, 'allowed'));

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>File Access Test</title>
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
    .cases { display: flex; flex-direction: column; gap: 12px; max-width: 600px; }
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
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      font-weight: 700;
    }
    .badge.pass { background: #1a3a1a; color: #4caf50; }
    .badge.fail { background: #3a1a1a; color: #f44336; }
    .case-body { flex: 1; }
    .case-label { font-size: 14px; color: #ccc; margin-bottom: 4px; }
    .case-label code { background: #252525; padding: 1px 5px; border-radius: 3px; font-size: 13px; }
    .case-detail { font-size: 12px; color: #666; }
    .case-detail.fail { color: #f44336; }

    .overall {
      max-width: 600px;
      margin-top: 28px;
      padding: 18px 20px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      gap: 14px;
      font-size: 15px;
      font-weight: 600;
    }
    .overall.pass { background: #1a3a1a; border: 1px solid #2d6b2d; color: #4caf50; }
    .overall.fail { background: #3a1a1a; border: 1px solid #6b2d2d; color: #f44336; }
    .overall-icon { font-size: 22px; }
    .root { font-size: 12px; color: #444; margin-top: 28px; max-width: 600px; }
  </style>
</head>
<body>

<h1>File Access Test</h1>
<p class="subtitle">
  All three operations must be <strong>denied</strong> for the test to pass.
  Tested path: <code><?= htmlspecialchars($root) ?></code>
</p>

<div class="cases">
<?php foreach ($results as $r): ?>
  <?php $pass = !$r['allowed']; ?>
  <div class="case">
    <div class="badge <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✓' : '✗' ?></div>
    <div class="case-body">
      <div class="case-label"><?= $r['label'] ?></div>
      <div class="case-detail <?= $pass ? '' : 'fail' ?>"><?= htmlspecialchars($r['detail']) ?></div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="overall <?= $allDenied ? 'pass' : 'fail' ?>">
  <span class="overall-icon"><?= $allDenied ? '✓' : '✗' ?></span>
  <span><?= $allDenied
    ? 'All operations denied — permissions are correctly restricted.'
    : 'One or more operations succeeded — the web server has unexpected write access.' ?></span>
</div>

</body>
</html>
