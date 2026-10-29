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

/** Starts tests/smtp_server.php on a free port; returns [process, port, outfile]. */
function startSmtp(string $mode, string $dir, string $cert = ''): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $out = $dir . '/mail-' . $mode . '.jsonl';
    $proc = proc_open([PHP_BINARY, __DIR__ . '/smtp_server.php', (string) $port, $out, $mode, $cert], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', $port)) {
            break;
        }
        usleep(100_000);
    }
    return [$proc, $port, $out];
}

/** Self-signed certificate for 127.0.0.1 as a PEM file with key. */
function selfSignedCert(string $dir): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
    $x = openssl_csr_sign($csr, null, $key, 2);
    openssl_x509_export($x, $crt);
    openssl_pkey_export($key, $priv);
    file_put_contents($dir . '/cert.pem', $crt . $priv);
    return $dir . '/cert.pem';
}

/** @return list<array<string,mixed>> messages the fake server received, with the body decoded */
function readMails(string $file): array
{
    $out = [];
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
        $m = json_decode($line, true);
        [$head, $body] = explode("\r\n\r\n", $m['data'], 2) + [1 => ''];
        $m['headers'] = $head;
        $m['body'] = base64_decode(str_replace("\r\n", '', $body));
        $m['subject_raw'] = preg_match('/^Subject: (.*)$/mi', $head, $s) ? $s[1] : '';
        $out[] = $m;
    }
    return $out;
}
