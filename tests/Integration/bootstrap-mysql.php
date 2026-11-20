<?php

// Separate from tests/bootstrap.php and the normal SQLite suite. No .env import.
if (getenv('TEKORA_MYSQL_LAB_ENABLE') !== '1' || ! is_file('/run/tekora-lab.json')) {
    throw new RuntimeException('BLOCKED: explicit disposable-container lab configuration required');
}
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_CONFIG_CACHE' => '/tmp/w62cb-config-'.bin2hex(random_bytes(8)).'.php',
    'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
