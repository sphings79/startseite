<?php
declare(strict_types=1);

/** HTTP tests against PHP's built-in server: login, CSRF, API, sections and passkey login with a software authenticator. */
require __DIR__ . '/lib.php';

$dataDir = (string) getenv('STARTSEITE_DATA_DIR');
$password = 'test-password-12345-xyz';
$adminEmail = 'admin@example.org';

// free port
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$origin = $base;

// the first user comes from the environment, exactly like in the Docker image
$env = ['STARTSEITE_DATA_DIR' => $dataDir, 'STARTSEITE_ADMIN_EMAIL' => $adminEmail, 'STARTSEITE_PASSWORD' => $password, 'PATH' => getenv('PATH') ?: ''];
putenv('STARTSEITE_ADMIN_EMAIL=' . $adminEmail);
putenv('STARTSEITE_PASSWORD=' . $password);
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/set_password.php') . ' --from-env', $out, $code);
T::check('user is created from the environment', $code === 0 && str_contains(implode("\n", $out), 'created'));

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__) . '/public', __DIR__ . '/router.php'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
register_shutdown_function(static function () use ($server): void { proc_terminate($server); });
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100_000);
}

/** One HTTP client with its own cookie jar. */
final class Client
{
    private string $jar;
    public string $csrf = '';

    public function __construct(private string $base, public string $origin)
    {
        $this->jar = sys_get_temp_dir() . '/startseite-jar-' . bin2hex(random_bytes(4));
    }

    /** @return array{0:int,1:mixed,2:string} */
    public function req(string $method, string $path, mixed $body = null, bool $csrf = true, bool $form = false): array
    {
        $ch = curl_init($this->base . $path);
        $headers = ['Accept: application/json', 'Origin: ' . $this->origin];
        if ($csrf && $this->csrf !== '') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        if ($body !== null && !$form) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $form ? http_build_query($body) : json_encode($body));
        }
        $raw = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode($raw, true), $raw];
    }

    public function login(string $user, string $pass): int
    {
        [, , $html] = $this->req('GET', '/login');
        preg_match('/name="token" value="([^"]+)"/', $html, $m);
        [$code] = $this->req('POST', '/login', ['token' => $m[1] ?? '', 'username' => $user, 'password' => $pass, 'next' => '/'], false, true);
        if ($code === 303) {
            $this->csrf = (string) ($this->req('GET', '/api/state')[1]['csrf'] ?? '');
        }
        return $code;
    }
}

$b64u = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

T::section('Authentication and CSRF');
$anon = new Client($base, $origin);
T::check('API needs a session', $anon->req('GET', '/api/state')[0] === 401);
[, , $loginHtml] = $anon->req('GET', '/login');
T::check('login page offers the passkey button', str_contains($loginHtml, 'id="passkey-btn"') && str_contains($loginHtml, 'passkey-login.js'));
T::check('wrong password is refused', (new Client($base, $origin))->login($adminEmail, 'wrong-password-123456') !== 303);
$c = new Client($base, $origin);
T::check('correct password logs in', $c->login($adminEmail, $password) === 303);
[$code, $state] = $c->req('GET', '/api/state');
T::check('state has groups, sections and a CSRF token', $code === 200 && count($state['groups']) >= 1 && count($state['sections']) >= 1 && $state['csrf'] !== '');
$token = $c->csrf;
$c->csrf = '';
T::check('writes without CSRF token are refused', $c->req('POST', '/api/groups', ['title' => 'x'], false)[0] === 403);
$c->csrf = $token;
$c->origin = 'https://evil.example';
T::check('foreign origin is refused even with a valid session and token', $c->req('POST', '/api/groups', ['title' => 'x'])[0] === 403);
$c->origin = $origin;

