<?php
declare(strict_types=1);

/** HTTP tests against PHP's built-in server: login, CSRF, API, sections and passkey login with a software authenticator. */
require __DIR__ . '/lib.php';

$dataDir = (string) getenv('STARTSEITE_DATA_DIR');
$password = 'test-password-12345-xyz';

// free port
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$base = "http://127.0.0.1:$port";
$origin = $base;

// the first user comes from the environment, exactly like in the Docker image
$env = ['STARTSEITE_DATA_DIR' => $dataDir, 'STARTSEITE_USER' => 'tester', 'STARTSEITE_PASSWORD' => $password, 'PATH' => getenv('PATH') ?: ''];
putenv('STARTSEITE_USER=tester');
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
T::check('wrong password is refused', (new Client($base, $origin))->login('tester', 'wrong-password-123456') !== 303);
$c = new Client($base, $origin);
T::check('correct password logs in', $c->login('tester', $password) === 303);
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
[$code, $r] = $c->req('POST', '/api/username', ['current' => 'wrong', 'username' => 'renamed']);
T::check('renaming needs the current password', $code === 422);
T::check('renaming works with the password', $c->req('POST', '/api/username', ['current' => $password, 'username' => 'renamed'])[0] === 200
    && (new Client($base, $origin))->login('renamed', $password) === 303);

T::section('Passkey login');
$c2 = new Client($base, $origin);
$c2->login('renamed', $password);
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
