<?php
declare(strict_types=1);

/** Accounts: creating them, e-mail confirmation, password reset, admin actions. Tokens are single use and stored only as hashes. */
final class Accounts
{
    private const VERIFY_TTL = 86400;
    private const RESET_TTL = 3600;

    // ---------- addresses and passwords ----------

    public static function normalizeEmail(string $email): string
    {
        $e = strtolower(trim($email));
        if ($e === '' || strlen($e) > 254 || !filter_var($e, FILTER_VALIDATE_EMAIL) || preg_match('/[\s<>"\x00-\x1f]/', $e)) {
            throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        return $e;
    }

    public static function lang(?string $lang): string
    {
        return $lang === 'en' ? 'en' : 'de';
    }

    /** German unless the browser clearly prefers something else. */
    public static function guessLang(): string
    {
        $h = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        return $h === '' || preg_match('/^\s*de\b/', $h) ? 'de' : 'en';
    }

    public static function byEmail(string $email): ?array
    {
        return Db::one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    }

    // ---------- tokens ----------

    /** @return string the secret to put into a link */
    private static function newToken(int $userId, string $kind, int $ttl, ?string $data = null): string
    {
        Db::run('DELETE FROM tokens WHERE expires_at < ?', [time()]);
        Db::run('DELETE FROM tokens WHERE user_id = ? AND kind = ?', [$userId, $kind]);
        $token = bin2hex(random_bytes(32));
        Db::run('INSERT INTO tokens (user_id, kind, hash, data, expires_at) VALUES (?, ?, ?, ?, ?)', [$userId, $kind, hash('sha256', $token), $data, time() + $ttl]);
        return $token;
    }

    /** Looks a token up without using it up. @return array<string,mixed>|null */
    public static function peekToken(string $token, string $kind): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return Db::one('SELECT * FROM tokens WHERE hash = ? AND kind = ? AND expires_at >= ?', [hash('sha256', $token), $kind, time()]);
    }

    private static function link(string $path, string $token): string
    {
        $base = (string) AppConfig::get('app_url');
        if ($base === '') {
            if (PHP_SAPI === 'cli' || !isset($_SERVER['HTTP_HOST'])) {
                throw new RuntimeException('Die Basis-URL ist nicht eingestellt (Admin-Einstellungen).');
            }
            $base = WebAuthn::origin() . Http::basePath();
        }
        return $base . $path . '?token=' . $token;
    }

    private static function mail(string $kind, string $to, string $lang, string $path, string $token, int $ttl, array $extra = []): void
    {
        [$subject, $body] = MailTexts::build($kind, $lang, ['app' => 'Startseite', 'link' => self::link($path, $token), 'hours' => (string) max(1, (int) round($ttl / 3600))] + $extra);
        Mail::send($to, $subject, $body);
    }

    // ---------- registration ----------

