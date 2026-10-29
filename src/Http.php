<?php
declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly array $headers = [])
    {
        parent::__construct($message);
    }
}

/**
 * Request/response helpers: base path handling, proxy awareness, security headers.
 */
final class Http
{
    public static function basePath(): string
    {
        $configured = Config::get('base_path');
        if ($configured !== null && $configured !== '') {
            return rtrim('/' . trim((string) $configured, '/'), '/');
        }
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $dir === '.' ? '' : $dir;
    }

    public static function url(string $path = '/'): string
    {
        return self::basePath() . $path;
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = self::basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return $path === '/index.php' ? '/' : $path;
    }

    private static function fromTrustedProxy(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', (array) Config::get('trusted_proxies'), true);
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        return self::fromTrustedProxy()
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public static function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $trusted = (array) Config::get('trusted_proxies');
            $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            foreach (array_reverse($chain) as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP) && !in_array($candidate, $trusted, true)) {
                    return $candidate;
                }
            }
        }
        return $ip;
    }

    public static function host(): string
    {
        if (self::fromTrustedProxy() && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0]));
        }
        return strtolower($_SERVER['HTTP_HOST'] ?? '');
    }

    /** Reject cross-origin state-changing requests (defence in depth next to SameSite and CSRF tokens). */
    public static function assertSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '' || $origin === 'null') {
            $origin = $_SERVER['HTTP_REFERER'] ?? '';
        }
        if ($origin === '') {
            return;
        }
        $parts = parse_url($origin);
        $originHost = strtolower(($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if ($originHost !== self::host()) {
            throw new HttpError(403, 'Cross-origin request rejected');
        }
    }

    public static function securityHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; "
            . "connect-src 'self'; manifest-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    /** Post-login target: only the quick-add page is allowed, so the parameter can never become an open redirect. */
    public static function safeNext(mixed $value): string
    {
        $v = (string) $value;
        return strlen($v) <= 2048 && preg_match('#^/add(\?[^\x00-\x20\\\\]*)?$#', $v) ? $v : '/';
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . self::url($path), true, 303);
        exit;
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input', false, null, 0, 1048577);
        if ($raw === false || $raw === '') {
            return [];
        }
        if (strlen($raw) > 1048576) {
            throw new HttpError(413, 'Request too large');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpError(400, 'Invalid JSON');
        }
        return $data;
    }
}
