<?php
/** @var array $L @var string $lang @var string $notice @var string $error @var ?string $form @var string $token @var string $email @var string $formToken @var string $appName @var string $path */
$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$base = Http::basePath();
$other = $lang === 'de' ? 'en' : 'de';
$title = match ($form ?? $path) { 'register', '/register' => $L['register'], 'forgot', '/forgot' => $L['forgot'], 'reset', '/reset' => $L['reset'], default => $appName };
?>
<!doctype html>
<html lang="<?= $e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= $e($appName) ?>: <?= $e($title) ?></title>
    <link rel="icon" href="<?= $e($base) ?>/icons/192.png">
    <link rel="stylesheet" href="<?= $e($base) ?>/assets/app.css">
</head>
<body class="login-page">
<main class="login-card">
    <h1><?= $e($appName) ?></h1>
    <h2 class="acct-title"><?= $e($title) ?></h2>
    <?php if ($notice !== ''): ?><p class="msg ok" role="status"><?= $e($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="login-error" role="alert"><?= $e($error) ?></p><?php endif; ?>

    <?php if ($form === 'register'): ?>
    <form method="post" action="<?= $e($base) ?>/register?lang=<?= $e($lang) ?>" autocomplete="on">
        <input type="hidden" name="form" value="<?= $e($formToken) ?>">
        <label><?= $e($L['email']) ?>
            <input type="email" name="email" value="<?= $e($email) ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus>
        </label>
        <label><?= $e($L['password']) ?>
            <input type="password" name="password" autocomplete="new-password" minlength="12" required>
        </label>
        <label><?= $e($L['password2']) ?>
            <input type="password" name="password2" autocomplete="new-password" minlength="12" required>
        </label>
        <p class="msg"><?= $e($L['hint']) ?></p>
        <label><?= $e($L['mailnote']) ?>
            <select name="mail_lang"><option value="de"<?= $lang === 'de' ? ' selected' : '' ?>>Deutsch</option><option value="en"<?= $lang === 'en' ? ' selected' : '' ?>>English</option></select>
        </label>
        <button type="submit"><?= $e($L['register']) ?></button>
    </form>
    <?php elseif ($form === 'forgot'): ?>
    <p class="msg"><?= $e($L['forgot_text']) ?></p>
    <form method="post" action="<?= $e($base) ?>/forgot?lang=<?= $e($lang) ?>" autocomplete="on">
        <input type="hidden" name="form" value="<?= $e($formToken) ?>">
        <label><?= $e($L['email']) ?>
            <input type="email" name="email" value="<?= $e($email) ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required autofocus>
        </label>
        <button type="submit"><?= $e($L['send']) ?></button>
    </form>
    <?php elseif ($form === 'reset'): ?>
    <form method="post" action="<?= $e($base) ?>/reset?lang=<?= $e($lang) ?>" autocomplete="off">
        <input type="hidden" name="form" value="<?= $e($formToken) ?>">
        <input type="hidden" name="token" value="<?= $e($token) ?>">
        <label><?= $e($L['newpass']) ?>
            <input type="password" name="password" autocomplete="new-password" minlength="12" required autofocus>
        </label>
        <label><?= $e($L['newpass2']) ?>
            <input type="password" name="password2" autocomplete="new-password" minlength="12" required>
        </label>
        <p class="msg"><?= $e($L['hint']) ?></p>
        <button type="submit"><?= $e($L['save']) ?></button>
    </form>
    <?php endif; ?>

    <p class="msg acct-links">
        <a href="<?= $e($base) ?>/login"><?= $e($form === 'register' ? $L['have'] : $L['to_login']) ?></a>
        · <a href="<?= $e($base . $path) ?>?lang=<?= $e($other) ?><?= $token !== '' ? '&amp;token=' . $e($token) : '' ?>"><?= $e($L['lang_' . $other]) ?></a>
    </p>
</main>
</body>
</html>
