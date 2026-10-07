<?php

/*
 * Router for PHP's built-in web server (local development only, never used in production):
 *
 *   php -S 127.0.0.1:8000 -t public bin/dev-router.php
 *
 * Serves existing files from public/ directly and sends everything else (including /robots.txt,
 * /sitemap.xml and /audio/*.mp3, which are Symfony routes) to the front controller.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__.'/../public'.$path;

if ('/' !== $path && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__.'/../public/index.php';
