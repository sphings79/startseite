<?php
declare(strict_types=1);

/**
 * Single-user authentication (OWASP ASVS / Authentication, Session Management, Password Storage cheat sheets):
 *  - Argon2id password hashes, transparent rehash
 *  - server-side sessions: random selector + validator, only SHA-256(validator) is stored
 *  - persistent cookie (HttpOnly, SameSite=Lax, Secure over HTTPS), sliding expiry
 *  - per-session CSRF token, signed pre-login token for the login form
 *  - brute-force throttling per IP and globally, uniform error messages, constant-time dummy verify
 */
final class Auth
{
    public const COOKIE = 'startseite_session';
    public const MIN_PASSWORD_LENGTH = 12;

    private const MAX_FAILS_PER_IP = 5;
    private const MAX_FAILS_GLOBAL = 40;
    private const FAIL_WINDOW = 900;

    private static ?array $session = null;
    private static bool $sessionLoaded = false;

    // ---------- secrets / tokens ----------

    private static function secret(): string
    {
        $file = Config::get('data_dir') . '/secret.key';
        if (!is_file($file)) {
            Db::pdo();
            $key = bin2hex(random_bytes(32));
            if (file_put_contents($file, $key, LOCK_EX) === false) {
                throw new RuntimeException('Cannot write secret key');
            }
            chmod($file, 0600);
        }
        return trim((string) file_get_contents($file));
    }

