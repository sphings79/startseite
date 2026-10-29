<?php
declare(strict_types=1);

/** In-process tests of the building blocks. Run through tests/run.php (needs STARTSEITE_DATA_DIR). */
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/src/bootstrap.php';

T::section('WebAuthn');
T::check('base64url round trip', WebAuthn::unb64u(WebAuthn::b64u("\x00\xff\xfe abc")) === "\x00\xff\xfe abc");
T::throws('invalid base64url is rejected', fn () => WebAuthn::unb64u('***'), RuntimeException::class);
$off = 0;
T::check('CBOR map with int keys', WebAuthn::cbor("\xa2\x01\x02\x03\x26", $off) === [1 => 2, 3 => -7]);
$off = 0;
T::throws('truncated CBOR is rejected', function () use (&$off) { WebAuthn::cbor("\x58\x20abc", $off); }, RuntimeException::class);
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$ec = openssl_pkey_get_details($key)['ec'];
$cose = [1 => 2, 3 => -7, -1 => 1, -2 => $ec['x'], -3 => $ec['y']];
$pem = WebAuthn::coseToPem($cose);
$data = random_bytes(37);
$cdj = '{"type":"webauthn.get"}';
openssl_sign($data . hash('sha256', $cdj, true), $sig, $key, OPENSSL_ALGO_SHA256);
T::check('ES256 signature verifies', WebAuthn::verifySignature($data, $cdj, $sig, $pem));
T::check('tampered data fails', !WebAuthn::verifySignature($data . 'x', $cdj, $sig, $pem));
T::throws('non-ES256 key is rejected', fn () => WebAuthn::coseToPem([1 => 3, 3 => -257]), RuntimeException::class);

