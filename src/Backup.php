<?php
declare(strict_types=1);

/**
 * Scheduled backups: the same ZIP as the manual export, written to DATA_DIR/backups/u<id>/ (one folder per user)
 * and pruned to the count that user configured.
 */
final class Backup
{
    private const NAME = '/^startseite-\d{8}-\d{6}\.zip$/';

    public static function dir(?int $uid = null): string
    {
        $dir = Config::get('data_dir') . '/backups/u' . ($uid ?? Ctx::uid());
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create backup directory');
        }
        return $dir;
    }

    /** @return list<array{name:string,size:int,time:int}> newest first */
    public static function list(?int $uid = null): array
    {
        $out = [];
        foreach (glob(self::dir($uid) . '/startseite-*.zip') ?: [] as $f) {
            if (preg_match(self::NAME, basename($f))) {
                $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
            }
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function path(string $name, ?int $uid = null): ?string
    {
        return preg_match(self::NAME, $name) && is_file(self::dir($uid) . '/' . $name) ? self::dir($uid) . '/' . $name : null;
    }

    /** Backs up the user in context. */
    public static function create(): string
    {
        $name = 'startseite-' . date('Ymd-His') . '.zip';
        $path = self::dir() . '/' . $name;
        try {
            Exporter::buildZip($path . '.part');
            rename($path . '.part', $path);
        } finally {
            @unlink($path . '.part');
        }
        @chmod($path, 0640);
        self::prune();
        return $name;
    }

    public static function prune(): void
    {
        $keep = (int) Settings::get('backup_keep');
        foreach (array_slice(self::list(), max(1, $keep)) as $old) {
            @unlink(self::dir() . '/' . $old['name']);
        }
    }

    public static function due(): bool
    {
        if (!Settings::get('backup_enabled')) {
            return false;
        }
        $last = self::list()[0]['time'] ?? 0;
        return time() - $last >= (int) Settings::get('backup_interval_hours') * 3600;
    }

    /** Removes every backup of a user (account deletion). */
    public static function deleteAll(int $uid): void
    {
        $dir = Config::get('data_dir') . '/backups/u' . $uid;
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
