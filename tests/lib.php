<?php
declare(strict_types=1);

/** Tiny assertion helpers; no dependencies. Counts results and prints one line per check. */
final class T
{
    public static int $ok = 0;
    public static int $fail = 0;

    public static function check(string $name, bool $cond, string $detail = ''): void
    {
        if ($cond) {
            self::$ok++;
            echo "  ok    $name\n";
        } else {
            self::$fail++;
            echo "  FAIL  $name" . ($detail !== '' ? " ($detail)" : '') . "\n";
        }
    }

    public static function throws(string $name, callable $fn, string $class = Throwable::class): void
    {
        try {
            $fn();
            self::check($name, false, 'nothing thrown');
        } catch (Throwable $e) {
            self::check($name, $e instanceof $class, get_class($e) . ': ' . $e->getMessage());
        }
    }

    public static function section(string $title): void
    {
        echo "\n$title\n";
    }

    public static function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/startseite-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }

    public static function rmTree(string $dir): void
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