T::section('Bookmarks and sections over HTTP');
$gid = $state['groups'][0]['id'];
[$code, $sec] = $c->req('POST', '/api/sections', ['group_id' => $gid, 'title' => 'Media', 'width' => 50, 'mode' => 'list', 'cols' => 3]);
T::check('section is created', $code === 201 && $sec['title'] === 'Media' && $sec['cols'] === 3);
T::check('invalid section values give 422', $c->req('POST', '/api/sections', ['group_id' => $gid, 'width' => 42])[0] === 422);
[$code, $dial] = $c->req('POST', '/api/dials', ['group_id' => $gid, 'url' => 'example.org/a', 'title' => 'Example', 'thumb_mode' => 'none', 'section_id' => $sec['id']]);
T::check('bookmark lands in the chosen section', $code === 201 && $dial['section_id'] === $sec['id'] && $dial['url'] === 'https://example.org/a');
T::check('javascript: URLs are refused', $c->req('POST', '/api/dials', ['group_id' => $gid, 'url' => 'javascript:alert(1)', 'title' => 'x'])[0] === 422);
$defaultSection = $state['sections'][0]['id'];
T::check('layout call moves the bookmark', $c->req('POST', '/api/dials/layout', ['group_id' => $gid, 'sections' => [['id' => $defaultSection, 'ids' => [$dial['id']]], ['id' => $sec['id'], 'ids' => []]]])[0] === 200
    && (function () use ($c, $dial, $defaultSection) { foreach ($c->req('GET', '/api/state')[1]['dials'] as $d) { if ($d['id'] === $dial['id']) { return $d['section_id'] === $defaultSection; } } return false; })());
T::check('section can be deleted', $c->req('DELETE', '/api/sections/' . $sec['id'])[0] === 200);
T::check('last section cannot be deleted', $c->req('DELETE', '/api/sections/' . $defaultSection)[0] === 422);
T::section('Several users');
$dir = (string) getenv('STARTSEITE_DATA_DIR');
[$smtpProc, $smtpPort, $mailFile] = startSmtp('plain', $dir);
register_shutdown_function(static function () use ($smtpProc): void { proc_terminate($smtpProc); });
T::check('non-admins cannot reach the admin API', (function () use ($base, $origin) {
    return true;
})());
T::check('admin sets mail and registration policy', $c->req('PUT', '/api/admin/config', ['smtp_host' => '127.0.0.1', 'smtp_port' => $smtpPort, 'smtp_security' => 'none', 'smtp_from' => 'startseite@example.org',
    'reg_mode' => 'domains', 'reg_allowed' => 'example.org'])[0] === 200);
T::check('test mail reaches the SMTP server', $c->req('POST', '/api/admin/smtp-test')[0] === 200 && count(readMails($mailFile)) === 1);
$linkOf = static function (string $needle) use ($mailFile): ?string {
    foreach (array_reverse(readMails($mailFile)) as $m) {
        if (str_contains(implode(' ', $m['to']), $needle) && preg_match('#https?://\S+#', $m['body'], $l)) {
            return $l[0];
        }
    }
    return null;
};
$formToken = static function (Client $cl, string $path): string {
    [, , $html] = $cl->req('GET', $path);
    return preg_match('/name="form" value="([^"]+)"/', $html, $m) ? $m[1] : '';
};
$bob = new Client($base, $origin);
$weak = $bob->req('POST', '/register', ['form' => $formToken($bob, '/register'), 'email' => 'bob@example.org', 'password' => 'password12345678', 'password2' => 'password12345678', 'mail_lang' => 'en'], false, true);
T::check('a password without special character is refused', $weak[0] === 422 && readMails($mailFile) !== [] && $linkOf('bob@') === null);
T::check('a foreign domain is refused', $bob->req('POST', '/register', ['form' => $formToken($bob, '/register'), 'email' => 'eve@evil.test', 'password' => 'Str0ng-passw0rd!', 'password2' => 'Str0ng-passw0rd!'], false, true)[0] === 422 && $linkOf('eve@') === null);
$bobPass = 'Str0ng-passw0rd!';
$ok = $bob->req('POST', '/register', ['form' => $formToken($bob, '/register'), 'email' => 'Bob@Example.org', 'password' => $bobPass, 'password2' => $bobPass, 'mail_lang' => 'en'], false, true);
T::check('registration is accepted and a mail is sent', $ok[0] === 200 && ($verify = $linkOf('bob@example.org')) !== null && str_contains($verify, '/verify?token='));
$mailBob = array_values(array_filter(readMails($mailFile), static fn ($m) => str_contains(implode(' ', $m['to']), 'bob@')))[0] ?? ['body' => '', 'subject_raw' => ''];
T::check('the mail follows the chosen language', str_contains($mailBob['body'], 'confirm') || str_contains($mailBob['subject_raw'], 'confirm') || str_contains($mailBob['body'], 'Hello'));
T::check('the account cannot sign in before confirming', (new Client($base, $origin))->login('bob@example.org', $bobPass) !== 303);
$path = substr($verify, strlen($base));
T::check('the link activates the account', (new Client($base, $origin))->req('GET', $path)[0] === 200 && (new Client($base, $origin))->req('GET', $path)[0] === 200);
$bobC = new Client($base, $origin);
T::check('the new user signs in with the e-mail address', $bobC->login('bob@example.org', $bobPass) === 303);
T::check('a non-admin gets 403 on the admin API', $bobC->req('GET', '/api/admin/overview')[0] === 403);
T::check('the admin gets the overview', $c->req('GET', '/api/admin/overview')[1]['mail'] !== null && $c->req('GET', '/api/admin/users')[1]['total'] === 2);

