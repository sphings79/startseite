<?php
declare(strict_types=1);

// The deploy script may place app_path.php next to this file to point at the code outside the web root.
$appRoot = is_file(__DIR__ . '/app_path.php') ? (string) require __DIR__ . '/app_path.php' : dirname(__DIR__);
define('WEB_ROOT', __DIR__);
require $appRoot . '/src/bootstrap.php';

ini_set('display_errors', '0');
Http::securityHeaders();

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = Http::path();

    if ($path === '/login') {
        require APP_ROOT . '/src/views/login_controller.php';
        exit;
    }
    if ($path === '/login/passkey/options' || $path === '/login/passkey/verify') {
        require APP_ROOT . '/src/views/login_passkey.php';
        exit;
    }

    if ($path === '/manifest.webmanifest') {
        header('Content-Type: application/manifest+json');
        header('Cache-Control: no-cache');
        $name = (string) Settings::get('app_name');
        echo json_encode([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12),
            'start_url' => Http::url('/'), 'scope' => Http::url('/'), 'display' => 'standalone',
            'background_color' => '#eeede8', 'theme_color' => '#eeede8',
            'share_target' => [
                'action' => Http::url('/add'), 'method' => 'GET',
                'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
            ],
            'icons' => [
                ['src' => Http::url('/icons/192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => Http::url('/icons/512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (preg_match('#^/icons/(192|512)\.png$#', $path, $m)) {
        $size = (int) $m[1];
        $im = imagecreatetruecolor($size, $size);
        $accent = sscanf((string) Settings::get('accent'), '#%02x%02x%02x');
        imagefill($im, 0, 0, imagecolorallocate($im, ...$accent));
        $white = imagecolorallocate($im, 255, 255, 255);
        $s = (int) ($size * 0.12);
        $gap = (int) ($size * 0.06);
        $cell = (int) (($size - 2 * $size * 0.22 - $gap) / 2);
        $x0 = (int) ($size * 0.22);
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$cx, $cy]) {
            $x = $x0 + $cx * ($cell + $gap);
            $y = $x0 + $cy * ($cell + $gap);
            imagefilledrectangle($im, $x, $y, $x + $cell, $y + $cell, $white);
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        imagepng($im);
        exit;
    }

    $session = Auth::current();
    if ($session === null) {
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/media/')) {
            throw new HttpError(401, 'Unauthorized');
        }
        if ($path === '/add') {
            $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
            Http::redirect('/login?next=' . rawurlencode('/add' . ($q !== '' ? '?' . $q : '')));
        }
        Http::redirect('/login');
    }

    if (str_starts_with($path, '/api/')) {
        Api::dispatch($method, $path, $session);
    }
    if (str_starts_with($path, '/media/') && $method === 'GET') {
        Api::media(substr($path, 7));
    }
    if ($path === '/add' && $method === 'GET') {
        header('Cache-Control: no-store');
        // The page may be opened by a bookmarklet from a foreign site; cut the opener link so that site cannot steer this window.
        header('Cross-Origin-Opener-Policy: same-origin');
        $boot = Api::addBoot($session);
        require APP_ROOT . '/src/views/add.php';
        exit;
    }
    if ($path === '/' && $method === 'GET') {
        header('Cache-Control: no-store');
        $boot = Api::state($session);
        require APP_ROOT . '/src/views/app.php';
        exit;
    }
    throw new HttpError(404, 'Not found');
} catch (HttpError $e) {
    http_response_code($e->status);
    foreach ($e->headers as $k => $v) {
        header("$k: $v");
    }
    if (str_starts_with(Http::path(), '/api/') || str_starts_with(Http::path(), '/media/')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->status . ' ' . $e->getMessage();
    }
} catch (Throwable $e) {
    error_log('[startseite] ' . $e);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal error';
}
