<?php
/** @var string $error @var string $appName @var string $next @var array $mfa */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = Http::basePath();
$m = $mfa['methods'];
$ver = static fn (string $f): string => (string) (@filemtime(WEB_ROOT . '/assets/' . $f) ?: APP_VERSION);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($appName) ?> – Bestätigung</title>
    <link rel="manifest" href="<?= $e($base) ?>/manifest.webmanifest">
    <link rel="icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css?v=<?= $ver('app.css') ?>">
</head>
<body class="login-page">
<main class="login-card">
    <h1><?= $e($appName) ?></h1>
    <p class="msg">Zweiter Schritt der Anmeldung.</p>
    <?php if ($error !== ''): ?>
        <p class="login-error" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <?php if ($m['totp'] || $m['recovery']): ?>
        <form method="post" action="<?= $e($base) ?>/login" autocomplete="off" class="mfa-form">
            <input type="hidden" name="ticket" value="<?= $e($mfa['ticket']) ?>">
            <input type="hidden" name="next" value="<?= $e($next) ?>">
            <label><?= $m['totp'] ? 'Code aus der Authenticator-App' : 'Wiederherstellungscode' ?>
                <input type="text" name="code" inputmode="text" autocomplete="one-time-code" autocapitalize="none" spellcheck="false" required autofocus>
            </label>
            <?php if ($m['totp'] && $m['recovery']): ?>
                <p class="msg">Statt des 6-stelligen Codes geht auch ein Wiederherstellungscode.</p>
            <?php endif; ?>
            <button type="submit">Bestätigen</button>
        </form>
    <?php endif; ?>
    <p class="msg"><a href="<?= $e($base) ?>/login<?= $next !== '/' ? '?next=' . rawurlencode($next) : '' ?>">Zurück zur Anmeldung</a></p>
</main>
</body>
</html>
