<?php
declare(strict_types=1);

/**
 * Thumbnail pipeline: image ingestion, logo discovery and headless-Chromium screenshots.
 * A dial with thumb_status = 'pending' is a queued job; bin/worker.php processes them.
 */
final class Thumbs
{
    private const MAX_PIXELS = 36_000_000;

    // ---------- image handling ----------

    /**
     * Decode, downscale and store an image. Returns [filename, kind] where kind is 'logo' (contain) or 'shot' (cover).
     * @return array{0:string,1:string}
     */
    public static function ingest(string $bytes, string $forceKind = ''): array
    {
        if (self::isSvg($bytes)) {
            $bytes = self::rasterizeSvg($bytes);
            $forceKind = $forceKind !== '' ? $forceKind : 'logo'; // vector artwork is a logo, whatever its proportions
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new RuntimeException('Not a usable image');
        }
        $im = @imagecreatefromstring($bytes);
        if (!$im) {
            throw new RuntimeException('Image format not supported');
        }
        imagepalettetotruecolor($im);
        [$w, $h] = [$info[0], $info[1]];
        $kind = $forceKind !== '' ? $forceKind : (($w / $h) >= 1.45 ? 'shot' : 'logo');
        $maxW = $kind === 'logo' ? 320 : 640;
        $maxH = $kind === 'logo' ? 320 : 480;
        $im = self::scaleDown($im, $maxW, $maxH);
        return [self::store($im, $kind === 'logo'), $kind];
    }

    public static function scaleDown(GdImage $im, int $maxW, int $maxH): GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $f = min($maxW / $w, $maxH / $h, 1.0);
        if ($f >= 1.0) {
            return $im;
        }
        $nw = max(1, (int) round($w * $f));
        $nh = max(1, (int) round($h * $f));
        $out = imagecreatetruecolor($nw, $nh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }

    public static function store(GdImage $im, bool $alpha): string
    {
        imagesavealpha($im, true);
        $name = bin2hex(random_bytes(12));
        $dir = Db::mediaDir();
        if (function_exists('imagewebp')) {
            $name .= '.webp';
            $ok = imagewebp($im, $dir . '/' . $name, 85);
        } elseif ($alpha) {
            $name .= '.png';
            $ok = imagepng($im, $dir . '/' . $name, 6);
        } else {
            $name .= '.jpg';
            $ok = imagejpeg($im, $dir . '/' . $name, 85);
        }
        if (!$ok) {
            throw new RuntimeException('Cannot write image');
        }
        return $name;
    }

    public static function deleteFile(?string $file): void
    {
        if ($file && preg_match('/^[a-f0-9]{24}\.(webp|png|jpg)$/', $file)) {
            @unlink(Db::mediaDir() . '/' . $file);
        }
    }

    // ---------- queue processing ----------

    public static function process(array $dial): void
    {
        $mode = $dial['thumb_mode'];
        $file = null;
        $kind = null;
        $error = null;

        try {
            if ($mode === 'url') {
                try {
                    [$file, $kind] = self::fromUrl((string) $dial['thumb_src']);
                } catch (Throwable $e) {
                    // image URL dead: fall back to the automatic pipeline
                    $error = 'Bild-URL: ' . $e->getMessage();
                    [$file, $kind] = self::autoThumb($dial['url'], (string) $dial['title']);
                    $error = null;
                }
            } elseif ($mode === 'screenshot') {
                [$file, $kind] = self::screenshot($dial['url']);
            } else {
                [$file, $kind] = self::autoThumb($dial['url'], (string) $dial['title']);
            }
        } catch (Throwable $e) {
            $error = mb_substr(($error ? $error . ' / ' : '') . $e->getMessage(), 0, 200);
        }

        if ($file !== null) {
            self::deleteFile($dial['thumb_file']);
            Db::run('UPDATE dials SET thumb_file = ?, thumb_kind = ?, thumb_status = ?, thumb_error = NULL WHERE id = ?',
                [$file, $kind, 'ok', $dial['id']]);
        } else {
            Db::run('UPDATE dials SET thumb_status = ?, thumb_error = ? WHERE id = ?', ['failed', $error ?? 'unknown', $dial['id']]);
        }
    }

