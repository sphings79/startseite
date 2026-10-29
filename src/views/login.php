<?php
/** @var string $error @var string $username @var string $token @var string $appName @var bool $noUser @var string $next */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = Http::basePath();
$ver = static fn (string $f): string => (string) (@filemtime(WEB_ROOT . '/assets/' . $f) ?: APP_VERSION);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($appName) ?> – Anmelden</title>
    <link rel="manifest" href="<?= $e($base) ?>/manifest.webmanifest">
    <link rel="icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body class="login-page">
<main class="login-card">
    <h1><?= $e($appName) ?></h1>
    <?php if ($noUser): ?>
        <p class="login-error">Noch kein Benutzer angelegt. Auf dem Server ausführen: <code>php bin/set_password.php</code></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="login-error" role="alert"><?= $e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= $e($base) ?>/login" autocomplete="on">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <input type="hidden" name="next" value="<?= $e($next) ?>">
        <label>Benutzername
            <input type="text" name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>
        <label>Passwort
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Anmelden</button>
    </form>
    <p class="login-error" id="passkey-error" role="alert" hidden></p>
    <button type="button" id="passkey-btn" class="alt" hidden data-base="<?= $e($base) ?>" data-next="<?= $e($next) ?>">Mit Passkey anmelden</button>
</main>
<script src="<?= $e($base) ?>/assets/passkey-login.js?v=<?= $ver('passkey-login.js') ?>"></script>
</body>
</html>