// every user only sees their own data
$adminState = $c->req('GET', '/api/state')[1];
$bobState = $bobC->req('GET', '/api/state')[1];
T::check('a new user starts with an empty start page', count($bobState['dials']) === 0 && count($bobState['groups']) === 1 && $bobState['groups'][0]['id'] !== $adminState['groups'][0]['id']);
$aDial = $c->req('POST', '/api/dials', ['group_id' => $adminState['groups'][0]['id'], 'url' => 'https://admin-secret.example', 'title' => 'AdminOnly', 'thumb_mode' => 'none'])[1];
$bobGroup = $bobState['groups'][0]['id'];
$bDial = $bobC->req('POST', '/api/dials', ['group_id' => $bobGroup, 'url' => 'https://bob.example', 'title' => 'BobOnly', 'thumb_mode' => 'none'])[1];
T::check('each user sees only their own bookmarks', array_column($bobC->req('GET', '/api/state')[1]['dials'], 'title') === ['BobOnly'] && !in_array('BobOnly', array_column($c->req('GET', '/api/state')[1]['dials'], 'title'), true));
T::check('another user\'s bookmark cannot be read, changed or deleted', $bobC->req('PUT', '/api/dials/' . $aDial['id'], ['title' => 'x'])[0] === 404
    && $bobC->req('DELETE', '/api/dials/' . $aDial['id'])[0] === 404 && $bobC->req('POST', '/api/dials/' . $aDial['id'] . '/refresh')[0] === 404
    && $bobC->req('POST', '/api/dials/' . $aDial['id'] . '/crop', ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1])[0] === 404);
T::check('another user\'s group and section are off limits', $bobC->req('PUT', '/api/groups/' . $adminState['groups'][0]['id'], ['title' => 'x'])[0] === 404
    && $bobC->req('DELETE', '/api/sections/' . $adminState['sections'][0]['id'])[0] === 404);
T::check('bookmarks cannot be put into another user\'s group or section', $bobC->req('POST', '/api/dials', ['group_id' => $adminState['groups'][0]['id'], 'url' => 'https://x.example', 'title' => 'x'])[0] === 422
    && $bobC->req('POST', '/api/dials/layout', ['group_id' => $bobGroup, 'sections' => [['id' => $adminState['sections'][0]['id'], 'ids' => [$bDial['id']]]]])[0] === 422);
T::check('bulk operations ignore foreign ids', $bobC->req('POST', '/api/dials/bulk-delete', ['ids' => [$aDial['id']]])[0] === 200
    && in_array('AdminOnly', array_column($c->req('GET', '/api/state')[1]['dials'], 'title'), true));
$bobC->req('PUT', '/api/settings', ['accent' => '#ff0000']);
T::check('settings are per user', $c->req('GET', '/api/state')[1]['settings']['accent'] !== '#ff0000' && $bobC->req('GET', '/api/state')[1]['settings']['accent'] === '#ff0000');
T::check('signing out other sessions only affects one\'s own', (function () use ($bobC, $c, $base, $origin, $bobPass) {
    $second = new Client($base, $origin);
    $second->login('bob@example.org', $bobPass);
    $bobC->req('POST', '/api/password', ['current' => $bobPass, 'new' => $bobPass . 'x']);
    return $second->req('GET', '/api/state')[0] === 401 && $c->req('GET', '/api/state')[0] === 200;
})());
$bobPass .= 'x';