    /** The site's own logo, then dashboard-icons, then a screenshot, finally a favicon.
     * @return array{0:string,1:string}
     */
    private static function autoThumb(string $url, string $title = ''): array
    {
        $steps = [
            fn () => self::discoverLogo($url),
            fn () => IconSets::dashboardIcon($url, $title),
            fn () => self::screenshot($url),
            fn () => IconSets::favicon($url),
        ];
        $errors = [];
        foreach ($steps as $step) {
            try {
                return $step();
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        throw new RuntimeException(implode(' / ', $errors));
    }

    /** @return array{0:string,1:string} */
    public static function fromUrl(string $url): array
    {
        $res = Net::fetch($url, 8_000_000);
        if ($res['status'] !== 200) {
            throw new RuntimeException('Image download failed (HTTP ' . $res['status'] . ')');
        }
        return self::ingest($res['body']);
    }

    // ---------- logo discovery ----------

    /** @return array{0:string,1:string} */
    public static function discoverLogo(string $pageUrl): array
    {
        $candidates = [];
        try {
            $page = Net::fetch($pageUrl, 1_500_000, 12);
            if ($page['status'] < 400 && str_contains($page['type'], 'html')) {
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="utf-8"?>' . $page['body'], LIBXML_NOERROR | LIBXML_NOWARNING);
                foreach ($dom->getElementsByTagName('link') as $link) {
                    $rel = strtolower($link->getAttribute('rel'));
                    $href = trim($link->getAttribute('href'));
                    if ($href === '' || !preg_match('/\b(apple-touch-icon(-precomposed)?|icon|shortcut)\b/', $rel)) {
                        continue;
                    }
                    $size = 0;
                    if (preg_match('/(\d+)x(\d+)/', strtolower($link->getAttribute('sizes')), $m)) {
                        $size = (int) $m[1];
                    }
                    $touch = str_contains($rel, 'apple-touch-icon');
                    $candidates[] = [
                        'url'   => Net::absolutize($href, $page['url']),
                        'score' => ($touch ? 1000 : 0) + $size,
                    ];
                }
                $page_url = $page['url'];
            }
        } catch (Throwable) {
            $page_url = $pageUrl;
        }
        $base = parse_url($page_url ?? $pageUrl);
        $origin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '') . (isset($base['port']) ? ':' . $base['port'] : '');
        $candidates[] = ['url' => $origin . '/apple-touch-icon.png', 'score' => 900];
        $candidates[] = ['url' => $origin . '/favicon.png', 'score' => 1];
        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $seen = [];
        foreach (array_slice($candidates, 0, 6) as $c) {
            if (isset($seen[$c['url']])) {
                continue;
            }
            $seen[$c['url']] = true;
            try {
                $res = Net::fetch($c['url'], 2_000_000, 10);
                if ($res['status'] !== 200 || $res['body'] === '') {
                    continue;
                }
                $info = @getimagesizefromstring($res['body']);
                if (!$info || min($info[0], $info[1]) < 96) {
                    continue; // too small to look good as a tile; fall back to a screenshot
                }
                return self::ingest($res['body'], 'logo');
            } catch (Throwable) {
                continue;
            }
        }
        throw new RuntimeException('No suitable logo found');
    }

    // ---------- screenshots ----------

    public static function chromiumBinary(): ?string
    {
        $configured = (string) Config::get('chromium_bin');
        if ($configured !== '') {
            return is_executable($configured) ? $configured : null;
        }
        $names = ['chromium', 'chromium-browser', 'google-chrome', 'google-chrome-stable', 'chrome'];
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/bin:/usr/local/bin') as $dir) {
            foreach ($names as $n) {
                if (is_executable($dir . '/' . $n)) {
                    return $dir . '/' . $n;
                }
            }
        }
        foreach ([
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Brave Browser.app/Contents/MacOS/Brave Browser',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
        ] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /** @return array{0:string,1:string} */
    public static function screenshot(string $url): array
    {
        if (self::chromiumBinary() === null) {
            throw new RuntimeException('Chromium not installed');
        }
        Net::checkTarget($url);
        $im = self::scaleDown(self::chromiumShot($url, 1280, 800), 640, 400);
        return [self::store($im, false), 'shot'];
    }

    // ---------- SVG ----------

    public static function isSvg(string $bytes): bool
    {
        return (bool) preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*>\s*)?(?:<!--.*?-->\s*|<!DOCTYPE[^>]*>\s*)*<svg[\s>]/is', substr($bytes, 0, 4096));
    }

    /**
     * GD cannot read SVG, so Chromium draws it as an <img> (no scripts, no external requests). Headless screenshots have no
     * alpha channel, so it is drawn once on black and once on white and the transparency is worked out from the difference.
     * Returns PNG bytes, longest side 512 px.
     */
    public static function rasterizeSvg(string $svg): string
    {
        if (self::chromiumBinary() === null) {
            throw new RuntimeException('SVG images need Chromium');
        }
        if (strlen($svg) > 2_000_000) {
            throw new RuntimeException('SVG too large');
        }
        $w = $h = 0.0;
        if (preg_match('/<svg\b[^>]*>/is', $svg, $tag)) {
            $attr = static fn (string $n): ?string => preg_match('/\s' . $n . '\s*=\s*["\']([^"\']*)["\']/i', $tag[0], $m) ? trim($m[1]) : null;
            if (($vb = $attr('viewBox')) !== null && preg_match('/^\S+[\s,]+\S+[\s,]+(\S+)[\s,]+(\S+)$/', $vb, $m)) {
                [$w, $h] = [(float) $m[1], (float) $m[2]];
            }
            if ($w <= 0 || $h <= 0) {
                foreach (['width' => &$w, 'height' => &$h] as $n => &$ref) {
                    $v = $attr($n);
                    $ref = $v !== null && preg_match('/^[\d.]+(px)?$/', $v) ? (float) $v : 0.0;
                }
                unset($ref);
            }
        }
        if ($w <= 0 || $h <= 0) {
            [$w, $h] = [512.0, 512.0];
        }
        $f = 512 / max($w, $h);
        [$pw, $ph] = [max(1, (int) round($w * $f)), max(1, (int) round($h * $f))];

        $base = sys_get_temp_dir();
        if (strlen($base) > 40 || !is_writable($base)) {
            $base = Config::get('data_dir') . '/tmp';
        }
        $shots = [];
        foreach (['#000', '#fff'] as $bg) {
            $page = $base . '/sv-' . bin2hex(random_bytes(4)) . '.html';
            file_put_contents($page, '<!doctype html><meta charset="utf-8"><body style="margin:0;background:' . $bg . '"><img alt="" style="display:block;width:' . $pw . 'px;height:' . $ph
                . 'px" src="data:image/svg+xml;base64,' . base64_encode($svg) . '">');
            try {
                $shots[] = self::chromiumShot('file://' . $page, $pw, $ph);
            } finally {
                @unlink($page);
            }
        }
        [$onBlack, $onWhite] = $shots;
        $out = imagecreatetruecolor($pw, $ph);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        for ($y = 0; $y < $ph; $y++) {
            for ($x = 0; $x < $pw; $x++) {
                $b = imagecolorat($onBlack, $x, $y);
                $w = imagecolorat($onWhite, $x, $y);
                $diff = (($w >> 16 & 255) - ($b >> 16 & 255)) + (($w >> 8 & 255) - ($b >> 8 & 255)) + (($w & 255) - ($b & 255));
                $a = max(0.0, min(1.0, 1 - $diff / 765));
                if ($a < 0.004) {
                    imagesetpixel($out, $x, $y, 0x7F000000);
                    continue;
                }
                $r = min(255, (int) round(($b >> 16 & 255) / $a));
                $g = min(255, (int) round(($b >> 8 & 255) / $a));
                $bl = min(255, (int) round(($b & 255) / $a));
                imagesetpixel($out, $x, $y, ((127 - (int) round($a * 127)) << 24) | ($r << 16) | ($g << 8) | $bl);
            }
        }
        ob_start();
        imagepng($out);
        return (string) ob_get_clean();
    }

    // ---------- headless Chromium ----------

    /** Loads $url in headless Chromium at the given viewport and returns the screenshot. */
    private static function chromiumShot(string $url, int $width, int $height): GdImage
    {
        $bin = self::chromiumBinary() ?? throw new RuntimeException('Chromium not installed');

        // Chromium creates a unix socket below the profile dir; sun_path is limited to ~107 bytes, so keep this path short.
        $base = sys_get_temp_dir();
        if (strlen($base) > 40 || !is_writable($base)) {
            $base = Config::get('data_dir') . '/tmp';
        }
        $work = $base . '/st-' . bin2hex(random_bytes(4));
        if (strlen($work) > 55) {
            throw new RuntimeException('Temp path too long for Chromium; set a shorter data_dir or TMPDIR');
        }
        mkdir($work, 0700, true);
        $png = $work . '/shot.png';
        // PHP ignores SIGPIPE and children inherit that; Chromium's zygote then dies with "Broken pipe".
        $prefix = PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/env') ? ['/usr/bin/env', '--default-signal=PIPE'] : [];
        $cmd = array_merge($prefix, [
            $bin, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run', '--no-default-browser-check',
            '--disable-dev-shm-usage', '--disable-extensions', '--mute-audio', '--force-device-scale-factor=1',
            '--user-data-dir=' . $work . '/profile', '--window-size=' . $width . ',' . $height, '--virtual-time-budget=8000',
            '--screenshot=' . $png,
        ], (array) Config::get('chromium_flags'), [$url]);

        try {
            $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $work . '/err.log', 'w']],
                $pipes, $work, ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $work, 'TMPDIR' => $work]);
            if (!is_resource($proc)) {
                throw new RuntimeException('Cannot start Chromium');
            }
            $deadline = time() + 45;
            while (proc_get_status($proc)['running']) {
                if (time() > $deadline) {
                    proc_terminate($proc, 9);
                    break;
                }
                usleep(200_000);
            }
            proc_close($proc);

            if (!is_file($png) || filesize($png) < 100) {
                $lines = array_filter(explode("\n", (string) @file_get_contents($work . '/err.log')),
                    static fn ($l) => $l !== '' && !str_contains($l, 'dbus') && !str_contains($l, 'DBus'));
                throw new RuntimeException('Screenshot failed' . ($lines ? ': ' . mb_substr(trim(implode(' | ', array_slice(array_values($lines), 0, 3))), 0, 400) : ''));
            }
            $im = @imagecreatefrompng($png);
            if (!$im) {
                throw new RuntimeException('Screenshot unreadable');
            }
            return $im;
        } finally {
            self::rmTree($work);
        }
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