    /** Purpose-bound 32-byte key derived from the server secret (ticket signing, sealing of TOTP secrets). */
    public static function derivedKey(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, self::secret(), true);
    }

    public static function loginToken(): string
    {
        $ts = (string) time();
        return $ts . '.' . hash_hmac('sha256', 'login|' . $ts, self::secret());
    }

    public static function checkLoginToken(string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            return false;
        }
        $age = time() - (int) $parts[0];
        if ($age < 0 || $age > 7200) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', 'login|' . $parts[0], self::secret()), $parts[1]);
    }

    // ---------- users ----------

    public static function userCount(): int
    {
        return (int) Db::one('SELECT COUNT(*) AS c FROM users')['c'];
    }

    public static function validatePassword(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Das Passwort braucht mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen.';
        }
        if (mb_strlen($password) > 256) {
            return 'Das Passwort ist zu lang.';
        }
        return null;
    }

    private static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /** Creates the single user or updates it. Invalidates all sessions. */
    public static function setUser(string $username, string $password): void
    {
        if (($err = self::validatePassword($password)) !== null) {
            throw new InvalidArgumentException($err);
        }
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > 64) {
            throw new InvalidArgumentException('Ungültiger Benutzername.');
        }
        $hash = self::hash($password);
        $existing = Db::one('SELECT id FROM users ORDER BY id LIMIT 1');
        if ($existing) {
            Db::run('UPDATE users SET username = ?, password_hash = ? WHERE id = ?', [$username, $hash, $existing['id']]);
        } else {
            Db::run('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)', [$username, $hash, time()]);
        }
        Db::run('DELETE FROM sessions');
    }

    // ---------- login ----------

    /** Seconds the client has to wait after too many failed logins (password or passkey), 0 if allowed. */
    public static function throttled(string $ip): int
    {
        Db::run('DELETE FROM login_attempts WHERE ts < ?', [time() - self::FAIL_WINDOW]);
        $perIp = (int) Db::one('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ?', [$ip])['c'];
        $global = (int) Db::one('SELECT COUNT(*) AS c FROM login_attempts')['c'];
        if ($perIp < self::MAX_FAILS_PER_IP && $global < self::MAX_FAILS_GLOBAL) {
            return 0;
        }
        $oldest = (int) (Db::one('SELECT MIN(ts) AS t FROM login_attempts' . ($perIp >= self::MAX_FAILS_PER_IP ? ' WHERE ip = ?' : ''),
            $perIp >= self::MAX_FAILS_PER_IP ? [$ip] : [])['t'] ?? time());
        return max(30, $oldest + self::FAIL_WINDOW - time());
    }

    public static function recordFailure(string $ip): void
    {
        Db::run('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)', [$ip, time()]);
    }

    public static function clearFailures(string $ip): void
    {
        Db::run('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    }

    /** @return array{ok:bool,retry_after:int,mfa?:string} "mfa" = ticket for the second step when the user has a second factor */
    public static function attemptLogin(string $username, string $password, string $ip): array
    {
        if (($wait = self::throttled($ip)) > 0) {
            return ['ok' => false, 'retry_after' => $wait];
        }

        $user = Db::one('SELECT * FROM users WHERE username = ?', [trim($username)]);
        // Always run one verification so timing does not reveal whether the user exists.
        static $dummy = null;
        $dummy ??= self::hash(bin2hex(random_bytes(8)));
        $valid = password_verify($password, $user['password_hash'] ?? $dummy) && $user !== null;

        if (!$valid) {
            self::recordFailure($ip);
            return ['ok' => false, 'retry_after' => 0];
        }

        self::clearFailures($ip);
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($password), $user['id']]);
        }
        if (Mfa::enabled((int) $user['id'])) {
            return ['ok' => false, 'retry_after' => 0, 'mfa' => Mfa::ticket((int) $user['id'])];
        }
        self::startSession((int) $user['id']);
        return ['ok' => true, 'retry_after' => 0];
    }

    /** Creates the session after all required factors succeeded. */
    public static function startSession(int $userId): void
    {
        // Fresh session identifier on every login (session fixation).
        self::destroyCurrent();
        self::create($userId);
    }

    // ---------- sessions ----------

    private static function cookieOptions(int $expires): array
    {
        return [
            'expires'  => $expires,
            'path'     => Http::basePath() . '/',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function create(int $userId): void
    {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $now = time();
        $expires = $now + (int) Config::get('session_days') * 86400;
        Db::run(
            'INSERT INTO sessions (user_id, selector, validator_hash, csrf, created_at, last_seen, expires_at, ip, ua)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $selector, hash('sha256', $validator), bin2hex(random_bytes(32)), $now, $now, $expires,
                Http::clientIp(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200)]
        );
        setcookie(self::COOKIE, $selector . '.' . $validator, self::cookieOptions($expires));
        self::$sessionLoaded = false;
    }

    /** @return array<string,mixed>|null session row incl. csrf and username */
    public static function current(): ?array
    {
        if (self::$sessionLoaded) {
            return self::$session;
        }
        self::$sessionLoaded = true;
        self::$session = null;

        $cookie = $_COOKIE[self::COOKIE] ?? '';
        $parts = explode('.', (string) $cookie, 2);
        if (count($parts) !== 2 || !ctype_xdigit($parts[0]) || !ctype_xdigit($parts[1])) {
            return null;
        }
        $row = Db::one(
            'SELECT s.*, u.username FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.selector = ?',
            [$parts[0]]
        );
        if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $parts[1])) || $row['expires_at'] < time()) {
            if ($row) {
                Db::run('DELETE FROM sessions WHERE id = ?', [$row['id']]);
            }
            return null;
        }

        // Sliding expiry, at most once a day.
        if (time() - (int) $row['last_seen'] > 86400) {
            $expires = time() + (int) Config::get('session_days') * 86400;
            Db::run('UPDATE sessions SET last_seen = ?, expires_at = ? WHERE id = ?', [time(), $expires, $row['id']]);
            setcookie(self::COOKIE, $cookie, self::cookieOptions($expires));
            Db::run('DELETE FROM sessions WHERE expires_at < ?', [time()]);
        }
        return self::$session = $row;
    }

    private static function destroyCurrent(): void
    {
        $cookie = $_COOKIE[self::COOKIE] ?? '';
        $parts = explode('.', (string) $cookie, 2);
        if (ctype_xdigit($parts[0] ?? '') && $parts[0] !== '') {
            Db::run('DELETE FROM sessions WHERE selector = ?', [$parts[0]]);
        }
        self::$session = null;
        self::$sessionLoaded = false;
    }

    public static function logout(): void
    {
        self::destroyCurrent();
        setcookie(self::COOKIE, '', self::cookieOptions(1));
    }

    public static function logoutOthers(int $keepSessionId): void
    {
        Db::run('DELETE FROM sessions WHERE id != ?', [$keepSessionId]);
    }

    public static function changePassword(array $session, string $current, string $new): ?string
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            return 'Das aktuelle Passwort ist falsch.';
        }
        if (($err = self::validatePassword($new)) !== null) {
            return $err;
        }
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [self::hash($new), $user['id']]);
        self::logoutOthers((int) $session['id']);
        return null;
    }

    public static function changeUsername(array $session, string $current, string $username): ?string
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$session['user_id']]);
        if (!$user || !password_verify($current, $user['password_hash'])) {
            return 'Das aktuelle Passwort ist falsch.';
        }
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > 64 || preg_match('/[\x00-\x1f\x7f]/', $username)) {
            return 'Ungültiger Benutzername (1 bis 64 Zeichen, keine Steuerzeichen).';
        }
        Db::run('UPDATE users SET username = ? WHERE id = ?', [$username, $user['id']]);
        self::logoutOthers((int) $session['id']);
        return null;
    }
}