T::section('SVG detection');
T::check('plain SVG is detected', Thumbs::isSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>'));
T::check('SVG with XML prolog and BOM is detected', Thumbs::isSvg("\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- c -->\n<svg xmlns=\"x\"/>"));
T::check('HTML is not SVG', !Thumbs::isSvg('<html><body><svg></svg></body></html>'));
T::check('PNG bytes are not SVG', !Thumbs::isSvg("\x89PNG\r\n\x1a\n"));

T::section('Passwords and URLs');
T::check('short password rejected', Auth::validatePassword('short') !== null);
T::check('long password accepted', Auth::validatePassword('a-long-enough-password-123') === null);
T::check('bare host gets https', Api::normalizeUrl('example.org/a') === 'https://example.org/a');
T::throws('javascript: URL rejected', fn () => Api::normalizeUrl('javascript:alert(1)'), InvalidArgumentException::class);
T::throws('ftp: URL rejected', fn () => Api::normalizeUrl('ftp://example.org'), InvalidArgumentException::class);

T::section('Link check rules');
T::check('exact host rule', LinkCheck::ruleMatches('proxy.example.de', 'proxy.example.de'));
T::check('wildcard rule', LinkCheck::ruleMatches('a.intern.example.de', '*.intern.example.de'));
T::check('wildcard does not match other domain', !LinkCheck::ruleMatches('example.org', '*.intern.example.de'));
T::check('CIDR rule', LinkCheck::ruleMatches('10.20.1.5', '10.20.0.0/16'));
T::check('CIDR rule excludes outside', !LinkCheck::ruleMatches('10.21.0.1', '10.20.0.0/16'));
T::check('local hosts are detected', LinkCheck::isLocalHost('192.168.1.10') && LinkCheck::isLocalHost('nas.local') && LinkCheck::isLocalHost('fritz.box') && LinkCheck::isLocalHost('printer'));
T::check('public host is not local', !LinkCheck::isLocalHost('example.org') && !LinkCheck::isLocalHost('8.8.8.8'));

T::section('Groups, sections and dials');
Db::run("INSERT INTO groups (title, position) VALUES ('Home', 0), ('Work', 1)");
Sections::repair();
$home = (int) Db::one("SELECT id FROM groups WHERE title = 'Home'")['id'];
$work = (int) Db::one("SELECT id FROM groups WHERE title = 'Work'")['id'];
T::check('every group gets a default section', (int) Db::one('SELECT COUNT(*) c FROM sections')['c'] === 2);
$def = Sections::defaultId($home);
foreach ([['https://a.example', 'A'], ['https://b.example', 'B'], ['https://c.example', 'C']] as $i => [$u, $t]) {
    Db::run("INSERT INTO dials (group_id, section_id, position, title, url, created_at) VALUES (?,?,?,?,?,?)", [$home, $def, $i, $t, $u, time()]);
}
$s2 = Sections::create(['group_id' => $home, 'title' => 'Media', 'width' => 50, 'mode' => 'list', 'cols' => 2, 'bg_color' => '#1971c2', 'bg_opacity' => 30]);
T::check('section is created with its values', $s2['title'] === 'Media' && $s2['mode'] === 'list' && $s2['cols'] === 2 && $s2['width'] === 50);
T::throws('invalid width rejected', fn () => Sections::create(['group_id' => $home, 'width' => 42]), InvalidArgumentException::class);
T::throws('invalid colour rejected', fn () => Sections::create(['group_id' => $home, 'bg_color' => 'red']), InvalidArgumentException::class);
T::throws('too many columns rejected', fn () => Sections::create(['group_id' => $home, 'cols' => 9]), InvalidArgumentException::class);
T::throws('unknown group rejected', fn () => Sections::create(['group_id' => 9999]), InvalidArgumentException::class);
$ids = array_column(Db::all('SELECT id FROM dials ORDER BY position'), 'id');
Sections::layout(['group_id' => $home, 'sections' => [['id' => $def, 'ids' => [$ids[0]]], ['id' => $s2['id'], 'ids' => [$ids[2], $ids[1]]]]]);
$placed = Db::all('SELECT title, section_id FROM dials ORDER BY position');
T::check('layout moves dials between sections and sets order', array_column($placed, 'title') === ['A', 'C', 'B'] && (int) $placed[1]['section_id'] === $s2['id']);
T::throws('layout with a foreign section is rejected', fn () => Sections::layout(['group_id' => $home, 'sections' => [['id' => Sections::defaultId($work), 'ids' => [$ids[0]]]]]), InvalidArgumentException::class);
Sections::order(['group_id' => $home, 'ids' => [$s2['id'], $def]]);
T::check('sections can be reordered', (int) Db::one('SELECT id FROM sections WHERE group_id = ? ORDER BY position LIMIT 1', [$home])['id'] === $s2['id']);
Sections::delete($s2['id']);
T::check('deleting a section moves its dials to another one', (int) Db::one('SELECT COUNT(*) c FROM dials WHERE section_id = ?', [$def])['c'] === 3);
T::throws('the last section of a tab cannot be deleted', fn () => Sections::delete($def), InvalidArgumentException::class);
Db::run('UPDATE dials SET section_id = NULL WHERE group_id = ?', [$home]);
Sections::repair();
T::check('repair assigns dials without a section', Db::one('SELECT 1 FROM dials WHERE section_id IS NULL') === null);

T::section('Icons and cropping');
$icon = Thumbs::normalizeIcon(['name' => 'home', 'd' => 'M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z', 'fg' => '#FFFFFF', 'bg' => '#2563eb']);
T::check('icon request is normalised', $icon['fg'] === '#ffffff' && $icon['name'] === 'home');
T::throws('icon with script-like path is refused', fn () => Thumbs::normalizeIcon(['name' => 'x', 'd' => 'M0 0"/><script>', 'fg' => '#ffffff', 'bg' => '#000000']), InvalidArgumentException::class);
T::throws('icon with bad colour is refused', fn () => Thumbs::normalizeIcon(['name' => 'x', 'd' => 'M0 0h4v4z', 'fg' => 'red', 'bg' => '#000000']), InvalidArgumentException::class);
T::check('generated icon SVG passes the backup check', Thumbs::isIconSvg(Thumbs::iconSvg($icon)));
T::check('foreign SVG is refused in backups', !Thumbs::isIconSvg('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'));
[$iconFile, $iconKind] = Thumbs::storeIcon($icon);
T::check('icon is stored as a .svg file', str_ends_with($iconFile, '.svg') && is_file(Db::mediaDir() . '/' . $iconFile) && $iconKind === 'logo');
$im = imagecreatetruecolor(200, 100);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
ob_start(); imagepng($im); [$imgFile, $imgKind] = Thumbs::ingest((string) ob_get_clean(), 'shot');
Db::run("INSERT INTO dials (group_id, section_id, position, title, url, thumb_mode, thumb_file, thumb_kind, thumb_status, created_at) VALUES (?,?,?,?,?, 'upload', ?, ?, 'ok', ?)",
    [$home, $def, 9, 'Cropme', 'https://crop.example', $imgFile, $imgKind, time()]);
$cid = (int) Db::insertId();
Thumbs::crop($cid, 0.25, 0.0, 0.5, 1.0);
$c = Db::one('SELECT * FROM dials WHERE id = ?', [$cid]);
$cut = getimagesize(Db::mediaDir() . '/' . $c['thumb_file']);
T::check('crop makes a new file and keeps the original', $c['thumb_file'] !== $imgFile && $c['thumb_orig'] === $imgFile && is_file(Db::mediaDir() . '/' . $imgFile));
T::check('cropped image has the chosen size', $cut[0] === 100 && $cut[1] === 100, $cut[0] . 'x' . $cut[1]);
Thumbs::crop($cid, 0.0, 0.0, 0.5, 0.5);
$c2 = Db::one('SELECT * FROM dials WHERE id = ?', [$cid]);
T::check('cropping again starts from the original', $c2['thumb_orig'] === $imgFile && !is_file(Db::mediaDir() . '/' . $c['thumb_file']));
T::throws('invalid crop rectangle is refused', fn () => Thumbs::crop($cid, 0.8, 0.0, 0.5, 1.0), InvalidArgumentException::class);
Thumbs::resetCrop($cid);
$c3 = Db::one('SELECT * FROM dials WHERE id = ?', [$cid]);
T::check('reset restores the original', $c3['thumb_file'] === $imgFile && $c3['thumb_orig'] === null && $c3['thumb_crop'] === null);
Db::run('DELETE FROM dials WHERE id = ?', [$cid]);

T::section('Backup round trip');
Sections::update($def, ['title' => 'Main', 'border_width' => 3, 'border_color' => '#ff0000']);
$before = [Db::all('SELECT title, width, border_width, border_color FROM sections ORDER BY group_id, position'),
    Db::all('SELECT d.title, s.title AS section FROM dials d JOIN sections s ON s.id = d.section_id ORDER BY d.group_id, d.position')];
$zip = sys_get_temp_dir() . '/startseite-test-' . bin2hex(random_bytes(4)) . '.zip';
Exporter::buildZip($zip);
$result = Importer::fromFile($zip, true);
unlink($zip);
$after = [Db::all('SELECT title, width, border_width, border_color FROM sections ORDER BY group_id, position'),
    Db::all('SELECT d.title, s.title AS section FROM dials d JOIN sections s ON s.id = d.section_id ORDER BY d.group_id, d.position')];
T::check('import reports groups and dials', $result['groups'] === 2 && $result['dials'] === 3);
T::check('sections and placement survive export and import', $before === $after);

echo "\n";
exit(T::$fail > 0 ? 1 : 0);
