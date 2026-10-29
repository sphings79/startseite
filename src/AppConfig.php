<?php
declare(strict_types=1);

/** Settings of the whole installation (not of one user): who may register and how mail is sent. Stored in the `settings` table. */
final class AppConfig
{
    private const DEFAULTS = [
        'reg_mode'      => 'off',      // off | any | domains
        'reg_allowed'   => '',         // one domain or address per line
        'app_url'       => '',         // base URL for links in mails; empty = taken from the request
        'smtp_host'     => '',
        'smtp_port'     => 25,
        'smtp_security' => 'none',     // none | starttls | ssl
        'smtp_user'     => '',
        'smtp_pass'     => '',         // sealed
        'smtp_from'     => '',
        'smtp_from_name' => 'Startseite',
        'smtp_verify'   => true,       // check the server certificate
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $out = self::DEFAULTS;
        foreach (Db::all('SELECT key, value FROM settings') as $row) {
            if (array_key_exists($row['key'], self::DEFAULTS)) {
                $out[$row['key']] = json_decode($row['value'], true);
            }
        }
        return $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /** For the admin form: everything except the SMTP password. @return array<string,mixed> */
    public static function forAdmin(): array
    {
        $c = self::all();
        $c['smtp_pass_set'] = $c['smtp_pass'] !== '';
        unset($c['smtp_pass']);
        return $c;
    }

    private static function put(string $key, mixed $value): void
    {
        Db::run('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value', [$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    /** @param array<string,mixed> $in absent keys stay as they are; smtp_pass '' keeps the stored one, smtp_pass_clear removes it */
    public static function update(array $in): void
    {
        $text = static fn (string $k, int $max): string => mb_substr(trim((string) $in[$k]), 0, $max);
        if (array_key_exists('reg_mode', $in)) {
            if (!in_array($in['reg_mode'], ['off', 'any', 'domains'], true)) {
                throw new InvalidArgumentException('Ungültiger Registrierungsmodus.');
            }
            self::put('reg_mode', $in['reg_mode']);
        }
        if (array_key_exists('reg_allowed', $in)) {
            $list = self::parseAllowed((string) $in['reg_allowed']);
            self::put('reg_allowed', implode("\n", $list));
        }
        if (array_key_exists('app_url', $in)) {
            $u = $text('app_url', 300);
            if ($u !== '' && !preg_match('#^https?://[^\s/]+[^\s]*$#i', $u)) {
                throw new InvalidArgumentException('Die Basis-URL muss mit http:// oder https:// beginnen.');
            }
            self::put('app_url', rtrim($u, '/'));
        }
        if (array_key_exists('smtp_host', $in)) {
            $h = $text('smtp_host', 253);
            if ($h !== '' && !preg_match('/^[A-Za-z0-9._-]+$|^\[[0-9a-fA-F:.]+\]$/', $h)) {
                throw new InvalidArgumentException('Ungültiger SMTP-Host.');
            }
            self::put('smtp_host', $h);
        }
        if (array_key_exists('smtp_port', $in)) {
            if (!is_numeric($in['smtp_port']) || (int) $in['smtp_port'] < 1 || (int) $in['smtp_port'] > 65535) {
                throw new InvalidArgumentException('Ungültiger SMTP-Port.');
            }
            self::put('smtp_port', (int) $in['smtp_port']);
        }
        if (array_key_exists('smtp_security', $in)) {
            if (!in_array($in['smtp_security'], ['none', 'starttls', 'ssl'], true)) {
                throw new InvalidArgumentException('Ungültige Verschlüsselung.');
            }
            self::put('smtp_security', $in['smtp_security']);
        }
        if (array_key_exists('smtp_user', $in)) {
            self::put('smtp_user', $text('smtp_user', 200));
        }
        if (!empty($in['smtp_pass_clear'])) {
            self::put('smtp_pass', '');
        } elseif (isset($in['smtp_pass']) && (string) $in['smtp_pass'] !== '') {
            if (strlen((string) $in['smtp_pass']) > 500) {
                throw new InvalidArgumentException('Das SMTP-Passwort ist zu lang.');
            }
            self::put('smtp_pass', Mfa::seal((string) $in['smtp_pass']));
        }
        if (array_key_exists('smtp_from', $in)) {
            $f = $text('smtp_from', 254);
            if ($f !== '' && !filter_var($f, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ungültige Absenderadresse.');
            }
            self::put('smtp_from', $f);
        }
        if (array_key_exists('smtp_from_name', $in)) {
            self::put('smtp_from_name', preg_replace('/[\x00-\x1f\x7f<>"]/', '', $text('smtp_from_name', 80)));
        }
        if (array_key_exists('smtp_verify', $in)) {
            self::put('smtp_verify', (bool) $in['smtp_verify']);
        }
    }

    /** @return list<string> lower-case domains ("example.org") and addresses ("a@example.org"), duplicates removed */
    public static function parseAllowed(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $text) ?: [] as $line) {
            $line = strtolower(trim(preg_replace('/#.*/', '', $line)));
            $line = ltrim($line, '@');
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([a-z0-9._+-]+@)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $line)) {
                throw new InvalidArgumentException('Ungültiger Eintrag in der Freigabeliste: ' . mb_substr($line, 0, 60));
            }
            $out[$line] = true;
        }
        return array_keys($out);
    }

    /** May this address create an account itself? */
    public static function registrationAllows(string $email): bool
    {
        $mode = self::get('reg_mode');
        if ($mode === 'any') {
            return true;
        }
        if ($mode !== 'domains') {
            return false;
        }
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        foreach (self::parseAllowed((string) self::get('reg_allowed')) as $entry) {
            if ($entry === $email || (!str_contains($entry, '@') && $entry === $domain)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{host:string,port:int,security:string,user:string,pass:string,from:string,from_name:string,verify:bool} */
    public static function smtp(): array
    {
        $c = self::all();
        return [
            'host' => (string) $c['smtp_host'], 'port' => (int) $c['smtp_port'], 'security' => (string) $c['smtp_security'],
            'user' => (string) $c['smtp_user'], 'pass' => $c['smtp_pass'] !== '' ? Mfa::unseal((string) $c['smtp_pass']) : '',
            'from' => (string) $c['smtp_from'], 'from_name' => (string) $c['smtp_from_name'], 'verify' => (bool) $c['smtp_verify'],
        ];
    }
}
