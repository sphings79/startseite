<?php
declare(strict_types=1);

/**
 * Imports a Startseite backup (ZIP or data.json), a Speed Dial 2 export (JSON) or a browser bookmark export (Netscape HTML).
 */
final class Importer
{
    /** @return array{groups:int,dials:int,skipped?:int} */
    public static function fromFile(string $path, bool $replace): array
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if (preg_match('/<!DOCTYPE\s+NETSCAPE-Bookmark-file|<DL\b/i', (string) file_get_contents($path, false, null, 0, 4096))) {
            return self::browserBookmarks((string) file_get_contents($path), $replace);
        }
        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Archiv nicht lesbar.');
            }
            $json = $zip->getFromName('data.json');
            if ($json === false) {
                throw new RuntimeException('data.json fehlt im Archiv.');
            }
            $data = json_decode($json, true);
            if (!is_array($data) || ($data['format'] ?? '') !== Exporter::FORMAT) {
                throw new RuntimeException('Unbekanntes Archivformat.');
            }
            return self::restore($data, $replace, $zip);
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('Datei ist kein gültiges JSON.');
        }
        if (($data['format'] ?? '') === Exporter::FORMAT) {
            return self::restore($data, $replace, null);
        }
        if (isset($data['dials'], $data['groups'])) {
            return self::speedDial($data, $replace);
        }
        throw new RuntimeException('Unbekanntes Dateiformat.');
    }

    // ---------- browser bookmarks (Netscape HTML: Chrome, Edge, Firefox, Safari) ----------

    private const ROOT_FOLDERS = ['bookmarks bar', 'lesezeichenleiste', 'lesezeichen-symbolleiste', 'bookmarks toolbar', 'favorites bar', 'favoritenleiste',
        'other bookmarks', 'weitere lesezeichen', 'bookmarks menu', 'lesezeichen-menü', 'favorites', 'favoriten'];

    /** Every folder becomes a group (nested ones as "A / B"); URLs that already exist are skipped. */
    private static function browserBookmarks(string $html, bool $replace): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $tokens = preg_split('#(<(?:/?DL|/?H[13]|A|/A)\b[^>]*>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $decode = static fn (string $s): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        $stack = [];      // folder names per open <DL>; null = folder without a name of its own
        $pending = null;  // folder heading seen, <DL> not yet opened
        $mode = '';
        $buffer = '';
        $link = null;
        $found = [];      // [group title, url, title, added]
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if ($i % 2 === 0) {
                $buffer .= $t;
                continue;
            }
            $tag = strtolower($t);
            if (preg_match('#^<h3\b#', $tag)) {
                $mode = 'h';
                $buffer = '';
                $pending = ['attrs' => $tag, 'name' => ''];
            } elseif (preg_match('#^</h3#', $tag)) {
                if ($pending !== null) {
                    $name = $decode($buffer);
                    $root = count($stack) <= 1 && (str_contains($pending['attrs'], 'personal_toolbar_folder') || str_contains($pending['attrs'], 'unfiled_bookmarks_folder')
                        || in_array(mb_strtolower($name), self::ROOT_FOLDERS, true));
                    $pending['name'] = $root ? null : $name;
                }
                $mode = '';
            } elseif (preg_match('#^<dl\b#', $tag)) {
                $stack[] = $pending['name'] ?? null;
                $pending = null;
            } elseif (preg_match('#^</dl#', $tag)) {
                array_pop($stack);
            } elseif (preg_match('#^<a\b#', $tag)) {
                $link = ['tag' => $t];
                $buffer = '';
            } elseif (str_starts_with($tag, '</a') && $link !== null) {
                if (preg_match('/\bhref\s*=\s*"([^"]*)"/i', $link['tag'], $m)) {
                    $added = preg_match('/\badd_date\s*=\s*"(\d+)"/i', $link['tag'], $d) ? (int) $d[1] : 0;
                    $added = $added > 4_000_000_000 ? intdiv($added, 1_000_000) : $added;
                    $path = array_values(array_filter($stack, static fn ($x) => $x !== null && $x !== ''));
                    $found[] = [mb_substr(implode(' / ', $path) ?: 'Importiert', 0, 80), html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $decode($buffer), $added];
                }
                $link = null;
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($replace) {
                self::wipe();
            }
            $norm = static fn (string $u): string => rtrim((string) preg_replace('#^https?://(www\.)?#i', '', strtolower($u)), '/');
            $seen = [];
            foreach (Db::all('SELECT url FROM dials') as $d) {
                $seen[$norm($d['url'])] = true;
            }
            $groups = [];
            $next = [];
            $count = $skipped = 0;
            foreach ($found as [$groupTitle, $href, $title, $added]) {
                try {
                    $url = Api::normalizeUrl($href);
                } catch (InvalidArgumentException) {
                    continue; // javascript:, place:, file: ...
                }
                if (isset($seen[$norm($url)])) {
                    $skipped++;
                    continue;
                }
                $seen[$norm($url)] = true;
                $gid = $groups[$groupTitle] ??= self::groupByTitle($groupTitle, $replace);
                $next[$gid] ??= (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM dials WHERE group_id = ?', [$gid])['p'];
                Db::run("INSERT INTO dials (group_id, position, title, url, thumb_status, created_at) VALUES (?,?,?,?, 'pending', ?)",
                    [$gid, $next[$gid]++, mb_substr($title !== '' ? $title : (string) parse_url($url, PHP_URL_HOST), 0, 200), $url, $added > 0 ? $added : time()]);
                $count++;
            }
            Sections::repair();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Import fehlgeschlagen: ' . $e->getMessage());
        }
        return ['groups' => count($groups), 'dials' => $count, 'skipped' => $skipped];
    }

    private static function wipe(): void
    {
        foreach (Db::all('SELECT thumb_file FROM dials') as $d) {
            Thumbs::deleteFile($d['thumb_file']);
        }
        Db::run('DELETE FROM dials');
        Db::run('DELETE FROM sections');
        Db::run('DELETE FROM groups');
    }

    // ---------- own format ----------

    private static function restore(array $data, bool $replace, ?ZipArchive $zip): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($replace) {
                self::wipe();
            }
            $gmap = [];
            $newGroups = [];
            $gcount = 0;
            foreach ((array) $data['groups'] as $g) {
                $had = Db::one('SELECT id FROM groups WHERE title = ?', [mb_substr(trim((string) $g['title']), 0, 80) ?: 'Gruppe']) !== null;
                $gmap[(int) $g['id']] = self::groupByTitle((string) $g['title'], $replace);
                if (!$had) {
                    $newGroups[$gmap[(int) $g['id']]] = true;
                }
                if (!empty($g['no_check'])) {
                    Db::run('UPDATE groups SET no_check = 1 WHERE id = ?', [$gmap[(int) $g['id']]]);
                }
                $gcount++;
            }
            // Sections only come along for tabs that were just created; merged tabs keep their own layout.
            $smap = [];
            foreach ((array) ($data['sections'] ?? []) as $sec) {
                $gid = $gmap[(int) ($sec['group_id'] ?? 0)] ?? null;
                if ($gid === null || (!isset($newGroups[$gid]) && !$replace)) {
                    continue;
                }
                try {
                    $smap[(int) $sec['id']] = Sections::create(['group_id' => $gid] + $sec)['id'];
                } catch (InvalidArgumentException) {
                    // invalid section values: the dials fall back to the tab's first section
                }
            }
            $dcount = 0;
            foreach ((array) $data['dials'] as $d) {
                if (!isset($gmap[(int) $d['group_id']])) {
                    continue;
                }
                $file = null;
                if ($zip && !empty($d['thumb_file']) && preg_match('/^[a-f0-9]{24}\.(webp|png|jpg)$/', $d['thumb_file'])) {
                    $file = self::copyMedia($zip, $d['thumb_file']);
                }
                $url = Api::normalizeUrl($d['url']);
                $mode = in_array($d['thumb_mode'], ['auto', 'screenshot', 'url', 'upload', 'none'], true) ? $d['thumb_mode'] : 'auto';
                $status = $file ? 'ok' : (in_array($mode, ['auto', 'screenshot', 'url'], true) ? 'pending' : 'none');
                $x = self::extras($d);
                Db::run('INSERT INTO dials (group_id, position, title, url, thumb_mode, thumb_src, thumb_file, thumb_kind, fit, thumb_status, visits, last_visit, created_at, no_check, tags, note, color, badge, badge_color, keep, section_id)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$gmap[(int) $d['group_id']], (int) $d['position'], mb_substr((string) $d['title'], 0, 200), $url, $mode, $d['thumb_src'] ?? null,
                        $file, $file ? ($d['thumb_kind'] ?? null) : null, in_array($d['fit'] ?? 'auto', ['auto', 'cover', 'contain'], true) ? $d['fit'] : 'auto',
                        $status, (int) ($d['visits'] ?? 0), $d['last_visit'] ?? null, (int) ($d['created_at'] ?? time()), empty($d['no_check']) ? 0 : 1,
                        $x['tags'], $x['note'], $x['color'], $x['badge'], $x['badge_color'], empty($d['keep']) ? 0 : 1, $smap[(int) ($d['section_id'] ?? 0)] ?? null]);
                $dcount++;
            }
            foreach ((array) ($data['settings'] ?? []) as $key => $value) {
                try {
                    if (in_array($key, ['bg_light', 'bg_dark'], true) && is_array($value)) {
                        $file = ($zip && !empty($value['file']) && preg_match('/^[a-f0-9]{24}\.(webp|png|jpg)$/', $value['file']))
                            ? self::copyMedia($zip, $value['file']) : '';
                        $value = Settings::normalize($key, $value);
                        $value['file'] = $file ?? '';
                        Settings::set($key, $value, true);
                    } else {
                        Settings::set((string) $key, $value);
                    }
                } catch (InvalidArgumentException) {
                    // skip unknown or invalid settings
                }
            }
            Sections::repair();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Import fehlgeschlagen: ' . $e->getMessage());
        }
        return ['groups' => $gcount, 'dials' => $dcount];
    }

    /** Tags, note, colours and badge of an imported dial, validated like the API does. @return array<string,?string> */
    private static function extras(array $d): array
    {
        $color = static fn ($v) => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : null;
        $tags = [];
        foreach ((array) ($d['tags'] ?? []) as $t) {
            $t = mb_substr(trim((string) $t), 0, 30);
            if ($t !== '') {
                $tags[] = $t;
            }
        }
        return [
            'tags' => json_encode(array_slice(array_values(array_unique($tags)), 0, 12), JSON_UNESCAPED_UNICODE),
            'note' => mb_substr(trim((string) ($d['note'] ?? '')), 0, 1000),
            'color' => $color($d['color'] ?? null),
            'badge' => ($b = mb_substr(trim((string) ($d['badge'] ?? '')), 0, 12)) === '' ? null : $b,
            'badge_color' => $color($d['badge_color'] ?? null),
        ];
    }

    private static function copyMedia(ZipArchive $zip, string $name): ?string
    {
        $bytes = $zip->getFromName('media/' . $name);
        if ($bytes === false) {
            return null;
        }
        $new = bin2hex(random_bytes(12)) . substr($name, strrpos($name, '.'));
        file_put_contents(Db::mediaDir() . '/' . $new, $bytes);
        return $new;
    }

    /** Finds a group with the same title (merge) or creates it. */
    private static function groupByTitle(string $title, bool $freshInstall): int
    {
        $title = mb_substr(trim($title), 0, 80) ?: 'Gruppe';
        $row = Db::one('SELECT id FROM groups WHERE title = ?', [$title]);
        if ($row) {
            return (int) $row['id'];
        }
        $pos = (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM groups')['p'];
        Db::run('INSERT INTO groups (title, position) VALUES (?, ?)', [$title, $pos]);
        return Db::insertId();
    }

    // ---------- Speed Dial 2 ----------

    private static function speedDial(array $data, bool $replace): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($replace) {
                self::wipe();
            }
            $gmap = [];
            $groups = (array) $data['groups'];
            usort($groups, static fn ($a, $b) => 0); // keep export order; positions in the export are not reliable
            foreach ($groups as $g) {
                $gmap[(int) $g['id']] = self::groupByTitle((string) $g['title'], $replace);
            }
            $dials = (array) $data['dials'];
            $order = array_keys($dials);
            usort($order, static fn ($a, $b) => [(int) ($dials[$a]['position'] ?? 0), $a] <=> [(int) ($dials[$b]['position'] ?? 0), $b]);

            $count = 0;
            $next = [];
            foreach ($order as $i) {
                $d = $dials[$i];
                $gid = $gmap[(int) ($d['idgroup'] ?? -1)] ?? null;
                if ($gid === null || empty($d['url'])) {
                    continue;
                }
                try {
                    $url = Api::normalizeUrl($d['url']);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $next[$gid] ??= (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM dials WHERE group_id = ?', [$gid])['p'];
                $thumb = trim((string) ($d['thumbnail'] ?? ''));
                $mode = 'auto';
                $src = null;
                $file = null;
                $kind = null;
                $status = 'pending';
                if (preg_match('#^https?://#i', $thumb)) {
                    $mode = 'url';
                    $src = $thumb;
                } elseif (preg_match('#^data:image/[a-z+.-]+;base64,(.+)$#is', $thumb, $m)) {
                    try {
                        [$file, $kind] = Thumbs::ingest((string) base64_decode($m[1], true));
                        $mode = 'upload';
                        $status = 'ok';
                    } catch (Throwable) {
                        // fall back to auto
                    }
                }
                $title = trim((string) ($d['title'] ?? '')) ?: (parse_url($url, PHP_URL_HOST) ?: $url);
                Db::run('INSERT INTO dials (group_id, position, title, url, thumb_mode, thumb_src, thumb_file, thumb_kind, thumb_status, visits, last_visit, created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$gid, $next[$gid]++, mb_substr($title, 0, 200), $url, $mode, $src, $file, $kind, $status,
                        (int) ($d['visits'] ?? 0), null, (int) ($d['ts_created'] ?? time())]);
                $count++;
            }
            self::speedDialPreferences((array) ($data['preferences'] ?? []));
            Sections::repair();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e instanceof RuntimeException ? $e : new RuntimeException('Import fehlgeschlagen: ' . $e->getMessage());
        }
        return ['groups' => count(array_unique($gmap)), 'dials' => $count];
    }

    private static function hex(mixed $v): ?string
    {
        $v = ltrim(trim((string) $v), '#');
        return preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $v) ? '#' . strtolower(substr($v, 0, 6)) : null;
    }

    private static function speedDialPreferences(array $p): void
    {
        $apply = static function (string $key, mixed $value): void {
            if ($value === null) {
                return;
            }
            try {
                Settings::set($key, $value);
            } catch (InvalidArgumentException) {
            }
        };
        $b = (array) ($p['bookmarks'] ?? []);
        $apply('columns', $p['columns'] ?? null);
        $apply('max_width', $p['maxWidth'] ?? null);
        $apply('spacing', $p['spacing'] ?? null);
        $apply('open_new_tab', isset($p['openInNewTab']) ? (bool) $p['openInNewTab'] : null);
        $apply('show_add_button', isset($p['showAddButton']) ? (bool) $p['showAddButton'] : null);
        $apply('center_vertical', isset($p['centeredLayout']) ? (bool) $p['centeredLayout'] : null);
        $apply('show_title', isset($b['showTitle']) ? (bool) $b['showTitle'] : null);
        $apply('show_visits', isset($b['showVisits']) ? (bool) $b['showVisits'] : null);
        $apply('title_align', $b['alignTitle'] ?? null);
        $apply('border_radius', ['none' => 0, 'small' => 10, 'medium' => 16, 'large' => 24][$b['borderRadius'] ?? ''] ?? null);
        if (in_array($p['orderBy'] ?? '', ['manual', 'visits', 'title'], true)) {
            $apply('order_by', $p['orderBy']);
        }

        $theme = (array) ($p['theme'] ?? []);
        $apply('theme', $theme['theme'] ?? null);
        $apply('font_scale', $theme['fontSize'] ?? null);
        foreach (['light', 'dark'] as $mode) {
            $t = (array) ($theme[$mode] ?? []);
            if (($c = self::hex($t['backgroundColor'] ?? '')) !== null) {
                $bg = ['color' => $c];
            } else {
                $bg = [];
            }
            $bg += [
                'size'     => in_array($t['backgroundSize'] ?? '', ['cover', 'contain', 'auto'], true) ? $t['backgroundSize'] : 'cover',
                'repeat'   => ($t['backgroundRepeat'] ?? '') === 'repeat' ? 'repeat' : 'no-repeat',
                'position' => str_replace('-', ' ', (string) ($t['backgroundPosition'] ?? 'center-bottom')),
                'opacity'  => (int) ($t['backgroundOpacity'] ?? 100),
            ];
            try {
                Settings::set('bg_' . $mode, $bg);
            } catch (InvalidArgumentException) {
            }
            $img = (string) ($t['backgroundImage'] ?? '');
            if (preg_match('#^https?://#i', $img)) {
                try {
                    $res = Net::fetch($img, 12_000_000, 20);
                    $im = $res['status'] === 200 ? @imagecreatefromstring($res['body']) : false;
                    if ($im) {
                        Settings::setBackgroundFile($mode, Thumbs::store(Thumbs::scaleDown($im, 2560, 1600), true));
                    }
                } catch (Throwable) {
                    // background can be uploaded again manually
                }
            }
            $tileBg = self::hex($t['bookmarksBackgroundColor'] ?? '');
            $tileTx = self::hex($t['bookmarksTextColor'] ?? '');
            $apply('tile_bg_' . $mode, $mode === 'dark' ? null : $tileBg);
            $apply('tile_text_' . $mode, $mode === 'dark' ? null : $tileTx);
        }
    }
}
