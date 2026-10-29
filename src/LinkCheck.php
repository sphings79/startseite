<?php
declare(strict_types=1);

/**
 * Link health checks. check_status: ok | cert | suspect | dead | skipped | excluded.
 *
 * Automatic runs report "dead" only after FAILS_FOR_DEAD failures in a row (retries are spaced out by the worker);
 * until then a failing link is "suspect". A manual run reports a failure as "dead" at once.
 */
final class LinkCheck
{
    public const FAILS_FOR_DEAD = 3;
    public const RETRY_AFTER = 72_000; // seconds before a suspect link is probed again (>= 20 h, so failures fall on different days)

    private const LOCAL_SUFFIXES = ['.fritz.box', '.local', '.lan', '.home.arpa', '.internal', '.localdomain', '.intranet', '.private', '.corp', '.home'];

    // ---------- exclusion rules ----------

    /** One rule per line: host name, wildcard (*.example.de), IP or CIDR range (10.0.0.0/8). Lines starting with # are comments. */
    public static function normalizeRules(string $text): string
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '#') {
                $out[$line] = mb_substr($line, 0, 200);
                continue;
            }
            $line = strtolower($line);
            if (!preg_match('#^[0-9a-f:.]+/\d{1,3}$#', $line)) {
                $line = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $line);
                $line = (string) preg_replace('#[/?\#].*$#', '', $line);
                if (substr_count($line, ':') === 1) {
                    $line = (string) preg_replace('#:\d+$#', '', $line);
                }
                $line = rtrim($line, '.');
            }
            if (str_contains($line, '/')) {
                [$ip, $bits] = explode('/', $line, 2);
                $max = str_contains($ip, ':') ? 128 : 32;
                if (!filter_var($ip, FILTER_VALIDATE_IP) || (int) $bits > $max) {
                    throw new InvalidArgumentException('Ungültige Regel: ' . mb_substr($line, 0, 60));
                }
            } elseif ($line === '' || strlen($line) > 253 || !preg_match('/^[a-z0-9*._:-]+$/', $line)) {
                throw new InvalidArgumentException('Ungültige Regel: ' . mb_substr($line, 0, 60));
            }
            $out[$line] = $line;
        }
        if (count($out) > 500) {
            throw new InvalidArgumentException('Zu viele Regeln (max. 500).');
        }
        return implode("\n", $out);
    }

    /** @return list<string> */
    private static function ruleList(string $text): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($l) => $l !== '' && $l[0] !== '#'));
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$net, $bits] = explode('/', $cidr, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($net);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    public static function ruleMatches(string $host, string $rule): bool
    {
        if (str_contains($rule, '/')) {
            return filter_var($host, FILTER_VALIDATE_IP) !== false && self::inCidr($host, $rule);
        }
        return preg_match('#^' . str_replace('\*', '.*', preg_quote($rule, '#')) . '$#i', $host) === 1;
    }

    public static function hostOf(string $url): string
    {
        return rtrim(strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]')), '.');
    }

    /** True for private/loopback IPs and for names that only make sense inside a network. */
    public static function isLocalHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !Net::isPublicIp($host);
        }
        if ($host === 'localhost' || $host === 'fritz.box' || !str_contains($host, '.')) {
            return true;
        }
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** Why this link must not be probed (or contacted by third-party icon services), or null. */
    public static function exclusionReason(array $dial, bool $groupExcluded, array $settings): ?string
    {
        if (!empty($dial['no_check'])) {
            return 'Lesezeichen von der Prüfung ausgenommen';
        }
        if ($groupExcluded) {
            return 'Gruppe von der Prüfung ausgenommen';
        }
        $host = self::hostOf((string) $dial['url']);
        foreach (self::ruleList((string) ($settings['check_exclude'] ?? '')) as $rule) {
            if (self::ruleMatches($host, $rule)) {
                return 'Ausschlussregel: ' . $rule;
            }
        }
        if (!empty($settings['check_skip_private']) && self::isLocalHost($host)) {
            return 'Lokale Adresse';
        }
        return null;
    }

    /** Same test for code that only has a URL (icon lookups): never send excluded hosts to outside services. */
    public static function isExcludedUrl(string $url): bool
    {
        $host = self::hostOf($url);
        foreach (self::ruleList((string) Settings::get('check_exclude')) as $rule) {
            if (self::ruleMatches($host, $rule)) {
                return true;
            }
        }
        return self::isLocalHost($host);
    }

    /** Re-evaluates exclusions without network access (after rules, groups or URLs changed). */
    public static function reapplyExclusions(?int $onlyDial = null): void
    {
        $settings = Settings::all();
        $sql = 'SELECT d.*, g.no_check AS group_no_check FROM dials d JOIN groups g ON g.id = d.group_id'
            . ($onlyDial !== null ? ' WHERE d.id = ' . (int) $onlyDial : '');
        foreach (Db::all($sql) as $d) {
            $reason = self::exclusionReason($d, (bool) $d['group_no_check'], $settings);
            if ($reason !== null) {
                if ($d['check_status'] !== 'excluded' || $d['check_reason'] !== $reason) {
                    Db::run("UPDATE dials SET check_status = 'excluded', check_reason = ?, check_code = NULL, check_at = ?, check_fails = 0, check_req = 0 WHERE id = ?",
                        [$reason, time(), $d['id']]);
                }
            } elseif (in_array($d['check_status'], ['excluded', 'skipped'], true)) {
                Db::run('UPDATE dials SET check_status = NULL, check_reason = NULL, check_code = NULL, check_at = NULL, check_fails = 0 WHERE id = ?', [$d['id']]);
            }
        }
    }

    // ---------- scheduling ----------

    /** @return array{0:array<string,mixed>,1:bool}|null  [dial row, manual] */
    public static function next(): ?array
    {
        $d = Db::one('SELECT * FROM dials WHERE check_req = 1 ORDER BY id LIMIT 1');
        if ($d !== null) {
            return [$d, true];
        }
        $days = (int) Settings::get('check_interval_days');
        if ($days <= 0) {
            return null;
        }
        $now = time();
        $d = Db::one("SELECT * FROM dials WHERE check_req = 0
                      AND (check_at IS NULL OR (check_status = 'suspect' AND check_at < ?) OR check_at < ?)
                      ORDER BY check_at IS NOT NULL, check_at LIMIT 1",
            [$now - self::RETRY_AFTER, $now - $days * 86400]);
        return $d === null ? null : [$d, false];
    }

    // ---------- the check itself ----------

    public static function run(array $dial, bool $manual): void
    {
        $settings = Settings::all();
        $group = Db::one('SELECT no_check FROM groups WHERE id = ?', [$dial['group_id']]);
        $reason = self::exclusionReason($dial, (bool) ($group['no_check'] ?? 0), $settings);

        $fails = (int) $dial['check_fails'];
        if ($reason !== null) {
            [$status, $note, $code, $fails] = ['excluded', $reason, null, 0];
        } else {
            [$result, $note, $code] = self::probeUrl((string) $dial['url'], $settings);
            if ($result === 'fail') {
                $fails++;
                $status = ($manual || $fails >= self::FAILS_FOR_DEAD) ? 'dead' : 'suspect';
            } else {
                $status = $result;
                $fails = 0;
            }
        }
        Db::run('UPDATE dials SET check_status = ?, check_reason = ?, check_code = ?, check_at = ?, check_fails = ?, check_req = 0 WHERE id = ?',
            [$status, mb_substr($note, 0, 200), $code, time(), $fails, $dial['id']]);
    }

    /** @return array{0:string,1:string,2:?int}  result ok|cert|fail|skipped, note, HTTP code */
    private static function probeUrl(string $url, array $settings): array
    {
        $host = self::hostOf($url);
        $ips = Net::resolve($host);
        if (!$ips) {
            return ['fail', 'DNS: Host nicht auflösbar', null];
        }
        foreach ($ips as $ip) {
            if (!Net::isPublicIp($ip) && (!empty($settings['check_skip_private']) || Config::get('block_private_targets'))) {
                return ['skipped', 'Löst auf private Adresse auf (' . $ip . ')', null];
            }
        }
        try {
            $r = self::attempt($url, 'HEAD');
            if ($r[0] === 'fail' && $r[3]) {
                $r = self::attempt($url, 'GET'); // some servers reject HEAD
            }
        } catch (RuntimeException $e) {
            return match (true) {
                str_contains($e->getMessage(), 'not public')       => ['skipped', 'Weiterleitung auf private Adresse', null],
                str_contains($e->getMessage(), 'not resolvable')   => ['fail', 'Weiterleitung auf nicht auflösbaren Host', null],
                default                                            => ['fail', 'Ungültige Weiterleitung', null],
            };
        }
        return [$r[0], $r[1], $r[2]];
    }

    /** @return array{0:string,1:string,2:?int,3:bool}  result, note, HTTP code, worth retrying with GET */
    private static function attempt(string $url, string $method): array
    {
        $r = Net::probe($url, $method, true);
        if ($r['status'] === 0 && in_array($r['errno'], [51, 60], true)) {
            $insecure = Net::probe($url, $method, false);
            if ($insecure['status'] > 0) {
                return ['cert', 'Zertifikat: ' . mb_substr($r['error'], 0, 140), $insecure['status'], false];
            }
        }
        $s = $r['status'];
        if ($s === 0) {
            return match ($r['errno']) {
                6       => ['fail', 'DNS: Host nicht auflösbar', null, false],
                7       => ['fail', 'Verbindung verweigert', null, false],
                28      => ['fail', 'Zeitüberschreitung', null, true],
                default => ['fail', 'Fehler: ' . mb_substr($r['error'], 0, 140), null, true],
            };
        }
        if ($s === 404 || $s === 410 || $s >= 500) {
            return ['fail', 'HTTP ' . $s, $s, true];
        }
        return ['ok', 'HTTP ' . $s, $s, false]; // any other answer means a server is there
    }
}