    /**
     * Creates a pending account and mails the confirmation link. The caller shows the same message whatever happened to the address.
     * @throws InvalidArgumentException shown to the user (policy, password); @throws RuntimeException mail problems
     */
    public static function register(string $email, string $password, string $lang, string $ip): void
    {
        $email = self::normalizeEmail($email);
        $lang = self::lang($lang);
        if (AppConfig::get('reg_mode') === 'off') {
            throw new InvalidArgumentException('Die Registrierung ist ausgeschaltet.');
        }
        if (!AppConfig::registrationAllows($email)) {
            throw new InvalidArgumentException('Mit dieser Adresse ist keine Registrierung möglich.');
        }
        if (($err = Auth::validatePassword($password)) !== null) {
            throw new InvalidArgumentException($err);
        }
        if (!Mail::configured()) {
            throw new InvalidArgumentException('Der Mailversand ist nicht eingerichtet. Bitte den Administrator kontaktieren.');
        }
        if (!Rate::hit('reg-ip', $ip, 5, 3600) || !Rate::hit('reg-mail', $email, 3, 3600)) {
            throw new InvalidArgumentException('Zu viele Versuche. Bitte später erneut versuchen.');
        }
        Db::run("DELETE FROM users WHERE status = 'pending' AND created_at < ?", [time() - self::VERIFY_TTL]);
        $user = self::byEmail($email);
        if ($user && $user['status'] !== 'pending') {
            // Someone registers an address that already has an account: tell the owner by mail, tell the visitor nothing new.
            self::mail('exists', $email, (string) $user['lang'], '/reset', self::newToken((int) $user['id'], 'reset', self::RESET_TTL), self::RESET_TTL);
            return;
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($user) {
            Db::run('UPDATE users SET password_hash = ?, lang = ?, created_at = ? WHERE id = ?', [$hash, $lang, time(), $user['id']]);
            $uid = (int) $user['id'];
        } else {
            Db::run("INSERT INTO users (username, email, password_hash, role, lang, status, created_at) VALUES (?, ?, ?, 'user', ?, 'pending', ?)", [$email, $email, $hash, $lang, time()]);
            $uid = Db::insertId();
        }
        try {
            self::mail('verify', $email, $lang, '/verify', self::newToken($uid, 'verify', self::VERIFY_TTL), self::VERIFY_TTL);
        } catch (RuntimeException $e) {
            Db::run("DELETE FROM users WHERE id = ? AND status = 'pending'", [$uid]);
            throw $e;
        }
    }

    /** Confirms the address behind the token and activates the account. */
    public static function verify(string $token): bool
    {
        $row = self::peekToken($token, 'verify');
        if (!$row) {
            return false;
        }
        Db::run("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'", [$row['user_id']]);
        Db::run('DELETE FROM tokens WHERE id = ?', [$row['id']]);
        return true;
    }

    // ---------- password reset ----------

    /** Sends a reset mail if the address belongs to an active account. Always looks the same from outside. */
    public static function requestReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (!Mail::configured()) {
            throw new InvalidArgumentException('Der Mailversand ist nicht eingerichtet. Bitte den Administrator kontaktieren.');
        }
        if (!Rate::hit('reset-ip', $ip, 8, 3600) || !Rate::hit('reset-mail', $email, 3, 3600)) {
            throw new InvalidArgumentException('Zu viele Versuche. Bitte später erneut versuchen.');
        }
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? self::byEmail($email) : null;
        if ($user && $user['status'] === 'active') {
            try {
                self::mail('reset', $email, (string) $user['lang'], '/reset', self::newToken((int) $user['id'], 'reset', self::RESET_TTL), self::RESET_TTL);
            } catch (RuntimeException $e) {
                error_log('[startseite] reset mail failed: ' . $e->getMessage());
            }
        }
    }

