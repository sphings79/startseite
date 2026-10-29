<?php
declare(strict_types=1);

/**
 * Creates or updates the single user.
 *
 *   php bin/set_password.php [username]        password is read from stdin (hidden on a terminal)
 *   php bin/set_password.php --from-env        STARTSEITE_USER / STARTSEITE_PASSWORD, only if no user exists yet
 *   php bin/set_password.php --reset-2fa       removes TOTP, passkeys and recovery codes (lost second factor)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

if (in_array('--reset-2fa', $argv, true)) {
    Mfa::reset();
    fwrite(STDOUT, "Second factor removed; the password alone logs in again.\n");
    exit(0);
}

if (in_array('--from-env', $argv, true)) {
    $user = getenv('STARTSEITE_USER') ?: 'admin';
    $pass = getenv('STARTSEITE_PASSWORD');
    if (Auth::userCount() > 0 || $pass === false || $pass === '') {
        exit(0);
    }
    try {
        Auth::setUser($user, $pass);
        fwrite(STDOUT, "User '$user' created.\n");
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

$username = $argv[1] ?? 'admin';
$tty = stream_isatty(STDIN);
if ($tty) {
    fwrite(STDERR, "Passwort für '$username': ");
    system('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($tty) {
    system('stty echo');
    fwrite(STDERR, "\n");
}

try {
    Auth::setUser($username, $password);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "Benutzer '$username' gespeichert; alle Sitzungen wurden beendet.\n";
