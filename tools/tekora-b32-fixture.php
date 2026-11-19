<?php

/**
 * Run B3.2's private SQLite exporter in a genuine PHPUnit test lifecycle.
 * Do not construct PHPUnit TestCase manually from a CLI script.
 */
$root = dirname(__DIR__);
if (PHP_SAPI !== 'cli' || is_file($root.'/vendor/bin/phpunit') === false
    || getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('DB_DATABASE') !== ':memory:' || (getenv('DB_URL') ?: '') !== ''
    || is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "B3.2 requiere CLI, dependencias y SQLite :memory: en un checkout aislado sin .env.\n");
    exit(1);
}

$output = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'tekora-b32-report-'.bin2hex(random_bytes(12)).'.json';
$process = null;
try {
    $command = [
        PHP_BINARY,
        $root.'/vendor/bin/phpunit',
        '--no-progress',
        '--filter',
        'test_exports_private_sqlite_only_when_requested_by_the_isolated_launcher',
        $root.'/tests/Feature/LocalIntegratedCheckoutFixtureExportTest.php',
    ];
    $env = getenv();
    $env['TEKORA_B32_FIXTURE_OUTPUT'] = $output;

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $root, $env);
    if (is_resource($process) === false) {
        throw new RuntimeException('No se pudo iniciar PHPUnit para crear la base sintética.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $process = null;
    if ($exitCode !== 0 || is_file($output) === false || is_link($output)) {
        throw new RuntimeException('El exportador PHPUnit falló ('.$exitCode.").\n".$stdout."\n".$stderr);
    }

    $json = file_get_contents($output);
    $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $database = $fixture['database'] ?? null;
    $tempRoot = realpath(sys_get_temp_dir());
    if (is_string($database) === false || is_file($database) === false || is_link($database)
        || str_starts_with(str_replace('\\', '/', realpath($database)), str_replace('\\', '/', $tempRoot).'/tekora-b32-') === false
        || is_file(dirname($database).DIRECTORY_SEPARATOR.'.tekora-b32-synthetic') === false) {
        throw new RuntimeException('La base exportada no cumple el aislamiento B3.2.');
    }

    echo $json."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'B3.2 fixture fallida: '.$error->getMessage()."\n");
    exit(1);
} finally {
    if (is_file($output) && is_link($output) === false) {
        unlink($output);
    }
}
