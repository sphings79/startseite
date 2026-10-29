<?php
declare(strict_types=1);

/**
 * Sections inside a tab. Every dial belongs to exactly one section of its group; repair() keeps that true
 * after imports, group changes and anything that leaves section_id empty.
 */
final class Sections
{
    private const WIDTHS = [25, 33, 50, 67, 75, 100];
    private const MODES = ['inherit', 'tiles', 'list'];
    private const SIZES = ['', 's', 'm', 'l'];
    private const RATIOS = ['', '1:1', '4:3', '16:10', '16:9'];

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return array_map([self::class, 'out'], Db::all('SELECT * FROM sections ORDER BY group_id, position, id'));
    }

    public static function out(array $s): array
    {
        return [
            'id'           => (int) $s['id'],
            'group_id'     => (int) $s['group_id'],
            'position'     => (int) $s['position'],
            'title'        => $s['title'],
            'title_center' => (bool) $s['title_center'],
            'collapsed'    => (bool) $s['collapsed'],
            'width'        => (int) $s['width'],
            'mode'         => $s['mode'],
            'cols'         => (int) $s['cols'],
            'tile_size'    => $s['tile_size'],
            'ratio'        => $s['ratio'],
            'bg_color'     => $s['bg_color'],
            'bg_opacity'   => (int) $s['bg_opacity'],
            'border_color' => $s['border_color'],
            'border_width' => (int) $s['border_width'],
            'radius'       => (int) $s['radius'],
            'padding'      => (int) $s['padding'],
            'kind'         => $s['kind'],
        ];
    }

    /** The section that takes dials without a (valid) section: first one of the group, created if missing. */
    public static function defaultId(int $groupId): int
    {
        $row = Db::one('SELECT id FROM sections WHERE group_id = ? ORDER BY position, id LIMIT 1', [$groupId]);
        if ($row) {
            return (int) $row['id'];
        }
        Db::run('INSERT INTO sections (group_id, position, padding) VALUES (?, 0, 0)', [$groupId]);
        return Db::insertId();
    }

    public static function belongs(int $sectionId, int $groupId): bool
    {
        return Db::one('SELECT 1 FROM sections WHERE id = ? AND group_id = ?', [$sectionId, $groupId]) !== null;
    }

    /** Gives every group a section and every dial a section of its own group. */
    public static function repair(): void
    {
        foreach (Db::all('SELECT id FROM groups WHERE id NOT IN (SELECT group_id FROM sections)') as $g) {
            self::defaultId((int) $g['id']);
        }
        if (Db::one('SELECT 1 FROM dials WHERE section_id IS NULL OR section_id NOT IN (SELECT id FROM sections WHERE group_id = dials.group_id) LIMIT 1') === null) {
            return;
        }
        foreach (Db::all('SELECT DISTINCT group_id FROM dials WHERE section_id IS NULL OR section_id NOT IN (SELECT id FROM sections WHERE group_id = dials.group_id)') as $g) {
            Db::run('UPDATE dials SET section_id = ? WHERE group_id = ? AND (section_id IS NULL OR section_id NOT IN (SELECT id FROM sections WHERE group_id = ?))',
                [self::defaultId((int) $g['group_id']), (int) $g['group_id'], (int) $g['group_id']]);
        }
    }

    private static function color(mixed $v): string
    {
        $v = strtolower(trim((string) $v));
        if ($v !== '' && !preg_match('/^#[0-9a-f]{6}$/', $v)) {
            throw new InvalidArgumentException('Ungültige Farbe.');
        }
        return $v;
    }

    private static function intIn(mixed $v, int $min, int $max, string $what): int
    {
        if (!is_numeric($v) || (int) $v < $min || (int) $v > $max) {
            throw new InvalidArgumentException($what . ' muss zwischen ' . $min . ' und ' . $max . ' liegen.');
        }
        return (int) $v;
    }

    /**
     * Validated column values from a request body; absent keys keep the current value (or the default).
     * @return array<string,mixed>
     */
    private static function fields(array $in, array $cur): array
    {
        $out = $cur;
        if (array_key_exists('title', $in)) {
            $t = trim((string) $in['title']);
            if (mb_strlen($t) > 80) {
                throw new InvalidArgumentException('Titel ist zu lang.');
            }
            $out['title'] = $t;
        }
        foreach (['title_center', 'collapsed'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = empty($in[$k]) ? 0 : 1;
            }
        }
        if (array_key_exists('width', $in)) {
            if (!in_array((int) $in['width'], self::WIDTHS, true)) {
                throw new InvalidArgumentException('Ungültige Breite.');
            }
            $out['width'] = (int) $in['width'];
        }
        foreach ([['mode', self::MODES], ['tile_size', self::SIZES], ['ratio', self::RATIOS]] as [$k, $allowed]) {
            if (array_key_exists($k, $in)) {
                if (!in_array((string) $in[$k], $allowed, true)) {
                    throw new InvalidArgumentException('Ungültiger Wert für ' . $k . '.');
                }
                $out[$k] = (string) $in[$k];
            }
        }
        foreach (['bg_color', 'border_color'] as $k) {
            if (array_key_exists($k, $in)) {
                $out[$k] = self::color($in[$k]);
            }
        }
        $ranges = ['cols' => [1, 3, 'Spalten'], 'bg_opacity' => [0, 100, 'Deckkraft'], 'border_width' => [0, 8, 'Rahmenstärke'],
            'radius' => [0, 40, 'Eckenradius'], 'padding' => [0, 40, 'Innenabstand']];
        foreach ($ranges as $k => [$min, $max, $what]) {
            if (array_key_exists($k, $in)) {
                $out[$k] = self::intIn($in[$k], $min, $max, $what);
            }
        }
        return $out;
    }

    private const COLUMNS = ['title', 'title_center', 'collapsed', 'width', 'mode', 'cols', 'tile_size', 'ratio', 'bg_color', 'bg_opacity', 'border_color', 'border_width', 'radius', 'padding'];

    public static function create(array $in): array
    {
        $group = (int) ($in['group_id'] ?? 0);
        if (Db::one('SELECT 1 FROM groups WHERE id = ?', [$group]) === null) {
            throw new InvalidArgumentException('Gruppe unbekannt.');
        }
        $defaults = ['title' => '', 'title_center' => 0, 'collapsed' => 0, 'width' => 50, 'mode' => 'inherit', 'cols' => 1, 'tile_size' => '', 'ratio' => '',
            'bg_color' => '', 'bg_opacity' => 30, 'border_color' => '', 'border_width' => 0, 'radius' => 14, 'padding' => 14];
        $v = self::fields($in, $defaults);
        $pos = (int) Db::one('SELECT COALESCE(MAX(position), -1) + 1 AS p FROM sections WHERE group_id = ?', [$group])['p'];
        Db::run('INSERT INTO sections (group_id, position, ' . implode(', ', self::COLUMNS) . ') VALUES (?, ?, ' . implode(', ', array_fill(0, count(self::COLUMNS), '?')) . ')',
            array_merge([$group, $pos], array_map(static fn ($c) => $v[$c], self::COLUMNS)));
        return self::out(Db::one('SELECT * FROM sections WHERE id = ?', [Db::insertId()]));
    }

    public static function update(int $id, array $in): array
    {
        $cur = Db::one('SELECT * FROM sections WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Not found');
        $v = self::fields($in, $cur);
        Db::run('UPDATE sections SET ' . implode(' = ?, ', self::COLUMNS) . ' = ? WHERE id = ?',
            array_merge(array_map(static fn ($c) => $v[$c], self::COLUMNS), [$id]));
        return self::out(Db::one('SELECT * FROM sections WHERE id = ?', [$id]));
    }

    /** Dials of the deleted section move to the first remaining one; the last section of a tab stays. */
    public static function delete(int $id): void
    {
        $cur = Db::one('SELECT group_id FROM sections WHERE id = ?', [$id]) ?? throw new HttpError(404, 'Not found');
        if ((int) Db::one('SELECT COUNT(*) AS c FROM sections WHERE group_id = ?', [$cur['group_id']])['c'] <= 1) {
            throw new InvalidArgumentException('Der letzte Abschnitt eines Tabs kann nicht gelöscht werden.');
        }
        $target = (int) Db::one('SELECT id FROM sections WHERE group_id = ? AND id <> ? ORDER BY position, id LIMIT 1', [$cur['group_id'], $id])['id'];
        Db::run('UPDATE dials SET section_id = ? WHERE section_id = ?', [$target, $id]);
        Db::run('DELETE FROM sections WHERE id = ?', [$id]);
    }

    public static function order(array $in): void
    {
        $group = (int) ($in['group_id'] ?? 0);
        foreach (array_values((array) ($in['ids'] ?? [])) as $i => $id) {
            Db::run('UPDATE sections SET position = ? WHERE id = ? AND group_id = ?', [$i, (int) $id, $group]);
        }
    }

    /** Places dials into sections of one tab: [{id: sectionId, ids: [dialId, ...]}], positions run across the whole tab. */
    public static function layout(array $in): void
    {
        $group = (int) ($in['group_id'] ?? 0);
        if (Db::one('SELECT 1 FROM groups WHERE id = ?', [$group]) === null) {
            throw new InvalidArgumentException('Gruppe unbekannt.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        $pos = 0;
        foreach ((array) ($in['sections'] ?? []) as $sec) {
            $sid = (int) ($sec['id'] ?? 0);
            if (!self::belongs($sid, $group)) {
                $pdo->rollBack();
                throw new InvalidArgumentException('Abschnitt unbekannt.');
            }
            foreach ((array) ($sec['ids'] ?? []) as $did) {
                Db::run('UPDATE dials SET group_id = ?, section_id = ?, position = ? WHERE id = ?', [$group, $sid, $pos++, (int) $did]);
            }
        }
        $pdo->commit();
    }
}
