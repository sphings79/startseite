<?php
declare(strict_types=1);

/**
 * Administration from the command line (works without mail, for the case that nobody can sign in).
 *
 *   php bin/admin.php list
 *   php bin/admin.php create <email> [--role=admin|user]    new account; the password is read from stdin
 *   php bin/admin.php password <email>                      new password; ends the user's sessions
 *   php bin/admin.php claim <email>                         gives the address to the account created before multi-user support (password stays)
 *   php bin/admin.php reset-2fa <email>                     removes the authenticator app, passkeys and recovery codes
 *   php bin/admin.php role <email> admin|user
 *   php bin/admin.php registration off|any|domains [domain-or-address ...]   who may register; the list replaces the old one
 *   php bin/admin.php url <https://startseite.example.org/path>              base URL for links in mails
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn ($a) => !str_starts_with($a, '--')));
$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) {
        $opts[$m[1]] = $m[2];
    }
}
$cmd = $args[0] ?? '';

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): never { fwrite(STDERR, $s . "\n"); exit(1); }
function readPassword(string $prompt): string
{
    $tty = stream_isatty(STDIN);
    if ($tty) {
        fwrite(STDERR, $prompt);
        system('stty -echo');
    }
    $p = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        system('stty echo');
        fwrite(STDERR, "\n");
    }
    return $p;
}
function userByEmail(string $email): array
{
    return Accounts::byEmail(Accounts::normalizeEmail($email)) ?? fail('No such user.');
}

try {
    switch ($cmd) {
        case 'list':
            $page = 1;
            do {
                $res = Accounts::listUsers('', $page++, 100);
                foreach ($res['users'] as $u) {
                    out(sprintf('#%d  %-35s %-6s %-9s %d bookmarks  last login %s', $u['id'], $u['email'], $u['role'], $u['status'], $u['dials'], $u['last_login'] ? date('Y-m-d H:i', $u['last_login']) : '-'));
                }
            } while ($res['page'] < $res['pages']);
            $cfg = AppConfig::all();
            out('registration: ' . $cfg['reg_mode'] . ($cfg['reg_allowed'] !== '' ? ' (' . str_replace("\n", ', ', $cfg['reg_allowed']) . ')' : ''));
            break;
        case 'create':
            $email = $args[1] ?? fail('Usage: create <email> [--role=admin|user]');
            $id = Auth::setUser($email, readPassword("Password for $email: "), ($opts['role'] ?? '') === 'admin' ? 'admin' : (($opts['role'] ?? '') === 'user' ? 'user' : null));
            out("Account #$id saved.");
            break;
        case 'password':
            $u = userByEmail($args[1] ?? fail('Usage: password <email>'));
            Auth::setUser((string) $u['email'], readPassword('New password: '));
            out('Password changed; sessions ended.');
            break;
        case 'claim':
            $email = Accounts::normalizeEmail($args[1] ?? fail('Usage: claim <email>'));
            if (Accounts::byEmail($email)) {
                fail('This address already belongs to an account.');
            }
            $legacy = Db::one('SELECT * FROM users WHERE email IS NULL ORDER BY id LIMIT 1') ?? fail('There is no account without an address.');
            Db::run("UPDATE users SET email = ?, username = ?, role = 'admin', status = 'active' WHERE id = ?", [$email, $email, $legacy['id']]);
            Db::run('DELETE FROM sessions WHERE user_id = ?', [$legacy['id']]);
            out("Account #{$legacy['id']} (was '{$legacy['username']}') is now $email and admin. The password is unchanged.");
            break;
        case 'reset-2fa':
            $u = userByEmail($args[1] ?? fail('Usage: reset-2fa <email>'));
            Mfa::reset((int) $u['id']);
            out('Second factor removed.');
            break;
        case 'role':
            $u = userByEmail($args[1] ?? fail('Usage: role <email> admin|user'));
            Accounts::setRole(0, (int) $u['id'], ($args[2] ?? '') === 'admin' ? 'admin' : 'user');
            out('Role saved.');
            break;
        case 'registration':
            $mode = $args[1] ?? fail('Usage: registration off|any|domains [domain-or-address ...]');
            AppConfig::update(['reg_mode' => $mode, 'reg_allowed' => implode("\n", array_slice($args, 2))]);
            out('Registration: ' . $mode . (count($args) > 2 ? ' for ' . implode(', ', array_slice($args, 2)) : ''));
            break;
        case 'url':
            AppConfig::update(['app_url' => $args[1] ?? fail('Usage: url <https://...>')]);
            out('Base URL saved.');
            break;
        default:
            fail("Usage: php bin/admin.php list | create | password | claim | reset-2fa | role | registration | url\n(see the comment at the top of this file)");
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    fail($e->getMessage());
}