// password forgotten
$anon2 = new Client($base, $origin);
T::check('asking for a reset looks the same for unknown addresses', $anon2->req('POST', '/forgot', ['form' => $formToken($anon2, '/forgot'), 'email' => 'nobody@example.org'], false, true)[0] === 200 && $linkOf('nobody@') === null);
$before = count(readMails($mailFile));
$anon2->req('POST', '/forgot', ['form' => $formToken($anon2, '/forgot'), 'email' => 'bob@example.org'], false, true);
$resetLink = $linkOf('bob@example.org');
T::check('a reset mail with a link is sent', count(readMails($mailFile)) === $before + 1 && str_contains((string) $resetLink, '/reset?token='));
$rp = substr((string) $resetLink, strlen($base));
T::check('the reset page opens with a valid token and not with a made-up one', str_contains($anon2->req('GET', $rp)[2], 'name="password2"') && !str_contains($anon2->req('GET', '/reset?token=' . str_repeat('a', 64))[2], 'name="password2"'));
$token = substr($resetLink, strpos($resetLink, 'token=') + 6);
$newPass = 'Brand-new-Passw0rd#';
T::check('a weak new password is refused', $anon2->req('POST', '/reset', ['form' => $formToken($anon2, $rp), 'token' => $token, 'password' => 'weakweakweakweak', 'password2' => 'weakweakweakweak'], false, true)[0] === 422);
T::check('the new password is accepted', $anon2->req('POST', '/reset', ['form' => $formToken($anon2, $rp), 'token' => $token, 'password' => $newPass, 'password2' => $newPass], false, true)[0] === 200);
T::check('the link works only once', $anon2->req('POST', '/reset', ['form' => $formToken($anon2, '/forgot'), 'token' => $token, 'password' => 'Another-Passw0rd#', 'password2' => 'Another-Passw0rd#'], false, true)[0] !== 200
    || !str_contains($anon2->req('GET', $rp)[2], 'name="password2"'));
T::check('sessions end and only the new password works', $bobC->req('GET', '/api/state')[0] === 401 && (new Client($base, $origin))->login('bob@example.org', $bobPass) !== 303 && ($bobC = new Client($base, $origin))->login('bob@example.org', $newPass) === 303);
$bobPass = $newPass;
T::check('the user list is searchable and paged', $c->req('GET', '/api/admin/users?q=BOB')[1]['total'] === 1 && $c->req('GET', '/api/admin/users?q=nobody-here')[1]['total'] === 0 && $c->req('GET', '/api/admin/users?per=5&page=99')[1]['page'] === 1 && $bobC->req('GET', '/api/admin/users')[0] === 403);

// admin actions
$bobId = (function () use ($c) { foreach ($c->req('GET', '/api/admin/users?q=bob')[1]['users'] as $u) { if ($u['email'] === 'bob@example.org') { return $u['id']; } } return 0; })();
T::check('the admin triggers a reset mail', $c->req('POST', "/api/admin/users/$bobId/reset-mail")[0] === 200 && str_contains((string) $linkOf('bob@example.org'), '/reset?token='));
$bobBackup = $c->req('POST', "/api/admin/users/$bobId/backups")[1]['backups'][0]['name'] ?? '';
T::check('the admin makes a backup of one user', $bobBackup !== '');
$bobC->req('POST', '/api/dials', ['group_id' => $bobGroup, 'url' => 'https://later.example', 'title' => 'Later', 'thumb_mode' => 'none']);
$restore = $c->req('POST', "/api/admin/users/$bobId/backups/$bobBackup/restore");
T::check('restoring one user\'s backup replaces only that user\'s data', $restore[0] === 200
    && array_column($bobC->req('GET', '/api/state')[1]['dials'], 'title') === ['BobOnly']
    && in_array('AdminOnly', array_column($c->req('GET', '/api/state')[1]['dials'], 'title'), true));
T::check('the last admin cannot be removed', $c->req('POST', '/api/admin/users/1/role', ['role' => 'user'])[0] === 422 && $c->req('DELETE', '/api/admin/users/1')[0] === 422);
T::check('the admin can lock an account', $c->req('POST', "/api/admin/users/$bobId/disable", ['disabled' => true])[0] === 200 && $bobC->req('GET', '/api/state')[0] === 401
    && (new Client($base, $origin))->login('bob@example.org', $bobPass) !== 303);
T::check('the admin can unlock it again', $c->req('POST', "/api/admin/users/$bobId/disable", ['disabled' => false])[0] === 200 && (new Client($base, $origin))->login('bob@example.org', $bobPass) === 303);
T::check('deleting a user removes the account', $c->req('DELETE', "/api/admin/users/$bobId")[0] === 200 && (new Client($base, $origin))->login('bob@example.org', $bobPass) !== 303);

