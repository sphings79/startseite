<?php
declare(strict_types=1);

/**
 * Page description for the search index. Dials with meta_desc IS NULL are queued; '' means "looked, nothing found".
 */
final class PageMeta
{
    /** @return array<string,mixed>|null */
    public static function next(): ?array
    {
        return Db::one("SELECT * FROM dials WHERE meta_desc IS NULL AND thumb_status NOT IN ('pending','running') AND check_req = 0 ORDER BY id LIMIT 1");
    }

    public static function refresh(array $dial): void
    {
        $desc = '';
        try {
            $res = Net::fetch((string) $dial['url'], 400_000, 10);
            if ($res['status'] < 400 && str_contains($res['type'], 'html')) {
                $desc = self::extract($res['body'], $res['type']);
            }
        } catch (Throwable) {
            // unreachable pages simply have no description
        }
        Db::run('UPDATE dials SET meta_desc = ? WHERE id = ?', [$desc, $dial['id']]);
    }

    private static function extract(string $html, string $type): string
    {
        if (preg_match('/charset=([\w-]+)/', $type, $m) && !preg_match('/^utf-?8$/i', $m[1])) {
            $html = @mb_convert_encoding($html, 'UTF-8', $m[1]) ?: $html;
        } elseif (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $found = [];
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            $key = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
            if (in_array($key, ['description', 'og:description', 'twitter:description'], true) && trim($meta->getAttribute('content')) !== '') {
                $found[$key] = trim($meta->getAttribute('content'));
            }
        }
        $desc = $found['description'] ?? $found['og:description'] ?? $found['twitter:description'] ?? '';
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $desc)), 0, 300);
    }
}
