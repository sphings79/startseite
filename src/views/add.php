<?php
/** @var array $boot */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = Http::basePath();
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
    <title><?= $e((string) $boot['app_name']) ?> – Hinzufügen</title>
    <link rel="icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css?v=<?= $ver('app.css') ?>">
</head>
<body class="login-page add-page">
<main class="login-card">
    <h1>Hinzufügen</h1>
    <p class="login-error" id="add-error" role="alert" hidden></p>
    <p class="msg" id="add-existing" hidden></p>
    <form id="add-form" autocomplete="off">
        <label>URL
            <input type="text" id="add-url" inputmode="url" autocapitalize="none" spellcheck="false" required>
        </label>
        <label>Titel
            <input type="text" id="add-title" placeholder="Wird von der Seite geholt …">
        </label>
        <label>Gruppe
            <select id="add-group"></select>
        </label>
        <button type="submit" id="add-submit">Hinzufügen</button>
    </form>
    <p class="msg" id="add-done" hidden></p>
</main>
<script type="application/json" id="boot"><?= $json ?></script>
<script src="<?= $e($base) ?>/assets/add.js?v=<?= $ver('add.js') ?>"></script>
</body>
</html>
