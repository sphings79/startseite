<?php
declare(strict_types=1);

/**
 * Logo fallbacks from public icon sources. Only an icon name from the public index (dashboard-icons)
 * or a public domain name (favicon service) leaves the server; excluded and local hosts never do.
 */
final class IconSets
{
    private const INDEX_URL = 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/tree.json';
    private const ICON_URL = 'https://cdn.jsdelivr.net/gh/homarr-labs/dashboard-icons/png/%s.png';
    private const INDEX_MAX_AGE = 30 * 86400;
    private const GENERIC_LABELS = ['www', 'app', 'apps', 'my', 'login', 'home', 'portal', 'admin', 'web', 'mail', 'api', 'cloud', 'test', 'dev',
        'intranet', 'server', 'online', 'secure', 'shop', 'start', 'main', 'new', 'old', 'beta', 'wiki', 'status'];

    /** @var array<string,string>|null  normalised key (letters/digits only) => icon name */
    private static ?array $index = null;

    // ---------- dashboard-icons ----------

    /** @return array{0:string,1:string} */
    public static function dashboardIcon(string $url, string $title): array
    {
        $index = self::index();
        foreach (self::candidates($url, $title) as $key) {
            $name = $index[$key] ?? null;
            if ($name === null) {
                continue;
            }
            $res = Net::fetch(sprintf(self::ICON_URL, rawurlencode($name)), 3_000_000, 12);
            if ($res['status'] === 200 && $res['body'] !== '') {
                return Thumbs::ingest($res['body'], 'logo');
            }
        }
        throw new RuntimeException('Kein Icon in dashboard-icons');
    }

    /** @return list<string> normalised lookup keys, best guess first */
    private static function candidates(string $url, string $title): array
    {
        $keys = [];
        $host = LinkCheck::hostOf($url);
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP)) {
            $labels = explode('.', $host);
            array_pop($labels); // top-level domain
            foreach ($labels as $label) {
                if (!in_array($label, self::GENERIC_LABELS, true)) {
                    $keys[] = self::norm($label);
                }
            }
        }
        $keys[] = self::norm($title);
        foreach (preg_split('/[\s\-_|:]+/', $title) ?: [] as $word) {
            if (!in_array(strtolower($word), self::GENERIC_LABELS, true)) {
                $keys[] = self::norm($word);
            }
        }
        return array_values(array_unique(array_filter($keys, static fn ($k) => strlen($k) >= 3)));
    }

    private static function norm(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower($s));
    }

    /** @return array<string,string> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }
        $cache = Config::get('data_dir') . '/dashboard-icons.json';
        $stale = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
        $names = is_array($stale) ? $stale : null;
        if ($names === null || time() - (int) filemtime($cache) > self::INDEX_MAX_AGE) {
            try {
                $res = Net::fetch(self::INDEX_URL, 3_000_000, 20);
                $tree = $res['status'] === 200 ? json_decode($res['body'], true) : null;
                if (is_array($tree['png'] ?? null)) {
                    $names = array_map(static fn ($f) => substr((string) $f, 0, -4), $tree['png']);
                    file_put_contents($cache, json_encode($names));
                }
            } catch (Throwable) {
                // keep a stale copy if there is one
            }
        }
        if (!$names) {
            throw new RuntimeException('dashboard-icons Index nicht verfügbar');
        }
        $index = [];
        foreach ($names as $name) {
            $key = self::norm($name);
            // plain names win over -dark/-light variants; shorter name wins on ties
            if (!isset($index[$key]) || strlen($name) < strlen($index[$key])) {
                $index[$key] = $name;
            }
        }
        return self::$index = $index;
    }

    // ---------- favicon service ----------

    /** Small (usually 32 px) but better than a letter tile. Last resort; public domains only. @return array{0:string,1:string} */
    public static function favicon(string $url): array
    {
        $host = LinkCheck::hostOf($url);
        if ($host === '' || LinkCheck::isExcludedUrl($url)) {
            throw new RuntimeException('Favicon-Dienst übersprungen (lokal oder ausgeschlossen)');
        }
        foreach (Net::resolve($host) as $ip) {
            if (!Net::isPublicIp($ip)) {
                throw new RuntimeException('Favicon-Dienst übersprungen (private Adresse)');
            }
        }
        $res = Net::fetch('https://t2.gstatic.com/faviconV2?client=SOCIAL&type=FAVICON&fallback_opts=TYPE,SIZE,URL&size=128&url='
            . rawurlencode('https://' . $host), 500_000, 10);
        $info = $res['status'] === 200 ? @getimagesizefromstring($res['body']) : false;
        if (!$info || min($info[0], $info[1]) < 32) {
            throw new RuntimeException('Kein Favicon verfügbar');
        }
        return Thumbs::ingest($res['body'], 'logo');
    }
}