    /** @throws InvalidArgumentException */
    public static function reset(string $token, string $password): void
    {
        $row = self::peekToken($token, 'reset');
        if (!$row) {
            throw new InvalidArgumentException('Der Link ist ungültig oder abgelaufen.');
        }
        if (($err = Auth::validatePassword($password)) !== null) {
            throw new InvalidArgumentException($err);
        }
        $uid = (int) $row['user_id'];
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_ARGON2ID), $uid]);
        Db::run("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'", [$uid]);
        Db::run("DELETE FROM tokens WHERE user_id = ? AND kind IN ('reset', 'verify')", [$uid]);
        Db::run('DELETE FROM sessions WHERE user_id = ?', [$uid]);
        Db::run('DELETE FROM mfa_attempts WHERE user_id = ?', [$uid]);
    }

    // ---------- changing the address ----------

    public static function requestEmailChange(array $session, string $password, string $newEmail): void
    {
        $uid = (int) $session['user_id'];
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$uid]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new InvalidArgumentException('Das aktuelle Passwort ist falsch.');
        }
        $new = self::normalizeEmail($newEmail);
        if ($user['role'] !== 'admin' && !AppConfig::registrationAllows($new)) {
            throw new InvalidArgumentException('Diese Adresse ist nicht zugelassen.');
        }
        if (self::byEmail($new)) {
            throw new InvalidArgumentException('Diese Adresse wird schon verwendet.');
        }
        if (!Mail::configured()) {
            throw new InvalidArgumentException('Der Mailversand ist nicht eingerichtet.');
        }
        if (!Rate::hit('email-change', (string) $uid, 5, 3600)) {
            throw new InvalidArgumentException('Zu viele Versuche. Bitte später erneut versuchen.');
        }
        self::mail('email_change', $new, (string) $user['lang'], '/verify-email', self::newToken($uid, 'email', self::VERIFY_TTL, $new), self::VERIFY_TTL);
    }

    public static function confirmEmailChange(string $token): bool
    {
        $row = self::peekToken($token, 'email');
        if (!$row || !$row['data'] || self::byEmail((string) $row['data'])) {
            return false;
        }
        Db::run('UPDATE users SET email = ?, username = ? WHERE id = ?', [$row['data'], $row['data'], $row['user_id']]);
        Db::run('DELETE FROM tokens WHERE id = ?', [$row['id']]);
        Db::run('DELETE FROM sessions WHERE user_id = ?', [$row['user_id']]);
        return true;
    }

    // ---------- admin ----------

    public static function isAdmin(?array $session): bool
    {
        return $session !== null && ($session['role'] ?? '') === 'admin';
    }

    /** @return list<array<string,mixed>> */
    public static function listUsers(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, username, email, role, lang, status, created_at, last_login, (totp_secret IS NOT NULL) AS totp FROM users ORDER BY id') as $u) {
            $out[] = [
                'id' => (int) $u['id'], 'email' => $u['email'] ?: $u['username'], 'role' => $u['role'], 'lang' => $u['lang'], 'status' => $u['status'],
                'created_at' => (int) $u['created_at'], 'last_login' => $u['last_login'] !== null ? (int) $u['last_login'] : null,
                'dials' => (int) Db::one('SELECT COUNT(*) AS c FROM dials WHERE user_id = ?', [$u['id']])['c'],
                'totp' => (bool) $u['totp'], 'passkeys' => (int) Db::one('SELECT COUNT(*) AS c FROM passkeys WHERE user_id = ?', [$u['id']])['c'],
            ];
        }
        return $out;
    }

    private static function activeAdmins(): int
    {
        return (int) Db::one("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND status = 'active'")['c'];
    }

    /** Creates an account for someone and mails the link for choosing the password. */
    public static function invite(string $email, string $lang, string $role): int
    {
        $email = self::normalizeEmail($email);
        if (self::byEmail($email)) {
            throw new InvalidArgumentException('Diese Adresse hat schon ein Konto.');
        }
        if (!Mail::configured()) {
            throw new InvalidArgumentException('Der Mailversand ist nicht eingerichtet.');
        }
        $lang = self::lang($lang);
        Db::run("INSERT INTO users (username, email, password_hash, role, lang, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', ?)",
            [$email, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_ARGON2ID), $role === 'admin' ? 'admin' : 'user', $lang, time()]);
        $uid = Db::insertId();
        try {
            self::mail('invite', $email, $lang, '/reset', self::newToken($uid, 'reset', self::VERIFY_TTL), self::VERIFY_TTL);
        } catch (RuntimeException $e) {
            Db::run('DELETE FROM users WHERE id = ?', [$uid]);
            throw $e;
        }
        return $uid;
    }

    public static function adminResetMail(int $userId): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new InvalidArgumentException('Benutzer unbekannt.');
        if (!Mail::configured()) {
            throw new InvalidArgumentException('Der Mailversand ist nicht eingerichtet.');
        }
        self::mail('reset', (string) ($user['email'] ?: $user['username']), (string) $user['lang'], '/reset', self::newToken($userId, 'reset', self::RESET_TTL), self::RESET_TTL);
    }

    public static function setRole(int $actor, int $userId, string $role): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new InvalidArgumentException('Benutzer unbekannt.');
        $role = $role === 'admin' ? 'admin' : 'user';
        if ($role === 'user' && $user['role'] === 'admin' && ($userId === $actor || self::activeAdmins() <= 1)) {
            throw new InvalidArgumentException('Der letzte Admin kann nicht zum Benutzer gemacht werden.');
        }
        Db::run('UPDATE users SET role = ? WHERE id = ?', [$role, $userId]);
    }

    public static function setDisabled(int $actor, int $userId, bool $disabled): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new InvalidArgumentException('Benutzer unbekannt.');
        if ($disabled && ($userId === $actor || ($user['role'] === 'admin' && self::activeAdmins() <= 1))) {
            throw new InvalidArgumentException('Dieses Konto kann nicht gesperrt werden.');
        }
        Db::run('UPDATE users SET status = ? WHERE id = ?', [$disabled ? 'disabled' : 'active', $userId]);
        if ($disabled) {
            Db::run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
        }
    }

    /** Deletes the account with all its data (bookmarks, images, backups). */
    public static function delete(int $actor, int $userId): void
    {
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw new InvalidArgumentException('Benutzer unbekannt.');
        if ($userId === $actor || ($user['role'] === 'admin' && self::activeAdmins() <= 1)) {
            throw new InvalidArgumentException('Dieses Konto kann nicht gelöscht werden.');
        }
        foreach (Db::all('SELECT thumb_file, thumb_orig FROM dials WHERE user_id = ?', [$userId]) as $d) {
            Thumbs::dropFiles($d);
        }
        foreach (Db::all("SELECT value FROM user_settings WHERE user_id = ? AND key IN ('bg_light', 'bg_dark')", [$userId]) as $row) {
            Thumbs::deleteFile((json_decode($row['value'], true)['file'] ?? '') ?: null);
        }
        Backup::deleteAll($userId);
        Db::run('DELETE FROM users WHERE id = ?', [$userId]);
        Db::run('DELETE FROM dials WHERE user_id = ?', [$userId]);
        Db::run('DELETE FROM sections WHERE user_id = ?', [$userId]);
    }
}
