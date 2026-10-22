<?php

// Aislar las pruebas incluso si el equipo tiene variables o caché de desarrollo.
// No modifica .env; la conexión de pruebas nunca debe ser persistente.
$testEnvironment = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/tekora-phpunit-config-'.bin2hex(random_bytes(8)).'.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'SESSION_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
];

foreach ($testEnvironment as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require dirname(__DIR__).'/vendor/autoload.php';
