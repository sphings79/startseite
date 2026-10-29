<?php
declare(strict_types=1);

/**
 * Full backup as ZIP: data.json (groups, dials, settings) plus all media files.
 */
final class Exporter
{
    public const FORMAT = 'startseite';

    /** @return array<string,mixed> */
    public static function snapshot(): array
    {
        Sections::repair();
        $dials = Db::all('SELECT group_id, section_id, position, title, url, thumb_mode, thumb_src, thumb_file, thumb_kind, fit, visits, last_visit, created_at, no_check, tags, note, color, badge, badge_color, keep
                          FROM dials ORDER BY group_id, position, id');
        foreach ($dials as &$d) {
            $d['tags'] = json_decode((string) $d['tags'], true) ?: [];
        }
        unset($d);
        return [
            'format'      => self::FORMAT,
            'version'     => 2,
            'exported_at' => time(),
            'groups'      => Db::all('SELECT id, title, position, no_check FROM groups ORDER BY position, id'),
            'sections'    => Db::all('SELECT id, group_id, position, title, title_center, collapsed, width, mode, cols, tile_size, ratio, bg_color, bg_opacity, border_color, border_width, radius, padding FROM sections ORDER BY group_id, position, id'),
            'dials'       => $dials,
            'settings'    => Settings::all(),
        ];
    }

    /** Writes the full backup archive to $path. */
    public static function buildZip(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create archive');
        }
        $data = self::snapshot();
        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $files = array_filter(array_column($data['dials'], 'thumb_file'));
        foreach (['bg_light', 'bg_dark'] as $k) {
            $files[] = $data['settings'][$k]['file'] ?? '';
        }
        foreach (array_unique(array_filter($files)) as $f) {
            $path = Db::mediaDir() . '/' . $f;
            if (preg_match('/^[a-f0-9]{24}\.(webp|png|jpg|svg)$/', $f) && is_file($path)) {
                $zip->addFile($path, 'media/' . $f);
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('Cannot write archive');
        }
    }

    public static function download(): never
    {
        $tmp = Config::get('data_dir') . '/tmp/export-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            self::buildZip($tmp);
        } catch (RuntimeException $e) {
            throw new HttpError(500, $e->getMessage());
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="startseite-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
