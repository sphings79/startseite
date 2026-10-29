<?php
declare(strict_types=1);

/**
 * Imports a Speed Dial 2 export (JSON) or a Startseite backup (ZIP / data.json).
 *
 *   php bin/import.php FILE [--merge] [--user=<email>]   default: replace all groups and dials of the first admin (or the named user)
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$file = $argv[1] ?? '';
$who = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--user=')) {
        $who = Accounts::byEmail(substr($a, 7)) ?? null;
        if ($who === null) {
            fwrite(STDERR, "No such user.\n");
            exit(1);
        }
    }
}
$who ??= Db::one("SELECT * FROM users WHERE role = 'admin' ORDER BY id LIMIT 1") ?? Db::one('SELECT * FROM users ORDER BY id LIMIT 1');
if ($who === null) {
    fwrite(STDERR, "There is no user yet (php bin/admin.php create <email>).\n");
    exit(1);
}
Ctx::$uid = (int) $who['id'];
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/import.php FILE [--merge]\n");
    exit(1);
}
try {
    $r = Importer::fromFile($file, !in_array('--merge', $argv, true));
    echo "Imported {$r['dials']} bookmarks into {$r['groups']} groups. Start the worker to fetch thumbnails.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
