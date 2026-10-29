<?php
// Router for PHP's built-in server: serves static files, everything else through public/index.php.
$root = dirname(__DIR__) . '/public';
$path = $root . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($path) && !str_ends_with($path, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
