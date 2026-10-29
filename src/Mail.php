<?php
declare(strict_types=1);

/**
 * Minimal SMTP client without dependencies: plain, STARTTLS or implicit TLS, optional AUTH PLAIN/LOGIN.
 * Works against an internal relay without login and encryption as well as against external servers.
 */
final class Mail
{
    private const TIMEOUT = 15;

    public static function configured(): bool
    {
        $c = AppConfig::smtp();
        return $c['host'] !== '' && $c['from'] !== '';
    }

    /** @throws RuntimeException with a readable reason (never contains the password) */
    public static function send(string $to, string $subject, string $body): void
    {
        $c = AppConfig::smtp();
        if ($c['host'] === '' || $c['from'] === '') {
            throw new RuntimeException('Der Mailversand ist nicht eingerichtet.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($c['from'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ungültige Mailadresse.');
        }
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $c['verify'], 'verify_peer_name' => $c['verify'], 'allow_self_signed' => !$c['verify'],
            'peer_name' => trim($c['host'], '[]'), 'SNI_enabled' => true,
        ]]);
        $remote = ($c['security'] === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
        $fp = @stream_socket_client($remote, $errno, $err, self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Keine Verbindung zum Mailserver ' . $c['host'] . ':' . $c['port'] . ' (' . trim((string) $err) . ').');
        }
        stream_set_timeout($fp, self::TIMEOUT);
        try {
            self::expect($fp, [220]);
            $caps = self::ehlo($fp);
            if ($c['security'] === 'starttls') {
                if (!str_contains($caps, 'STARTTLS')) {
                    throw new RuntimeException('Der Mailserver bietet kein STARTTLS an.');
                }
                self::cmd($fp, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Die TLS-Verbindung zum Mailserver ist fehlgeschlagen (Zertifikat prüfen oder Prüfung abschalten).');
                }
                $caps = self::ehlo($fp);
            }
            if ($c['user'] !== '') {
                if (preg_match('/AUTH[ =].*\bPLAIN\b/i', $caps)) {
                    self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235], true);
                } elseif (preg_match('/AUTH[ =].*\bLOGIN\b/i', $caps)) {
                    self::cmd($fp, 'AUTH LOGIN', [334]);
                    self::cmd($fp, base64_encode($c['user']), [334], true);
                    self::cmd($fp, base64_encode($c['pass']), [235], true);
                } else {
                    throw new RuntimeException('Der Mailserver bietet keine Anmeldung (AUTH PLAIN/LOGIN) an. Benutzername leer lassen oder Verschlüsselung aktivieren.');
                }
            }
            self::cmd($fp, 'MAIL FROM:<' . $c['from'] . '>', [250]);
            self::cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            fwrite($fp, self::message($c, $to, $subject, $body) . "\r\n.\r\n");
            self::expect($fp, [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }

    /** @param array<string,mixed> $c */
    private static function message(array $c, string $to, string $subject, string $body): string
    {
        $clean = static fn (string $s): string => trim(preg_replace('/[\r\n]+/', ' ', $s));
        $enc = static fn (string $s): string => preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
        $host = preg_replace('/[^A-Za-z0-9.-]/', '', substr((string) strrchr($c['from'], '@'), 1)) ?: 'localhost';
        $from = $c['from_name'] !== '' ? $enc($clean($c['from_name'])) . ' <' . $c['from'] . '>' : $c['from'];
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . $enc($clean($subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode(str_replace("\r\n", "\n", $body)), 76, "\r\n");
    }

    private static function ehlo($fp): string
    {
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost';
        return self::cmd($fp, 'EHLO ' . $name, [250]);
    }

    /** @param list<int> $ok @param bool $secret the command carries credentials and is not echoed into errors */
    private static function cmd($fp, string $line, array $ok, bool $secret = false): string
    {
        fwrite($fp, $line . "\r\n");
        return self::expect($fp, $ok, $secret ? 'Anmeldung am Mailserver fehlgeschlagen' : '');
    }

    /** @param list<int> $ok @return string the whole reply */
    private static function expect($fp, array $ok, string $label = ''): string
    {
        $reply = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $reply .= $line;
            if (preg_match('/^\d{3} /', $line) || strlen($line) < 4) {
                break;
            }
        }
        if ($reply === '') {
            throw new RuntimeException('Keine Antwort vom Mailserver (Zeitüberschreitung).');
        }
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $ok, true)) {
            $text = trim(preg_replace('/\s+/', ' ', preg_replace('/^\d{3}[ -]/m', '', $reply)));
            throw new RuntimeException(($label !== '' ? $label . ': ' : 'Mailserver: ') . mb_substr($text, 0, 200));
        }
        return $reply;
    }
}
