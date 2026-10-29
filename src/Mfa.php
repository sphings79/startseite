<?php
declare(strict_types=1);

/**
 * Optional extras to the password login (OWASP Authentication / MFA cheat sheets):
 *  - TOTP (RFC 6238) as second factor, with one-time recovery codes; the login asks for it after the password.
 *  - Passkeys (WebAuthn, discoverable, user verification required) as a password-less login of their own.
 */
final class Mfa
{
    private const MAX_FAILS = 5;
    private const FAIL_WINDOW = 900;
    private const TICKET_TTL = 300;
    private const RECOVERY_CODES = 10;

    // ---------- status ----------

    /** @return array{totp:bool,passkeys:int,recovery:int} */
    public static function status(int $userId): array
    {
        $u = Db::one('SELECT totp_secret FROM users WHERE id = ?', [$userId]);
        return [
            'totp'     => !empty($u['totp_secret']),
            'passkeys' => (int) Db::one('SELECT COUNT(*) AS c FROM passkeys WHERE user_id = ?', [$userId])['c'],
            'recovery' => (int) Db::one('SELECT COUNT(*) AS c FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId])['c'],
        ];
    }

    /** True when the password login needs a second step (TOTP). Passkeys replace the password and are not a second step. */
    public static function enabled(int $userId): bool
    {
        return self::status($userId)['totp'];
    }

    /** Removes every second factor (CLI emergency reset). */
    public static function reset(?int $userId = null): void
    {
        $uid = $userId ?? (int) (Db::one('SELECT id FROM users ORDER BY id LIMIT 1')['id'] ?? 0);
        Db::run('UPDATE users SET totp_secret = NULL, totp_pending = NULL, totp_last_step = 0 WHERE id = ?', [$uid]);
        Db::run('DELETE FROM passkeys WHERE user_id = ?', [$uid]);
        Db::run('DELETE FROM recovery_codes WHERE user_id = ?', [$uid]);
    }

    // ---------- sealing (TOTP secrets are not stored in the clear) ----------

    private static function seal(string $plain): string
    {
        $key = Auth::derivedKey('mfa-seal');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    private static function unseal(string $sealed): string
    {
        $raw = base64_decode($sealed, true) ?: '';
        $plain = strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ? sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), Auth::derivedKey('mfa-seal'))
            : false;
        if ($plain === false) {
            throw new RuntimeException('Gespeicherter Schlüssel nicht lesbar.');
        }
        return $plain;
    }

    // ---------- throttling ----------

    /** Seconds the user must wait, 0 if attempts are allowed. */
    public static function lockedFor(int $userId): int
    {
        Db::run('DELETE FROM mfa_attempts WHERE ts < ?', [time() - self::FAIL_WINDOW]);
        $row = Db::one('SELECT COUNT(*) AS c, MIN(ts) AS t FROM mfa_attempts WHERE user_id = ?', [$userId]);
        return (int) $row['c'] >= self::MAX_FAILS ? max(30, (int) $row['t'] + self::FAIL_WINDOW - time()) : 0;
    }

    public static function fail(int $userId): void
    {
        Db::run('INSERT INTO mfa_attempts (user_id, ts) VALUES (?, ?)', [$userId, time()]);
    }

    private static function clearFails(int $userId): void
    {
        Db::run('DELETE FROM mfa_attempts WHERE user_id = ?', [$userId]);
    }

