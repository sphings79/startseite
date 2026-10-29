<?php
declare(strict_types=1);

/**
 * A tiny SMTP server for the tests:  php tests/smtp_server.php <port> <outfile> <mode> [<cert.pem>]
 * mode: plain | auth (AUTH PLAIN/LOGIN, user "mailuser", password "mailpass") | starttls (with auth) | ssl (implicit TLS, with auth)
 * Every accepted message is appended to <outfile> as one JSON line.
 */
[, $port, $out, $mode] = $argv + [null, null, null, 'plain'];
$cert = $argv[4] ?? '';
$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true]]);
$server = stream_socket_server(($mode === 'ssl' ? 'ssl' : 'tcp') . "://127.0.0.1:$port", $errno, $err, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
    fwrite(STDERR, "listen failed: $err\n");
    exit(1);
}
while (true) {
    $c = @stream_socket_accept($server, 30);
    if (!$c) {
        continue;
    }
    $state = ['tls' => $mode === 'ssl', 'auth' => false, 'from' => '', 'to' => []];
    $send = static fn (string $l) => fwrite($c, $l . "\r\n");
    $send('220 fake.test ESMTP');
    while (($line = fgets($c, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        $cmd = strtoupper(strtok($line, ' '));
        switch ($cmd) {
            case 'EHLO':
                $send('250-fake.test');
                if (in_array($mode, ['auth', 'starttls', 'ssl'], true) && ($mode !== 'starttls' || $state['tls'])) {
                    $send('250-AUTH PLAIN LOGIN');
                }
                if ($mode === 'starttls' && !$state['tls']) {
                    $send('250-STARTTLS');
                }
                $send('250 8BITMIME');
                break;
            case 'STARTTLS':
                $send('220 ready');
                stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
                $state['tls'] = true;
                break;
            case 'AUTH':
                $parts = explode(' ', $line);
                if (strtoupper($parts[1] ?? '') === 'PLAIN') {
                    $cred = explode("\0", (string) base64_decode($parts[2] ?? ''));
                    $ok = ($cred[1] ?? '') === 'mailuser' && ($cred[2] ?? '') === 'mailpass';
                } else {
                    $send('334 ' . base64_encode('Username:'));
                    $u = base64_decode(rtrim((string) fgets($c, 512)));
                    $send('334 ' . base64_encode('Password:'));
                    $p = base64_decode(rtrim((string) fgets($c, 512)));
                    $ok = $u === 'mailuser' && $p === 'mailpass';
                }
                $state['auth'] = $ok;
                $send($ok ? '235 ok' : '535 authentication failed');
                break;
            case 'MAIL':
                if (in_array($mode, ['auth', 'starttls', 'ssl'], true) && !$state['auth']) {
                    $send('530 authentication required');
                    break;
                }
                $state['from'] = $line;
                $send('250 ok');
                break;
            case 'RCPT':
                $state['to'][] = $line;
                $send('250 ok');
                break;
            case 'DATA':
                $send('354 go');
                $data = '';
                while (($l = fgets($c, 8192)) !== false) {
                    if ($l === ".\r\n") {
                        break;
                    }
                    $data .= $l;
                }
                file_put_contents($out, json_encode($state + ['data' => $data]) . "\n", FILE_APPEND | LOCK_EX);
                $send('250 queued');
                break;
            case 'QUIT':
                $send('221 bye');
                break 2;
            default:
                $send('250 ok');
        }
    }
    fclose($c);
}
