<?php

// Static GET/HEAD only: never bootstrap Laravel or read this checkout's .env.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
require __DIR__.'/tekora-w3-preview-key.php';
$root = getenv('TEKORA_W3_PREVIEW_DIR');
$safety = $root && is_file($root.'/safety.json') ? json_decode(file_get_contents($root.'/safety.json'), true) : null;
if (! $safety || $safety['environment'] !== 'testing' || $safety['database'] !== ':memory:' || $safety['environment_file_exists'] || $safety['post_backend']) {
    http_response_code(503);
    echo 'Export the memory-only W3 preview first.';

    return;
}
if (! in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo 'Static visual preview only. Do not enter credentials.';

    return;
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
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
$key = tekoraMarketplacePreviewKey($_SERVER['REQUEST_URI']);
$redirects = json_decode(file_get_contents($root.'/redirects.json'), true);
if (isset($redirects[$key]) && str_starts_with($redirects[$key], '/') && ! str_starts_with($redirects[$key], '//')) {
    header('Location: '.$redirects[$key], true, 302);

    return;
}
$routes = json_decode(file_get_contents($root.'/routes.json'), true);
$file = $key !== null ? ($routes[$key] ?? null) : null;
if (! is_string($file) || ! preg_match('/^[a-z0-9-]+\.html$/D', $file) || ! is_file($root.'/'.$file)) {
    http_response_code(404);
    echo 'This query is not exported. Use the fixture queries in W3-LARAGON.md; this preview has no live backend.';

    return;
}
$status = json_decode(file_get_contents($root.'/status-codes.json'), true);
http_response_code($status[$key] ?? 200);
foreach (json_decode(file_get_contents($root.'/headers.json'), true) as $name => $value) {
    if ($value && in_array($name, ['Content-Security-Policy', 'X-Content-Type-Options'], true)) {
        header($name.': '.$value);
    }
}
header('Content-Type: text/html; charset=utf-8');
header('X-Tekora-Preview: synthetic-static-memory-export');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    readfile($root.'/'.$file);
}