    /** Re-authentication for changes to the second factor; wrong passwords count as failed attempts. */
    public static function checkPassword(int $userId, string $password): void
    {
        if (self::lockedFor($userId) > 0) {
            throw new InvalidArgumentException('Zu viele Fehlversuche. Bitte später erneut versuchen.');
        }
        $user = Db::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::fail($userId);
            throw new InvalidArgumentException('Das Passwort ist falsch.');
        }
    }

    // ---------- login ticket (proof that the password step succeeded) ----------

    public static function ticket(int $userId): string
    {
        $payload = WebAuthn::b64u(json_encode(['u' => $userId, 'e' => time() + self::TICKET_TTL, 'n' => bin2hex(random_bytes(8))]));
        return $payload . '.' . hash_hmac('sha256', 'mfa|' . $payload, Auth::derivedKey('ticket'));
    }

    public static function ticketUser(string $ticket): ?int
    {
        $parts = explode('.', $ticket, 2);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'mfa|' . $parts[0], Auth::derivedKey('ticket')), $parts[1])) {
            return null;
        }
        try {
            $data = json_decode(WebAuthn::unb64u($parts[0]), true);
        } catch (RuntimeException) {
            return null;
        }
        return is_array($data) && ($data['e'] ?? 0) >= time() ? (int) $data['u'] : null;
    }

    /** What the second login step offers. @return array{totp:bool,recovery:bool} */
    public static function methods(int $userId): array
    {
        $s = self::status($userId);
        return ['totp' => $s['totp'], 'recovery' => $s['recovery'] > 0];
    }

    // ---------- TOTP ----------

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function totpCode(string $secretBin, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), $secretBin, true);
        $o = ord($hash[19]) & 15;
        $value = unpack('N', substr($hash, $o, 4))[1] & 0x7fffffff;
        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Matches the code against the current and the neighbouring 30 s windows; a window can only be used once. */
    private static function matchTotp(string $secretBin, string $code, int $lastStep): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return null;
        }
        $now = intdiv(time(), 30);
        $hit = null;
        foreach ([-1, 0, 1] as $off) {
            if (hash_equals(self::totpCode($secretBin, $now + $off), $code) && $now + $off > $lastStep) {
                $hit = $now + $off;
            }
        }
        return $hit;
    }

    /** @return array{secret:string,uri:string} */
    public static function totpBegin(int $userId): array
    {
        $user = Db::one('SELECT username FROM users WHERE id = ?', [$userId]);
        $secret = random_bytes(20);
        Db::run('UPDATE users SET totp_pending = ? WHERE id = ?', [self::seal($secret), $userId]);
        $issuer = (string) Settings::get('app_name');
        return [
            'secret' => self::base32Encode($secret),
            'uri'    => 'otpauth://totp/' . rawurlencode($issuer . ':' . $user['username']) . '?secret=' . self::base32Encode($secret)
                . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30',
        ];
    }

    /** Activates the pending secret if the code is right. @return bool */
    public static function totpConfirm(int $userId, string $code): bool
    {
        if (self::lockedFor($userId) > 0) {
            throw new InvalidArgumentException('Zu viele Fehlversuche. Bitte später erneut versuchen.');
        }
        $user = Db::one('SELECT totp_pending FROM users WHERE id = ?', [$userId]);
        if (empty($user['totp_pending'])) {
            throw new InvalidArgumentException('Die Einrichtung wurde nicht gestartet.');
        }
        $step = self::matchTotp(self::unseal($user['totp_pending']), $code, 0);
        if ($step === null) {
            self::fail($userId);
            return false;
        }
        Db::run('UPDATE users SET totp_secret = totp_pending, totp_pending = NULL, totp_last_step = ? WHERE id = ?', [$step, $userId]);
        self::clearFails($userId);
        return true;
    }

    public static function totpDisable(int $userId): void
    {
        Db::run('UPDATE users SET totp_secret = NULL, totp_pending = NULL, totp_last_step = 0 WHERE id = ?', [$userId]);
        self::dropRecoveryIfNoFactor($userId);
    }

    // ---------- recovery codes ----------

    /** Replaces all codes; the plain codes are returned once and never stored. @return list<string> */
    public static function recoveryGenerate(int $userId): array
    {
        Db::run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtolower(self::base32Encode(random_bytes(7))); // 56 bits, 12 chars -> use 10
            $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $codes[] = $code;
            Db::run('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)', [$userId, hash('sha256', self::normalizeCode($code))]);
        }
        return $codes;
    }

    private static function normalizeCode(string $code): string
    {
        return strtolower((string) preg_replace('/[^a-z2-7]/i', '', $code));
    }

    private static function recoveryUse(int $userId, string $code): bool
    {
        $norm = self::normalizeCode($code);
        if (strlen($norm) !== 10) {
            return false;
        }
        $hash = hash('sha256', $norm);
        foreach (Db::all('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]) as $row) {
            if (hash_equals($row['code_hash'], $hash)) {
                Db::run('UPDATE recovery_codes SET used_at = ? WHERE id = ?', [time(), $row['id']]);
                return true;
            }
        }
        return false;
    }

    private static function dropRecoveryIfNoFactor(int $userId): void
    {
        if (!self::enabled($userId)) {
            Db::run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
        }
    }

    // ---------- second login step: code ----------

    /** TOTP code or recovery code. @return bool true = verified */
    public static function verifyCode(int $userId, string $input): bool
    {
        $user = Db::one('SELECT totp_secret, totp_last_step FROM users WHERE id = ?', [$userId]);
        $input = trim($input);
        $ok = false;
        // Six digits = authenticator code; anything else (recovery codes have 10 characters) is tried as recovery code.
        if (preg_match('/^\d{6}$/', (string) preg_replace('/\s+/', '', $input)) && !empty($user['totp_secret'])) {
            $step = self::matchTotp(self::unseal($user['totp_secret']), $input, (int) $user['totp_last_step']);
            if ($step !== null) {
                Db::run('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $userId]);
                $ok = true;
            }
        } elseif ($input !== '') {
            $ok = self::recoveryUse($userId, $input);
        }
        $ok ? self::clearFails($userId) : self::fail($userId);
        return $ok;
    }

    // ---------- passkeys ----------

    private static function newChallenge(string $kind, int $userId): string
    {
        Db::run('DELETE FROM challenges WHERE expires_at < ?', [time()]);
        if ($kind === 'pk-login' && (int) Db::one("SELECT COUNT(*) AS c FROM challenges WHERE kind = 'pk-login'")['c'] >= 200) {
            throw new RuntimeException('Zu viele offene Anmeldungen. Bitte kurz warten.');
        }
        $challenge = WebAuthn::b64u(random_bytes(32));
        Db::run('INSERT INTO challenges (hash, kind, user_id, expires_at) VALUES (?, ?, ?, ?)', [hash('sha256', $challenge), $kind, $userId, time() + self::TICKET_TTL]);
        return $challenge;
    }

    /** Single use. */
    private static function takeChallenge(string $kind, int $userId, string $challenge): bool
    {
        return Db::run('DELETE FROM challenges WHERE hash = ? AND kind = ? AND user_id = ? AND expires_at >= ?', [hash('sha256', $challenge), $kind, $userId, time()]) === 1;
    }

    /** @return list<array{id:int,name:string,created_at:int,last_used:?int}> */
    public static function passkeys(int $userId): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'created_at' => (int) $r['created_at'], 'last_used' => $r['last_used'] !== null ? (int) $r['last_used'] : null],
            Db::all('SELECT id, name, created_at, last_used FROM passkeys WHERE user_id = ? ORDER BY id', [$userId]));
    }

    public static function passkeyDelete(int $userId, int $id): void
    {
        Db::run('DELETE FROM passkeys WHERE id = ? AND user_id = ?', [$id, $userId]);
        self::dropRecoveryIfNoFactor($userId);
    }

    /** Options for navigator.credentials.create(). */
    public static function passkeyCreateOptions(int $userId): array
    {
        $user = Db::one('SELECT username FROM users WHERE id = ?', [$userId]);
        return [
            'rp'                     => ['name' => (string) Settings::get('app_name'), 'id' => WebAuthn::rpId()],
            'user'                   => ['id' => WebAuthn::b64u(pack('J', $userId)), 'name' => $user['username'], 'displayName' => $user['username']],
            'challenge'              => self::newChallenge('pk-create', $userId),
            'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => WebAuthn::ALG_ES256]],
            'timeout'                => 60000,
            'attestation'            => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials'     => array_map(static fn ($r) => ['type' => 'public-key', 'id' => $r['cred_id']],
                Db::all('SELECT cred_id FROM passkeys WHERE user_id = ?', [$userId])),
        ];
    }

    public static function passkeyRegister(int $userId, array $cred, string $name): void
    {
        $r = (array) ($cred['response'] ?? []);
        try {
            $cdj = WebAuthn::unb64u((string) ($r['clientDataJSON'] ?? ''));
            $att = WebAuthn::cbor(WebAuthn::unb64u((string) ($r['attestationObject'] ?? '')));
            $challenge = WebAuthn::checkClientData($cdj, 'webauthn.create');
            if (!self::takeChallenge('pk-create', $userId, $challenge)) {
                throw new RuntimeException('Die Anfrage ist abgelaufen. Bitte erneut versuchen.');
            }
            $auth = WebAuthn::parseAuthData((string) ($att['authData'] ?? ''));
            WebAuthn::checkRpAndPresence($auth);
            if (!($auth['flags'] & 0x04)) {
                throw new RuntimeException('Der Passkey muss mit Fingerabdruck, Gesicht oder PIN geschützt sein.');
            }
            if ($auth['credId'] === null || $auth['publicKey'] === null) {
                throw new RuntimeException('Keine Zugangsdaten übermittelt.');
            }
            $pem = WebAuthn::coseToPem($auth['publicKey']);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
        $name = mb_substr(trim($name), 0, 60) ?: 'Passkey';
        try {
            Db::run('INSERT INTO passkeys (user_id, cred_id, public_key, sign_count, name, created_at) VALUES (?,?,?,?,?,?)',
                [$userId, WebAuthn::b64u($auth['credId']), $pem, $auth['signCount'], $name, time()]);
        } catch (PDOException) {
            throw new InvalidArgumentException('Dieser Passkey ist bereits registriert.');
        }
    }

    /** Options for navigator.credentials.get() on the login page: no user yet, the device offers its passkeys for this site. */
    public static function passkeyLoginOptions(): array
    {
        return [
            'challenge'        => self::newChallenge('pk-login', 0),
            'rpId'             => WebAuthn::rpId(),
            'timeout'          => 60000,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /** Verifies a discoverable-credential assertion. @return int|null id of the user the passkey belongs to */
    public static function passkeyLogin(array $cred): ?int
    {
        try {
            $r = (array) ($cred['response'] ?? []);
            $cdj = WebAuthn::unb64u((string) ($r['clientDataJSON'] ?? ''));
            $authData = WebAuthn::unb64u((string) ($r['authenticatorData'] ?? ''));
            $sig = WebAuthn::unb64u((string) ($r['signature'] ?? ''));
            $challenge = WebAuthn::checkClientData($cdj, 'webauthn.get');
            $fresh = self::takeChallenge('pk-login', 0, $challenge);
            $row = Db::one('SELECT * FROM passkeys WHERE cred_id = ?', [(string) ($cred['id'] ?? '')]);
            if (!$fresh || $row === null) {
                return null;
            }
            $handle = (string) ($r['userHandle'] ?? '');
            if ($handle !== '' && !hash_equals(WebAuthn::b64u(pack('J', (int) $row['user_id'])), $handle)) {
                return null;
            }
            $auth = WebAuthn::parseAuthData($authData);
            WebAuthn::checkRpAndPresence($auth);
            if (!($auth['flags'] & 0x04)) {
                return null; // user verification is what replaces the password
            }
            $count = $auth['signCount'];
            // A counter that does not move forward hints at a cloned authenticator (0/0 means the device has no counter).
            $counterOk = ($count === 0 && (int) $row['sign_count'] === 0) || $count > (int) $row['sign_count'];
            if ($counterOk && WebAuthn::verifySignature($authData, $cdj, $sig, $row['public_key'])) {
                Db::run('UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?', [$count, time(), $row['id']]);
                return (int) $row['user_id'];
            }
        } catch (RuntimeException) {
        }
        return null;
    }
}
