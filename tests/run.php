<?php
declare(strict_types=1);

/**
 * Runs all tests without any dependency:  php tests/run.php
 * unit.php runs in-process on a throw-away data directory, integration.php talks HTTP to a PHP built-in server.
 */
require __DIR__ . '/lib.php';

$php = PHP_BINARY;
$failed = 0;
foreach (['unit.php', 'integration.php'] as $file) {
    $dir = T::tmpDir();
    echo "=== $file ===\n";
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/' . $file);
    $proc = proc_open($cmd, [1 => STDOUT, 2 => STDERR], $pipes, null, ['STARTSEITE_DATA_DIR' => $dir, 'PATH' => getenv('PATH') ?: ''] + $_ENV);
    $code = proc_close($proc);
    T::rmTree($dir);
    $failed += $code === 0 ? 0 : 1;
}
echo $failed === 0 ? "All test files passed.\n" : "$failed test file(s) failed.\n";
exit($failed === 0 ? 0 : 1);
