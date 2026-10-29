<?php
declare(strict_types=1);

/**
 * Imports a Speed Dial 2 export (JSON) or a Startseite backup (ZIP / data.json).
 *
 *   php bin/import.php FILE [--merge]     default: replace all groups and dials
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

$file = $argv[1] ?? '';
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
