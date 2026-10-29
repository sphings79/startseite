<?php
/** @var array $boot */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = Http::basePath();
// Cache-busting by file mtime: parent .htaccess files may mark assets as immutable.
$ver = static fn (string $f): string => (string) (@filemtime(WEB_ROOT . '/assets/' . $f) ?: APP_VERSION);
$json = json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light dark">
    <title><?= $e((string) $boot['settings']['app_name']) ?></title>
    <link rel="manifest" href="<?= $e($base) ?>/manifest.webmanifest">
    <link rel="icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="apple-touch-icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css?v=<?= $ver('app.css') ?>">
</head>
<body>
<div id="bg"></div>
<div id="app"></div>
<script type="application/json" id="boot"><?= $json ?></script>
<script src="<?= $e($base) ?>/assets/app.js?v=<?= $ver('app.js') ?>"></script>
</body>
</html>
