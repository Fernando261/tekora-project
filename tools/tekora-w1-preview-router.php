<?php

// php -S 127.0.0.1:8015 -t public tools/tekora-w1-preview-router.php
// Static exported HTML/assets only. Does not bootstrap Laravel or accept credentials.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$root = getenv('TEKORA_W1_PREVIEW_DIR');
if (! $root || ! is_dir($root)) {
    http_response_code(503);
    echo 'Export the memory-only preview first.';

    return;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo 'Static visual preview only. Do not enter credentials.';

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// The repository favicon is empty. Avoid an incidental browser 404 in static previews.
if ($path === '/favicon.ico') {
    http_response_code(204);

    return;
}
if (preg_match('#^/(build/assets/|fonts/tekora/|css/|js/|img/)[a-zA-Z0-9._/-]+\.(css|js|woff2|png|jpg|jpeg|svg|webp)$#D', $path)) {
    $public = realpath(dirname(__DIR__).'/public');
    $asset = realpath($public.$path);
    if ($asset && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && is_file($asset)) {
        return false;
    }
}
$routes = json_decode(file_get_contents($root.'/routes.json'), true);
$file = $routes[$_SERVER['REQUEST_URI']] ?? $routes[$path] ?? null;
if (! is_string($file) || ! preg_match('/^[a-z0-9-]+\.html$/D', $file) || ! is_file($root.'/'.$file)) {
    http_response_code(404);
    echo 'Visual preview: this link targets the real Laravel application.';

    return;
}
foreach (json_decode(file_get_contents($root.'/headers.json'), true) as $name => $value) {
    if ($value && in_array($name, ['Content-Security-Policy', 'X-Content-Type-Options'], true)) {
        header($name.': '.$value);
    }
}
header('Content-Type: text/html; charset=utf-8');
header('X-Tekora-Preview: synthetic-static-memory-export');
readfile($root.'/'.$file);
