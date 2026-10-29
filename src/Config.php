<?php
declare(strict_types=1);

/**
 * Runtime configuration: defaults, optional config/config.php, environment overrides.
 */
final class Config
{
    private static array $values = [];

    public static function load(): void
    {
        $values = [
            'data_dir'              => APP_ROOT . '/data',
            'base_path'             => null,   // null = detect from SCRIPT_NAME, '/' = site root
            'trusted_proxies'       => [],     // IPs whose X-Forwarded-* headers are trusted
            'chromium_bin'          => '',     // empty = auto-detect
            'chromium_flags'        => [],     // e.g. ['--no-sandbox'] inside containers
            'block_private_targets' => true,   // refuse server-side fetches to LAN/loopback addresses
            'session_days'          => 365,
            'worker_poll_seconds'   => 2,
        ];

        $file = getenv('STARTSEITE_CONFIG') ?: APP_ROOT . '/config/config.php';
        if (is_file($file)) {
            $values = array_replace($values, (array) require $file);
        }

        $env = static fn (string $k) => getenv('STARTSEITE_' . $k);
        if (($v = $env('DATA_DIR')) !== false && $v !== '') {
            $values['data_dir'] = rtrim($v, '/');
        }
        if (($v = $env('BASE_PATH')) !== false && $v !== '') {
            $values['base_path'] = $v;
        }
        if (($v = $env('TRUSTED_PROXIES')) !== false && $v !== '') {
            $values['trusted_proxies'] = array_values(array_filter(array_map('trim', explode(',', $v))));
        }
        if (($v = $env('CHROMIUM_BIN')) !== false && $v !== '') {
            $values['chromium_bin'] = $v;
        }
        if (($v = $env('CHROMIUM_FLAGS')) !== false && $v !== '') {
            $values['chromium_flags'] = array_values(array_filter(preg_split('/\s+/', trim($v))));
        }
        if (($v = $env('BLOCK_PRIVATE')) !== false && $v !== '') {
            $values['block_private_targets'] = !in_array(strtolower($v), ['0', 'false', 'no', 'off'], true);
        }
        if (($v = $env('SESSION_DAYS')) !== false && ctype_digit($v)) {
            $values['session_days'] = max(1, (int) $v);
        }

        self::$values = $values;
    }

    public static function get(string $key): mixed
    {
        return self::$values[$key] ?? null;
    }
}