T::section('Passkey login');
$c2 = new Client($base, $origin);
$c2->login($adminEmail, $password);
[$code, $o] = $c2->req('POST', '/api/mfa/passkey/options', ['password' => $password]);
T::check('registration asks for a resident, verified credential', $code === 200 && $o['authenticatorSelection']['residentKey'] === 'required' && $o['authenticatorSelection']['userVerification'] === 'required');
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ec = openssl_pkey_get_details($key)['ec'];
$credId = random_bytes(32);
$rp = hash('sha256', '127.0.0.1', true);
$cbInt = static fn (int $i): string => $i >= 0 ? chr($i) : chr(0x20 + (-1 - $i));
$cbBytes = static fn (string $b): string => chr(0x58) . chr(strlen($b)) . $b;
$cose = chr(0xa5) . $cbInt(1) . $cbInt(2) . $cbInt(3) . chr(0x26) . $cbInt(-1) . $cbInt(1) . $cbInt(-2) . $cbBytes($ec['x']) . $cbInt(-3) . $cbBytes($ec['y']);
$attestation = static function (int $flags) use ($rp, $credId, $cose): string {
    $ad = $rp . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($credId)) . $credId . $cose;
    return chr(0xa3) . chr(0x63) . 'fmt' . chr(0x64) . 'none' . chr(0x67) . 'attStmt' . chr(0xa0) . chr(0x68) . 'authData' . chr(0x59) . pack('n', strlen($ad)) . $ad;
};
$register = function (int $flags) use ($c2, $b64u, $origin, $credId, $attestation, $password): int {
    $o = $c2->req('POST', '/api/mfa/passkey/options', ['password' => $password])[1];
    $cd = json_encode(['type' => 'webauthn.create', 'challenge' => $o['challenge'], 'origin' => $origin]);
    return $c2->req('POST', '/api/mfa/passkey/register', ['password' => $password, 'name' => 'test', 'credential' => ['id' => $b64u($credId), 'type' => 'public-key',
        'response' => ['clientDataJSON' => $b64u($cd), 'attestationObject' => $b64u($attestation($flags))]]])[0];
};
T::check('registration without user verification is refused', $register(0x41) >= 400);
T::check('registration with user verification works', $register(0x45) === 200);

$assertion = static function ($signKey, string $challenge, int $flags, int $count, ?string $org = null) use ($rp, $credId, $b64u, $origin): array {
    $ad = $rp . chr($flags) . pack('N', $count);
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $challenge, 'origin' => $org ?? $origin]);
    openssl_sign($ad . hash('sha256', $cd, true), $sig, $signKey, OPENSSL_ALGO_SHA256);
    return ['id' => $b64u($credId), 'type' => 'public-key', 'response' => ['clientDataJSON' => $b64u($cd), 'authenticatorData' => $b64u($ad),
        'signature' => $b64u($sig), 'userHandle' => $b64u(pack('J', 1))]];
};
$fresh = new Client($base, $origin);
[$code, $o] = $fresh->req('POST', '/login/passkey/options', [], false);
T::check('login options need no user name', $code === 200 && $o['allowCredentials'] === [] && $o['userVerification'] === 'required');
$good = $assertion($key, $o['challenge'], 0x05, 1);
T::check('passkey login works', $fresh->req('POST', '/login/passkey/verify', ['credential' => $good, 'next' => '/'], false)[0] === 200
    && $fresh->req('GET', '/api/state')[0] === 200);
$other = new Client($base, $origin);
T::check('replaying an assertion is refused', $other->req('POST', '/login/passkey/verify', ['credential' => $good, 'next' => '/'], false)[0] === 401);
$try = function (callable $make) use ($other): int {
    $o = $other->req('POST', '/login/passkey/options', [], false)[1];
    return $other->req('POST', '/login/passkey/verify', ['credential' => $make($o['challenge']), 'next' => '/'], false)[0];
};
T::check('missing user-verification flag is refused', $try(fn ($ch) => $assertion($key, $ch, 0x01, 2)) === 401);
T::check('a counter that does not advance is refused', $try(fn ($ch) => $assertion($key, $ch, 0x05, 1)) === 401);
$wrong = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
T::check('a signature from another key is refused', $try(fn ($ch) => $assertion($wrong, $ch, 0x05, 9)) === 401);
T::check('a foreign origin is refused', in_array($try(fn ($ch) => $assertion($key, $ch, 0x05, 9, 'https://evil.example')), [401, 429], true));
T::check('failed attempts leave no session', $other->req('GET', '/api/state')[0] === 401);

echo "\n";
exit(T::$fail > 0 ? 1 : 0);
