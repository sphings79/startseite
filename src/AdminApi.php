<?php
declare(strict_types=1);

/** /api/admin/*: user management, registration policy, mail settings, restoring a user's backup. Only reached by admins. */
final class AdminApi
{
    public static function dispatch(string $method, string $path, array $session): never
    {
        $actor = (int) $session['user_id'];
        $m = [];
        try {
            switch (true) {
                case $method === 'GET' && $path === '/api/admin/overview':
                    Http::json(['config' => AppConfig::forAdmin(), 'users' => Accounts::listUsers(), 'mail' => Mail::configured(), 'default_url' => WebAuthn::origin() . Http::basePath()]);
                case $method === 'PUT' && $path === '/api/admin/config':
                    AppConfig::update(Http::body());
                    Http::json(['ok' => true, 'config' => AppConfig::forAdmin(), 'mail' => Mail::configured()]);
                case $method === 'POST' && $path === '/api/admin/smtp-test':
                    $to = (string) ($session['email'] ?: $session['username']);
                    [$subject, $body] = MailTexts::build('test', (string) $session['lang'], ['app' => 'Startseite']);
                    try {
                        Mail::send($to, $subject, $body);
                    } catch (RuntimeException $e) {
                        throw new InvalidArgumentException($e->getMessage());
                    }
                    Http::json(['ok' => true, 'to' => $to]);

                case $method === 'POST' && $path === '/api/admin/users':
                    $b = Http::body();
                    try {
                        Accounts::invite((string) ($b['email'] ?? ''), (string) ($b['lang'] ?? 'de'), (string) ($b['role'] ?? 'user'));
                    } catch (RuntimeException $e) {
                        throw new InvalidArgumentException('Die Mail konnte nicht gesendet werden: ' . $e->getMessage());
                    }
                    Http::json(['users' => Accounts::listUsers()]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/reset-mail$#', $path, $m):
                    try {
                        Accounts::adminResetMail((int) $m[1]);
                    } catch (RuntimeException $e) {
                        throw new InvalidArgumentException('Die Mail konnte nicht gesendet werden: ' . $e->getMessage());
                    }
                    Http::json(['ok' => true]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/reset-2fa$#', $path, $m):
                    Mfa::reset((int) $m[1]);
                    Http::json(['users' => Accounts::listUsers()]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/role$#', $path, $m):
                    Accounts::setRole($actor, (int) $m[1], (string) (Http::body()['role'] ?? 'user'));
                    Http::json(['users' => Accounts::listUsers()]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/disable$#', $path, $m):
                    Accounts::setDisabled($actor, (int) $m[1], !empty(Http::body()['disabled']));
                    Http::json(['users' => Accounts::listUsers()]);
                case $method === 'DELETE' && preg_match('#^/api/admin/users/(\d+)$#', $path, $m):
                    Accounts::delete($actor, (int) $m[1]);
                    Http::json(['users' => Accounts::listUsers()]);

                case $method === 'GET' && preg_match('#^/api/admin/users/(\d+)/backups$#', $path, $m):
                    self::user((int) $m[1]);
                    Http::json(['backups' => Backup::list((int) $m[1])]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/backups$#', $path, $m):
                    self::user((int) $m[1]);
                    Ctx::as((int) $m[1], static fn () => Backup::create());
                    Http::json(['backups' => Backup::list((int) $m[1])]);
                case $method === 'POST' && preg_match('#^/api/admin/users/(\d+)/backups/([A-Za-z0-9.-]+)/restore$#', $path, $m):
                    self::user((int) $m[1]);
                    $file = Backup::path($m[2], (int) $m[1]) ?? throw new HttpError(404, 'Not found');
                    try {
                        // The backup replaces this one user's data; nobody else is touched.
                        $result = Ctx::as((int) $m[1], static function () use ($file) {
                            $r = Importer::fromFile($file, true);
                            LinkCheck::reapplyExclusions();
                            return $r;
                        });
                    } catch (RuntimeException $e) {
                        throw new InvalidArgumentException($e->getMessage());
                    }
                    Http::json(['ok' => true] + $result);
            }
        } catch (InvalidArgumentException $e) {
            Http::json(['error' => $e->getMessage()], 422);
        }
        throw new HttpError(404, 'Not found');
    }

    private static function user(int $id): void
    {
        if (Db::one('SELECT 1 FROM users WHERE id = ?', [$id]) === null) {
            throw new HttpError(404, 'Not found');
        }
    }
}
