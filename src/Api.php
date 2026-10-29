<?php
declare(strict_types=1);

/**
 * JSON API (all routes require a session; mutating routes require the CSRF header).
 */
final class Api
{
    private const THUMB_MODES = ['auto', 'screenshot', 'url', 'upload', 'none'];
    private const UPLOAD_LIMIT = 12_000_000;

    public static function dispatch(string $method, string $path, array $session): never
    {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            Http::assertSameOrigin();
            if (!hash_equals($session['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
                throw new HttpError(403, 'Invalid CSRF token');
            }
        }

        $m = [];
        try {
            switch (true) {
                case $method === 'GET' && $path === '/api/state':
                    Http::json(self::state($session));
                case $method === 'GET' && $path === '/api/page-title':
                    Http::json(['title' => self::pageTitle((string) ($_GET['url'] ?? ''))]);
                case $method === 'GET' && $path === '/api/jobs':
                    Http::json(self::jobs());

                case $method === 'POST' && $path === '/api/groups':
                    Http::json(self::groupCreate(Http::body()), 201);
                case $method === 'POST' && $path === '/api/groups/order':
                    self::groupOrder(Http::body());
                case $method === 'PUT' && preg_match('#^/api/groups/(\d+)$#', $path, $m):
                    self::groupRename((int) $m[1], Http::body());
                case $method === 'DELETE' && preg_match('#^/api/groups/(\d+)$#', $path, $m):
                    self::groupDelete((int) $m[1]);

                case $method === 'POST' && $path === '/api/sections':
                    Http::json(Sections::create(Http::body()), 201);
                case $method === 'POST' && $path === '/api/sections/order':
                    Sections::order(Http::body());
                    Http::json(['ok' => true]);
                case $method === 'PUT' && preg_match('#^/api/sections/(\d+)$#', $path, $m):
                    Http::json(Sections::update((int) $m[1], Http::body()));
                case $method === 'DELETE' && preg_match('#^/api/sections/(\d+)$#', $path, $m):
                    Sections::delete((int) $m[1]);
                    Http::json(['ok' => true]);
                case $method === 'POST' && $path === '/api/dials/layout':
                    Sections::layout(Http::body());
                    Http::json(['ok' => true]);

                case $method === 'POST' && $path === '/api/dials':
                    Http::json(self::dialCreate(Http::body()), 201);
                case $method === 'POST' && $path === '/api/dials/order':
                    self::dialOrder(Http::body());
                case $method === 'POST' && $path === '/api/dials/keep':
                    self::dialKeep(Http::body());
                case $method === 'POST' && $path === '/api/dials/bulk-delete':
                    self::dialBulkDelete(Http::body());
                case $method === 'POST' && $path === '/api/checks/run':
                    Http::json(['queued' => self::queueChecks(Http::body())]);
                case $method === 'POST' && $path === '/api/thumbs/missing':
                    Http::json(['queued' => self::queueMissing()]);
                case $method === 'PUT' && preg_match('#^/api/dials/(\d+)$#', $path, $m):
                    self::dialUpdate((int) $m[1], Http::body());
                case $method === 'DELETE' && preg_match('#^/api/dials/(\d+)$#', $path, $m):
                    self::dialDelete((int) $m[1]);
                case $method === 'POST' && preg_match('#^/api/dials/(\d+)/visit$#', $path, $m):
                    Db::run('UPDATE dials SET visits = visits + 1, last_visit = ? WHERE id = ?', [time(), (int) $m[1]]);
                    Http::json(['ok' => true]);
                case $method === 'POST' && preg_match('#^/api/dials/(\d+)/refresh$#', $path, $m):
                    self::dialRefresh((int) $m[1]);
                case $method === 'POST' && preg_match('#^/api/dials/(\d+)/thumb$#', $path, $m):
                    self::dialUpload((int) $m[1]);

                case $method === 'PUT' && $path === '/api/settings':
                    self::settingsUpdate(Http::body());
                case $method === 'POST' && preg_match('#^/api/background/(light|dark)$#', $path, $m):
                    self::backgroundUpload($m[1]);
                case $method === 'DELETE' && preg_match('#^/api/background/(light|dark)$#', $path, $m):
                    Settings::setBackgroundFile($m[1], '');
                    Http::json(['ok' => true]);

                case $method === 'GET' && $path === '/api/mfa':
                    Http::json(self::mfaStatus($session));
                case $method === 'POST' && $path === '/api/mfa/totp/begin':
                    self::mfaPassword($session);
                    Http::json(Mfa::totpBegin((int) $session['user_id']));
                case $method === 'POST' && $path === '/api/mfa/totp/confirm':
                    if (!Mfa::totpConfirm((int) $session['user_id'], (string) (Http::body()['code'] ?? ''))) {
                        throw new InvalidArgumentException('Der Code stimmt nicht. Bitte die Uhrzeit des Handys prüfen und erneut versuchen.');
                    }
                    Http::json(self::mfaStatus($session, self::mfaEnabled($session)));
                case $method === 'POST' && $path === '/api/mfa/totp/disable':
                    self::mfaPassword($session);
                    Mfa::totpDisable((int) $session['user_id']);
                    Http::json(self::mfaStatus($session));
                case $method === 'POST' && $path === '/api/mfa/passkey/options':
                    self::mfaPassword($session);
                    Http::json(Mfa::passkeyCreateOptions((int) $session['user_id']));
                case $method === 'POST' && $path === '/api/mfa/passkey/register':
                    $b = Http::body();
                    Mfa::passkeyRegister((int) $session['user_id'], (array) ($b['credential'] ?? []), (string) ($b['name'] ?? ''));
                    Http::json(self::mfaStatus($session, self::mfaEnabled($session)));
                case $method === 'POST' && preg_match('#^/api/mfa/passkey/(\d+)/delete$#', $path, $m):
                    self::mfaPassword($session);
                    Mfa::passkeyDelete((int) $session['user_id'], (int) $m[1]);
                    Http::json(self::mfaStatus($session));
                case $method === 'POST' && $path === '/api/mfa/recovery':
                    self::mfaPassword($session);
                    if (!Mfa::enabled((int) $session['user_id'])) {
                        throw new InvalidArgumentException('Zuerst einen zweiten Faktor einrichten.');
                    }
                    Http::json(self::mfaStatus($session, Mfa::recoveryGenerate((int) $session['user_id'])));

                case $method === 'POST' && $path === '/api/password':
                    $b = Http::body();
                    $err = Auth::changePassword($session, (string) ($b['current'] ?? ''), (string) ($b['new'] ?? ''));
                    Http::json($err === null ? ['ok' => true] : ['error' => $err], $err === null ? 200 : 422);
                case $method === 'POST' && $path === '/api/username':
                    $b = Http::body();
                    $err = Auth::changeUsername($session, (string) ($b['current'] ?? ''), (string) ($b['username'] ?? ''));
                    Http::json($err === null ? ['ok' => true, 'username' => trim((string) $b['username'])] : ['error' => $err], $err === null ? 200 : 422);
                case $method === 'POST' && $path === '/api/logout':
                    Auth::logout();
                    Http::json(['ok' => true]);

                case $method === 'GET' && $path === '/api/backups':
                    Http::json(['backups' => Backup::list()]);
                case $method === 'POST' && $path === '/api/backups':
                    try {
                        Backup::create();
                    } catch (RuntimeException $e) {
                        throw new InvalidArgumentException('Sicherung fehlgeschlagen: ' . $e->getMessage());
                    }
                    Http::json(['backups' => Backup::list()]);
                case $method === 'GET' && preg_match('#^/api/backups/([A-Za-z0-9.-]+)$#', $path, $m):
                    self::backupDownload($m[1]);
                case $method === 'DELETE' && preg_match('#^/api/backups/([A-Za-z0-9.-]+)$#', $path, $m):
                    if ($file = Backup::path($m[1])) {
                        unlink($file);
                    }
                    Http::json(['backups' => Backup::list()]);

                case $method === 'GET' && $path === '/api/export':
                    Exporter::download();
                case $method === 'POST' && $path === '/api/import':
                    self::import();
            }
        } catch (InvalidArgumentException $e) {
            Http::json(['error' => $e->getMessage()], 422);
        }
        throw new HttpError(404, 'Not found');
    }

    // ---------- state ----------

    private static function dialOut(array $d): array
    {
        return [
            'id'           => (int) $d['id'],
            'group_id'     => (int) $d['group_id'],
            'section_id'   => (int) $d['section_id'],
            'position'     => (int) $d['position'],
            'title'        => $d['title'],
            'url'          => $d['url'],
            'thumb'        => $d['thumb_file'] ? Http::url('/media/' . $d['thumb_file']) : null,
            'thumb_mode'   => $d['thumb_mode'],
            'thumb_src'    => $d['thumb_src'],
            'thumb_kind'   => $d['thumb_kind'],
            'fit'          => $d['fit'],
            'thumb_status' => $d['thumb_status'],
            'thumb_error'  => $d['thumb_error'],
            'visits'       => (int) $d['visits'],
            'last_visit'   => $d['last_visit'] !== null ? (int) $d['last_visit'] : null,
            'created_at'   => (int) $d['created_at'],
            'no_check'     => (bool) $d['no_check'],
            'tags'         => json_decode((string) $d['tags'], true) ?: [],
            'note'         => $d['note'],
            'meta_desc'    => (string) $d['meta_desc'],
            'color'        => $d['color'],
            'badge'        => $d['badge'],
            'badge_color'  => $d['badge_color'],
            'keep'         => (bool) $d['keep'],
            'check_status' => $d['check_req'] > 0 ? 'queued' : $d['check_status'],
            'check_reason' => $d['check_reason'],
            'check_code'   => $d['check_code'] !== null ? (int) $d['check_code'] : null,
            'check_at'     => $d['check_at'] !== null ? (int) $d['check_at'] : null,
        ];
    }

    public static function state(array $session): array
    {
        if (Db::one('SELECT 1 FROM groups LIMIT 1') === null) {
            Db::run("INSERT INTO groups (title, position) VALUES ('Allgemein', 0)"); // a fresh install needs somewhere to put bookmarks
        }
        Sections::repair();
        $settings = Settings::all();
        foreach (['bg_light', 'bg_dark'] as $k) {
            $settings[$k]['url'] = $settings[$k]['file'] !== '' ? Http::url('/media/' . $settings[$k]['file']) : '';
        }
        return [
            'base'     => Http::basePath(),
            'csrf'     => $session['csrf'],
            'username' => $session['username'],
            'settings' => $settings,
            'groups'   => array_map(static fn ($g) => ['id' => (int) $g['id'], 'title' => $g['title'], 'position' => (int) $g['position'], 'no_check' => (bool) $g['no_check']],
                Db::all('SELECT * FROM groups ORDER BY position, id')),
            'sections' => Sections::all(),
            'dials'    => array_map([self::class, 'dialOut'], Db::all('SELECT * FROM dials ORDER BY position, id')),
            'jobs'     => self::jobs(),
            'screenshots' => Thumbs::chromiumBinary() !== null,
        ];
    }

    /** Data for the quick-add page: URL/title from the query (or a URL found in the shared text), groups, duplicate hint. */
    public static function addBoot(array $session): array
    {
        $url = trim((string) ($_GET['url'] ?? ''));
        $text = (string) ($_GET['text'] ?? '');
        if (!preg_match('#^https?://#i', $url) && preg_match('#https?://[^\s<>"\']+#i', $url . ' ' . $text, $m)) {
            $url = $m[0];
        }
        try {
            $url = self::normalizeUrl($url);
        } catch (InvalidArgumentException) {
            $url = '';
        }
        $title = mb_substr(trim((string) ($_GET['title'] ?? '')), 0, 200);
        if ($title === $url) {
            $title = '';
        }
        $norm = static fn (string $u): string => rtrim((string) preg_replace('#^https?://(www\.)?#i', '', strtolower($u)), '/');
        $existing = null;
        if ($url !== '') {
            foreach (Db::all('SELECT d.url, g.title AS gtitle FROM dials d JOIN groups g ON g.id = d.group_id') as $d) {
                if ($norm($d['url']) === $norm($url)) {
                    $existing = $d['gtitle'];
                    break;
                }
            }
        }
        return [
            'base'     => Http::basePath(),
            'csrf'     => $session['csrf'],
            'url'      => $url,
            'title'    => $title,
            'existing' => $existing,
            'popup'    => !empty($_GET['popup']),
            'groups'   => array_map(static fn ($g) => ['id' => (int) $g['id'], 'title' => $g['title']], Db::all('SELECT id, title FROM groups ORDER BY position, id')),
            'app_name' => (string) Settings::get('app_name'),
        ];
    }

    /** Best-effort page title for pre-filling forms; empty on any problem. */
    private static function pageTitle(string $url): string
    {
        try {
            $res = Net::fetch(self::normalizeUrl($url), 400_000, 8);
        } catch (Throwable) {
            return '';
        }
        if ($res['status'] >= 400 || !preg_match('#<title[^>]*>(.*?)</title>#is', $res['body'], $m)) {
            return '';
        }
        $t = $m[1];
        if (preg_match('/charset=([\w-]+)/', $res['type'], $cs) && !preg_match('/^utf-?8$/i', $cs[1])) {
            $t = @mb_convert_encoding($t, 'UTF-8', $cs[1]) ?: $t;
        } elseif (!mb_check_encoding($t, 'UTF-8')) {
            $t = mb_convert_encoding($t, 'UTF-8', 'ISO-8859-1');
        }
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t)), 0, 200);
    }

    private static function jobs(): array
    {
        $out = ['pending' => 0, 'running' => 0, 'failed' => 0, 'checks' => (int) Db::one('SELECT COUNT(*) AS c FROM dials WHERE check_req > 0')['c']];
        foreach (Db::all("SELECT thumb_status AS s, COUNT(*) AS c FROM dials WHERE thumb_status IN ('pending','running','failed') GROUP BY thumb_status") as $r) {
            $out[$r['s']] = (int) $r['c'];
        }
        return $out;
    }

    // ---------- validation ----------

    private static function title(mixed $v, int $max = 200): string
    {
        $t = trim((string) $v);
        if ($t === '' || mb_strlen($t) > $max) {
            throw new InvalidArgumentException('Titel fehlt oder ist zu lang.');
        }
        return $t;
    }

    public static function normalizeUrl(mixed $v): string
    {
        $u = trim((string) $v);
        if ($u === '') {
            throw new InvalidArgumentException('URL fehlt.');
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $u)) {
            $u = 'https://' . $u;
        }
        $p = parse_url($u);
        if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || strlen($u) > 2048) {
            throw new InvalidArgumentException('Ungültige URL (nur http/https).');
        }
        return $u;
    }

    /**
     * Tags, note, colours and badge from a request body; keys that are absent keep the current value.
     * @return array{tags:string,note:string,color:?string,badge:?string,badge_color:?string}
     */
    private static function extras(array $in, ?array $cur): array
    {
        $out = [
            'tags' => $cur['tags'] ?? '[]', 'note' => $cur['note'] ?? '', 'color' => $cur['color'] ?? null,
            'badge' => $cur['badge'] ?? null, 'badge_color' => $cur['badge_color'] ?? null,
        ];
        if (array_key_exists('tags', $in)) {
            $tags = [];
            foreach ((array) $in['tags'] as $t) {
                $t = mb_substr(trim((string) preg_replace('/\s+/u', ' ', ltrim((string) $t, '#'))), 0, 30);
                if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tags), true)) {
                    $tags[] = $t;
                }
            }
            $out['tags'] = json_encode(array_slice($tags, 0, 12), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('note', $in)) {
            $out['note'] = mb_substr(trim((string) $in['note']), 0, 1000);
        }
        foreach (['color', 'badge_color'] as $k) {
            if (array_key_exists($k, $in)) {
                $v = $in[$k] === null ? '' : strtolower(trim((string) $in[$k]));
                if ($v !== '' && !preg_match('/^#[0-9a-f]{6}$/', $v)) {
                    throw new InvalidArgumentException('Ungültige Farbe.');
                }
                $out[$k] = $v === '' ? null : $v;
            }
        }
        if (array_key_exists('badge', $in)) {
            $b = mb_substr(trim((string) $in['badge']), 0, 12);
            $out['badge'] = $b === '' ? null : $b;
        }
        return $out;
    }

    private static function groupExists(int $id): bool
    {
        return Db::one('SELECT 1 FROM groups WHERE id = ?', [$id]) !== null;
    }

    // ---------- groups ----------

    private static function groupCreate(array $in): array
    {
        $title = self::title($in['title'] ?? '', 80);
        $pos = (int) (Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM groups')['p']);
        Db::run('INSERT INTO groups (title, position) VALUES (?, ?)', [$title, $pos]);
        $id = Db::insertId();
        Sections::defaultId($id);
        return ['id' => $id, 'title' => $title, 'position' => $pos];
    }

    private static function groupRename(int $id, array $in): never
    {
        if (array_key_exists('title', $in)) {
            Db::run('UPDATE groups SET title = ? WHERE id = ?', [self::title($in['title'], 80), $id]);
        }
        if (array_key_exists('no_check', $in)) {
            Db::run('UPDATE groups SET no_check = ? WHERE id = ?', [$in['no_check'] ? 1 : 0, $id]);
            LinkCheck::reapplyExclusions();
        }
        Http::json(['ok' => true]);
    }

    private static function groupOrder(array $in): never
    {
        foreach (array_values((array) ($in['ids'] ?? [])) as $i => $id) {
            Db::run('UPDATE groups SET position = ? WHERE id = ?', [$i, (int) $id]);
        }
        Http::json(['ok' => true]);
    }

    private static function groupDelete(int $id): never
    {
        if ((int) Db::one('SELECT COUNT(*) AS c FROM groups')['c'] <= 1) {
            throw new InvalidArgumentException('Die letzte Gruppe kann nicht gelöscht werden.');
        }
        foreach (Db::all('SELECT thumb_file FROM dials WHERE group_id = ?', [$id]) as $d) {
            Thumbs::deleteFile($d['thumb_file']);
        }
        Db::run('DELETE FROM groups WHERE id = ?', [$id]);
        Http::json(['ok' => true]);
    }

    // ---------- dials ----------

    private static function dialCreate(array $in): array
    {
        $group = (int) ($in['group_id'] ?? 0);
        if (!self::groupExists($group)) {
            throw new InvalidArgumentException('Gruppe unbekannt.');
        }
        $url = self::normalizeUrl($in['url'] ?? '');
        $title = trim((string) ($in['title'] ?? '')) !== '' ? self::title($in['title']) : (parse_url($url, PHP_URL_HOST) ?: $url);
        $mode = in_array($in['thumb_mode'] ?? 'auto', self::THUMB_MODES, true) ? ($in['thumb_mode'] ?? 'auto') : 'auto';
        $src = $mode === 'url' ? self::normalizeUrl($in['thumb_src'] ?? '') : null;
        $fit = in_array($in['fit'] ?? 'auto', ['auto', 'cover', 'contain'], true) ? ($in['fit'] ?? 'auto') : 'auto';
        $status = in_array($mode, ['auto', 'screenshot', 'url'], true) ? 'pending' : 'none';
        $pos = (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM dials WHERE group_id = ?', [$group])['p'];
        $x = self::extras($in, null);
        $section = isset($in['section_id']) && Sections::belongs((int) $in['section_id'], $group) ? (int) $in['section_id'] : Sections::defaultId($group);
        Db::run('INSERT INTO dials (group_id, section_id, position, title, url, thumb_mode, thumb_src, fit, thumb_status, created_at, tags, note, color, badge, badge_color) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$group, $section, $pos, $title, $url, $mode, $src, $fit, $status, time(), $x['tags'], $x['note'], $x['color'], $x['badge'], $x['badge_color']]);
        return self::dialOut(Db::one('SELECT * FROM dials WHERE id = ?', [Db::insertId()]));
    }

    private static function dialUpdate(int $id, array $in): never
    {
        $cur = Db::one('SELECT * FROM dials WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Not found');
        $group = (int) ($in['group_id'] ?? $cur['group_id']);
        if (!self::groupExists($group)) {
            throw new InvalidArgumentException('Gruppe unbekannt.');
        }
        $url = array_key_exists('url', $in) ? self::normalizeUrl($in['url']) : $cur['url'];
        $title = array_key_exists('title', $in) ? self::title($in['title']) : $cur['title'];
        $mode = in_array($in['thumb_mode'] ?? $cur['thumb_mode'], self::THUMB_MODES, true) ? ($in['thumb_mode'] ?? $cur['thumb_mode']) : $cur['thumb_mode'];
        $src = $mode === 'url' ? self::normalizeUrl($in['thumb_src'] ?? $cur['thumb_src'] ?? '') : null;
        $fit = in_array($in['fit'] ?? $cur['fit'], ['auto', 'cover', 'contain'], true) ? ($in['fit'] ?? $cur['fit']) : 'auto';
        $noCheck = array_key_exists('no_check', $in) ? ($in['no_check'] ? 1 : 0) : (int) $cur['no_check'];
        $x = self::extras($in, $cur);

        $status = $cur['thumb_status'];
        $changed = $url !== $cur['url'] || $mode !== $cur['thumb_mode'] || $src !== $cur['thumb_src'];
        if ($mode === 'none') {
            Thumbs::deleteFile($cur['thumb_file']);
            Db::run('UPDATE dials SET thumb_file = NULL, thumb_kind = NULL WHERE id = ?', [$id]);
            $status = 'none';
        } elseif ($mode === 'upload') {
            $status = $cur['thumb_file'] ? 'ok' : 'none';
        } elseif ($changed) {
            $status = 'pending';
        }
        $moved = $group !== (int) $cur['group_id'];
        $pos = $moved ? (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM dials WHERE group_id = ?', [$group])['p'] : $cur['position'];

        $section = (int) $cur['section_id'];
        if ($moved) {
            $section = Sections::defaultId($group);
        } elseif (isset($in['section_id']) && Sections::belongs((int) $in['section_id'], $group)) {
            $section = (int) $in['section_id'];
        }
        Db::run('UPDATE dials SET section_id = ? WHERE id = ?', [$section, $id]);
        Db::run('UPDATE dials SET group_id=?, position=?, title=?, url=?, thumb_mode=?, thumb_src=?, fit=?, thumb_status=?, thumb_attempts=0, no_check=? WHERE id=?',
            [$group, $pos, $title, $url, $mode, $src, $fit, $status, $noCheck, $id]);
        Db::run('UPDATE dials SET tags=?, note=?, color=?, badge=?, badge_color=? WHERE id=?', [$x['tags'], $x['note'], $x['color'], $x['badge'], $x['badge_color'], $id]);
        if ($url !== $cur['url']) {
            Db::run('UPDATE dials SET check_status = NULL, check_reason = NULL, check_code = NULL, check_at = NULL, check_fails = 0, meta_desc = NULL WHERE id = ?', [$id]);
        }
        if ($url !== $cur['url'] || $noCheck !== (int) $cur['no_check']) {
            LinkCheck::reapplyExclusions($id);
        }
        Http::json(self::dialOut(Db::one('SELECT * FROM dials WHERE id = ?', [$id])));
    }

    private static function dialDelete(int $id): never
    {
        $cur = Db::one('SELECT thumb_file FROM dials WHERE id = ?', [$id]);
        if ($cur) {
            Thumbs::deleteFile($cur['thumb_file']);
            Db::run('DELETE FROM dials WHERE id = ?', [$id]);
        }
        Http::json(['ok' => true]);
    }

    private static function dialBulkDelete(array $in): never
    {
        foreach ((array) ($in['ids'] ?? []) as $id) {
            $cur = Db::one('SELECT thumb_file FROM dials WHERE id = ?', [(int) $id]);
            if ($cur) {
                Thumbs::deleteFile($cur['thumb_file']);
                Db::run('DELETE FROM dials WHERE id = ?', [(int) $id]);
            }
        }
        Http::json(['ok' => true]);
    }

    private static function backupDownload(string $name): never
    {
        $file = Backup::path($name) ?? throw new HttpError(404, 'Not found');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-store');
        readfile($file);
        exit;
    }

    // ---------- second factor ----------

    /** Changes to the second factor need the current password again. */
    private static function mfaPassword(array $session): void
    {
        Mfa::checkPassword((int) $session['user_id'], (string) (Http::body()['password'] ?? ''));
    }

    /**
     * After a factor or passkey was added: other sessions end; TOTP comes with a set of recovery codes (returned once).
     * @return list<string>|null
     */
    private static function mfaEnabled(array $session): ?array
    {
        $uid = (int) $session['user_id'];
        Auth::logoutOthers((int) $session['id']);
        return Mfa::enabled($uid) && Mfa::status($uid)['recovery'] === 0 ? Mfa::recoveryGenerate($uid) : null;
    }

    private static function mfaStatus(array $session, ?array $newCodes = null): array
    {
        $uid = (int) $session['user_id'];
        $s = Mfa::status($uid);
        return ['totp' => $s['totp'], 'passkeys' => Mfa::passkeys($uid), 'recovery_left' => $s['recovery'], 'recovery_codes' => $newCodes, 'rp_id' => WebAuthn::rpId()];
    }

    private static function dialKeep(array $in): never
    {
        foreach (array_slice((array) ($in['ids'] ?? []), 0, 2000) as $id) {
            Db::run('UPDATE dials SET keep = ? WHERE id = ?', [empty($in['keep']) ? 0 : 1, (int) $id]);
        }
        Http::json(['ok' => true]);
    }

    private static function dialOrder(array $in): never
    {
        $group = (int) ($in['group_id'] ?? 0);
        if (!self::groupExists($group)) {
            throw new InvalidArgumentException('Gruppe unbekannt.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $default = Sections::defaultId($group);
        foreach (array_values((array) ($in['ids'] ?? [])) as $i => $id) {
            Db::run('UPDATE dials SET group_id = ?, position = ?, section_id = CASE WHEN section_id IN (SELECT id FROM sections WHERE group_id = ?) THEN section_id ELSE ? END WHERE id = ?',
                [$group, $i, $group, $default, (int) $id]);
        }
        $pdo->commit();
        Http::json(['ok' => true]);
    }

    private static function dialRefresh(int $id): never
    {
        $cur = Db::one('SELECT * FROM dials WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Not found');
        $mode = in_array($cur['thumb_mode'], ['upload', 'none'], true) ? 'auto' : $cur['thumb_mode'];
        Db::run("UPDATE dials SET thumb_mode = ?, thumb_status = 'pending', thumb_attempts = 0 WHERE id = ?", [$mode, $id]);
        Http::json(['ok' => true]);
    }

    private static function queueChecks(array $in): int
    {
        if (isset($in['ids'])) {
            $n = 0;
            foreach (array_slice((array) $in['ids'], 0, 2000) as $id) {
                $n += Db::run("UPDATE dials SET check_req = 1 WHERE id = ? AND check_req = 0", [(int) $id]);
            }
            return $n;
        }
        return Db::run('UPDATE dials SET check_req = 1 WHERE check_req = 0');
    }

    private static function queueMissing(): int
    {
        return Db::run("UPDATE dials SET thumb_status = 'pending', thumb_attempts = 0
                        WHERE thumb_file IS NULL AND thumb_mode IN ('auto','screenshot','url') AND thumb_status IN ('none','failed')");
    }

    private static function uploadedBytes(): string
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > self::UPLOAD_LIMIT) {
            throw new InvalidArgumentException('Upload fehlgeschlagen oder Datei zu groß (max. 12 MB).');
        }
        return (string) file_get_contents($f['tmp_name']);
    }

    private static function dialUpload(int $id): never
    {
        $cur = Db::one('SELECT * FROM dials WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Not found');
        try {
            [$file, $kind] = Thumbs::ingest(self::uploadedBytes());
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException('Kein gültiges Bild.');
        }
        Thumbs::deleteFile($cur['thumb_file']);
        Db::run("UPDATE dials SET thumb_mode='upload', thumb_src=NULL, thumb_file=?, thumb_kind=?, thumb_status='ok', thumb_error=NULL WHERE id=?",
            [$file, $kind, $id]);
        Http::json(self::dialOut(Db::one('SELECT * FROM dials WHERE id = ?', [$id])));
    }

    // ---------- settings / background ----------

    private static function settingsUpdate(array $in): never
    {
        foreach ($in as $key => $value) {
            Settings::set((string) $key, $value);
        }
        if (array_key_exists('check_exclude', $in) || array_key_exists('check_skip_private', $in)) {
            LinkCheck::reapplyExclusions();
        }
        Http::json(['ok' => true, 'check_exclude' => Settings::get('check_exclude')]);
    }

    private static function backgroundUpload(string $theme): never
    {
        $bytes = self::uploadedBytes();
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] * $info[1] > 40_000_000) {
            throw new InvalidArgumentException('Kein gültiges Bild.');
        }
        $im = @imagecreatefromstring($bytes) ?: throw new InvalidArgumentException('Bildformat nicht unterstützt.');
        $im = Thumbs::scaleDown($im, 2560, 1600);
        Settings::setBackgroundFile($theme, Thumbs::store($im, true));
        Http::json(['ok' => true, 'url' => Http::url('/media/' . Settings::get('bg_' . $theme)['file'])]);
    }

    // ---------- media / import ----------

    public static function media(string $name): never
    {
        if (!preg_match('/^[a-f0-9]{24}\.(webp|png|jpg)$/', $name, $m)) {
            throw new HttpError(404, 'Not found');
        }
        $file = Db::mediaDir() . '/' . $name;
        if (!is_file($file)) {
            throw new HttpError(404, 'Not found');
        }
        header('Content-Type: ' . ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$m[1]]);
        header('Cache-Control: private, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    private static function import(): never
    {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new InvalidArgumentException('Upload fehlgeschlagen.');
        }
        $replace = ($_POST['mode'] ?? 'replace') === 'replace';
        try {
            $result = Importer::fromFile($f['tmp_name'], $replace);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }
        LinkCheck::reapplyExclusions();
        Http::json(['ok' => true] + $result);
    }
}
