<?php
declare(strict_types=1);

/**
 * Outbound HTTP with SSRF protection: every hop is resolved, checked and pinned to the resolved IP.
 */
final class Net
{
    public const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    /** @return list<string> */
    public static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            $ips[] = $rec['ip'] ?? $rec['ipv6'] ?? null;
        }
        if (!$ips) {
            $ips = @gethostbynamel($host) ?: [];
        }
        return array_values(array_filter($ips));
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** Validates scheme and (unless disabled) that the host only resolves to public addresses. */
    public static function checkTarget(string $url): void
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('Unsupported URL');
        }
        $ips = self::resolve(trim($p['host'], '[]'));
        if (!$ips) {
            throw new RuntimeException('Host not resolvable');
        }
        if (Config::get('block_private_targets')) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new RuntimeException('Target address is not public');
                }
            }
        }
    }

    /**
     * @return array{status:int,body:string,url:string,type:string}
     */
    public static function fetch(string $url, int $maxBytes = 4_000_000, int $timeout = 15): array
    {
        for ($hop = 0; $hop <= 5; $hop++) {
            self::checkTarget($url);
            $p = parse_url($url);
            $host = trim($p['host'], '[]');
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            $ip = self::resolve($host)[0];

            $body = '';
            $headers = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_USERAGENT      => self::UA,
                CURLOPT_HTTPHEADER     => ['Accept: text/html,image/*,*/*;q=0.8', 'Accept-Language: de,en;q=0.8'],
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_ENCODING       => '',
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$body, $maxBytes): int {
                    $body .= $chunk;
                    return strlen($body) > $maxBytes ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            if ($ok === false && strlen($body) <= $maxBytes) {
                throw new RuntimeException('Fetch failed: ' . $err);
            }
            if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
                $url = self::absolutize($headers['location'], $url);
                continue;
            }
            return ['status' => $status, 'body' => $body, 'url' => $url, 'type' => strtolower($headers['content-type'] ?? '')];
        }
        throw new RuntimeException('Too many redirects');
    }

    /**
     * Liveness probe: follows up to 5 redirects (each hop SSRF-checked and IP-pinned) without downloading a body.
     * Transport errors are returned, not thrown; only a refused target throws.
     *
     * @return array{status:int,errno:int,error:string,url:string}
     */
    public static function probe(string $url, string $method = 'HEAD', bool $verifyTls = true, int $timeout = 10): array
    {
        $status = 0;
        $errno = 0;
        $err = '';
        for ($hop = 0; $hop <= 5; $hop++) {
            self::checkTarget($url);
            $p = parse_url($url);
            $host = trim($p['host'], '[]');
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            $ip = self::resolve($host)[0];

            $location = '';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
                CURLOPT_USERAGENT      => self::UA,
                CURLOPT_HTTPHEADER     => ['Accept: text/html,*/*;q=0.8', 'Accept-Language: de,en;q=0.8'],
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => $verifyTls,
                CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
                CURLOPT_NOBODY         => $method === 'HEAD',
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                // GET: abort as soon as the first body bytes arrive; headers are all we need.
                CURLOPT_WRITEFUNCTION  => static fn ($c, string $chunk): int => 0,
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            if ($status > 0 && $errno === CURLE_WRITE_ERROR) {
                $errno = 0; // our own deliberate abort
                $err = '';
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = self::absolutize($location, $url);
                continue;
            }
            break;
        }
        return ['status' => $status, 'errno' => $errno, 'error' => $err, 'url' => $url];
    }

    public static function absolutize(string $href, string $base): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }
}
