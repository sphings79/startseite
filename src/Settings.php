<?php
declare(strict_types=1);

/**
 * Key/value settings with a whitelist schema; values are stored as JSON.
 */
final class Settings
{
    private const BG_DEFAULT = ['file' => '', 'color' => '', 'size' => 'cover', 'position' => 'center bottom', 'repeat' => 'no-repeat', 'opacity' => 100];

    /** key => [type, default, min/max or enum] */
    private const SCHEMA = [
        'app_name'           => ['string', 'Startseite', 1, 60],
        'theme'              => ['enum', 'auto', ['auto', 'light', 'dark']],
        'columns'            => ['int', 8, 2, 20],
        'spacing'            => ['int', 14, 0, 40],
        'max_width'          => ['int', 1400, 0, 3000],
        'center_vertical'    => ['bool', false],
        'compact'            => ['bool', false],
        'show_title'         => ['bool', true],
        'show_visits'        => ['bool', false],
        'border_radius'      => ['int', 12, 0, 32],
        'title_align'        => ['enum', 'center', ['left', 'center', 'right']],
        'ratio'              => ['enum', '16:10', ['1:1', '4:3', '16:10', '16:9']],
        'open_new_tab'       => ['bool', true],
        'order_by'           => ['enum', 'manual', ['manual', 'visits', 'title', 'recent', 'created']],
        'keep_active_group'  => ['bool', true],
        'show_add_button'    => ['bool', true],
        'show_web_search'    => ['bool', false],
        'mobile_list'        => ['bool', false],
        'auto_top'           => ['bool', false],
        'auto_recent'        => ['bool', false],
        'auto_count'         => ['int', 12, 3, 50],
        'cleanup_days'       => ['int', 365, 30, 3650],
        'backup_enabled'     => ['bool', true],
        'backup_interval_hours' => ['int', 24, 1, 720],
        'backup_keep'        => ['int', 7, 1, 100],
        'web_search_engine'  => ['enum', 'google', ['google', 'duckduckgo', 'bing', 'brave', 'startpage', 'ecosia', 'qwant', 'custom']],
        'web_search_custom'  => ['template', ''],
        'font_scale'         => ['int', 100, 80, 140],
        'accent'             => ['color', '#2563eb'],
        'tile_bg_light'      => ['color', '#ffffff'],
        'tile_text_light'    => ['color', '#1f2937'],
        'tile_bg_dark'       => ['color', '#1f2430'],
        'tile_text_dark'     => ['color', '#e5e7eb'],
        'check_interval_days' => ['int', 7, 0, 90],
        'check_skip_private' => ['bool', true],
        'check_exclude'      => ['rules', ''],
        'bg_light'           => ['bg', ['color' => '#eeede8'] + self::BG_DEFAULT],
        'bg_dark'            => ['bg', ['color' => '#0f1115'] + self::BG_DEFAULT],
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $out = [];
        foreach (self::SCHEMA as $k => $def) {
            $out[$k] = $def[1];
        }
        foreach (Db::all('SELECT key, value FROM settings') as $row) {
            if (isset(self::SCHEMA[$row['key']])) {
                $v = json_decode($row['value'], true);
                $out[$row['key']] = self::SCHEMA[$row['key']][0] === 'bg' && is_array($v) ? $v + $out[$row['key']] : $v;
            }
        }
        return $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** @throws InvalidArgumentException */
    public static function normalize(string $key, mixed $value): mixed
    {
        if (!isset(self::SCHEMA[$key])) {
            throw new InvalidArgumentException("Unknown setting: $key");
        }
        $def = self::SCHEMA[$key];
        switch ($def[0]) {
            case 'string':
                $v = trim((string) $value);
                if (mb_strlen($v) < $def[2] || mb_strlen($v) > $def[3]) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                return $v;
            case 'int':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                return max($def[2], min($def[3], (int) $value));
            case 'bool':
                return (bool) $value;
            case 'enum':
                if (!in_array($value, $def[2], true)) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                return $value;
            case 'color':
                if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                return strtolower($value);
            case 'template':
                $v = trim((string) $value);
                if ($v !== '' && (strlen($v) > 300 || !preg_match('#^https?://[^\s]+$#i', $v) || substr_count($v, '%s') !== 1)) {
                    throw new InvalidArgumentException('Eigene Suche: http(s)-URL mit genau einem %s als Platzhalter.');
                }
                return $v;
            case 'rules':
                return LinkCheck::normalizeRules((string) $value);
            case 'bg':
                if (!is_array($value)) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                $cur = self::all()[$key];
                $file = $cur['file'];
                $color = $value['color'] ?? $cur['color'];
                if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)) {
                    throw new InvalidArgumentException('Invalid color');
                }
                $size = $value['size'] ?? $cur['size'];
                $repeat = $value['repeat'] ?? $cur['repeat'];
                $pos = $value['position'] ?? $cur['position'];
                if (!in_array($size, ['cover', 'contain', 'auto'], true)
                    || !in_array($repeat, ['no-repeat', 'repeat'], true)
                    || !in_array($pos, ['center center', 'center top', 'center bottom', 'left center', 'right center'], true)) {
                    throw new InvalidArgumentException("Invalid $key");
                }
                return [
                    'file'     => $file,
                    'color'    => strtolower((string) $color),
                    'size'     => $size,
                    'position' => $pos,
                    'repeat'   => $repeat,
                    'opacity'  => max(0, min(100, (int) ($value['opacity'] ?? $cur['opacity']))),
                ];
        }
        throw new InvalidArgumentException("Invalid $key");
    }

    public static function set(string $key, mixed $value, bool $raw = false): void
    {
        $v = $raw ? $value : self::normalize($key, $value);
        Db::run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($v, JSON_UNESCAPED_UNICODE)]);
    }

    public static function setBackgroundFile(string $theme, string $file): void
    {
        $key = 'bg_' . $theme;
        $cur = self::all()[$key];
        self::deleteMedia($cur['file']);
        $cur['file'] = $file;
        self::set($key, $cur, true);
    }

    private static function deleteMedia(string $file): void
    {
        Thumbs::deleteFile($file);
    }
}
