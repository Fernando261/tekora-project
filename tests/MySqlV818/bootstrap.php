<?php

$root = dirname(__DIR__, 2);
$instance = getenv('TEKORA_V818_INSTANCE');
$private = getenv('TEKORA_V818_PRIVATE');
$socket = '/run/tekora-mysql/mysql.sock';
if (PHP_SAPI !== 'cli' || PHP_VERSION !== '8.3.37' || ! in_array($instance, ['source', 'target'], true)
    || ! $private || ! is_file($private.'/'.$instance.'-app.json') || ! file_exists($socket)
    || filetype($socket) !== 'socket' || file_exists($root.'/.env') || file_exists($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Se requiere contenedor PHP 8.3.37 y el socket del laboratorio privado.');
}

foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://tekora.example',
    'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => 'localhost', 'DB_DATABASE' => 'tekora',
    'DB_USERNAME' => 'tekora_app', 'DB_SOCKET' => $socket, 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATION' => 'utf8mb4_unicode_ci',
    'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

require_once $root.'/vendor/autoload.php';
