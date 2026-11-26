<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);

if (PHP_SAPI !== 'cli' || getenv('TEKORA_MYSQL_LAB') !== '1'
    || ! preg_match('/^tekora_v81_[a-f0-9]{16}$/D', getenv('DB_DATABASE') ?: '')
    || getenv('DB_SOCKET') !== '/run/tekora-mysql/mysql.sock' || ! file_exists(getenv('DB_SOCKET'))
    || file_exists($root.'/.env') || file_exists($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('MySQL V8.1 requires a new private Docker lab and the dedicated Unix socket.');
}

foreach ([
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_CONFIG_CACHE' => sys_get_temp_dir().'/tekora-v81-'.bin2hex(random_bytes(8)).'.php',
    'APP_KEY' => getenv('APP_KEY') ?: throw new RuntimeException('Missing lab key.'),
    'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => 'localhost',
    'DB_CHARSET' => 'utf8mb4', 'DB_COLLATION' => 'utf8mb4_unicode_ci',
    'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
] as $key => $value) {
    putenv($key.'='.$value);
    $_SERVER[$key] = $_ENV[$key] = $value;
}

require_once $root.'/vendor/autoload.php';

function mysqlV81Application(): Application
{
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    // Dotenv must never inspect other environment files in this test process.
    $app->useEnvironmentPath(sys_get_temp_dir());
    $app->loadEnvironmentFrom('tekora-v81-no-env-'.bin2hex(random_bytes(12)));
    $app->make(Kernel::class)->bootstrap();

    $parameters = DB::selectOne('SELECT VERSION() AS version, @@default_storage_engine AS engine,
        @@foreign_key_checks AS fk, @@sql_mode AS sql_mode, @@skip_networking AS no_tcp, @@session.time_zone AS timezone, DATABASE() AS db');
    if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql'
        || ! preg_match('/^8\.4\.\d+(?:-commercial)?$/D', $parameters->version)
        || $parameters->engine !== 'InnoDB' || (int) $parameters->fk !== 1 || (int) $parameters->no_tcp !== 1
        || ! str_contains($parameters->sql_mode, 'STRICT_TRANS_TABLES') || $parameters->db !== getenv('DB_DATABASE') || $parameters->timezone !== '+00:00') {
        throw new RuntimeException('MySQL 8.4/InnoDB lab identity or integrity requirements failed.');
    }

    return $app;
}

function mysqlV81MigrationPaths(): array
{
    $root = dirname(__DIR__, 2);
    $last = '2026_11_19_000000_add_reservation_slot_holds.php';
    $variants = [
        '2026_10_28_000002_create_service_inquiries_table.php',
        '2026_10_30_000000_create_service_inquiry_messages_table.php',
        '2026_10_30_000001_create_service_inquiry_reads_table.php',
        '2026_10_30_000002_create_provider_availability_tables.php',
        '2026_11_03_000000_create_service_reservations.php',
        '2026_11_03_000001_create_service_reviews.php',
        '2026_11_03_000002_create_activity_notifications.php',
        '2026_11_18_000000_create_web_pricing_and_quotes.php',
        $last,
    ];
    $files = array_filter(glob($root.'/database/migrations/*.php'), fn ($path) => basename($path) <= $last);

    return array_map(fn ($path) => in_array(basename($path), $variants, true) ? $root.'/database/mysql/migrations/'.basename($path) : $path, $files);
}

/** The lab owns this synthetic schema. Production has no reference to this override. */
function mysqlV81EnableBookingFixtureClock(): void
{
    if (getenv('TEKORA_MYSQL_LAB') !== '1' || DB::selectOne('SELECT DATABASE() AS db')->db !== getenv('DB_DATABASE')) {
        throw new RuntimeException('Fixture clock requires the private MySQL lab.');
    }
    $definition = (array) DB::selectOne('SHOW CREATE FUNCTION tekora_booking_now_us');
    if (str_contains($definition['Create Function'], '@tekora_v81_booking_us')) {
        return;
    }
    DB::statement('SET SESSION timestamp=946684800');
    DB::statement('SET @tekora_v81_booking_us=946684800000000');
    try {
        $time = (int) DB::selectOne('SELECT tekora_booking_now_us() AS micros')->micros;
        if ($time < (time() - 5) * 1000000 || $time > (time() + 5) * 1000000) {
            throw new RuntimeException('Production SQL clock accepted a session override.');
        }
    } finally {
        DB::statement('SET SESSION timestamp=DEFAULT');
        DB::statement('SET @tekora_v81_booking_us=NULL');
    }

    DB::unprepared('DROP FUNCTION tekora_booking_now_us');
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION tekora_booking_now_us() RETURNS BIGINT NOT DETERMINISTIC NO SQL
        BEGIN
            IF NOT REGEXP_LIKE(@@session.time_zone, '^[+-][0-9]{2}:[0-9]{2}$', 'c') THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'reservation_clock_timezone';
            END IF;
            RETURN COALESCE(@tekora_v81_booking_us, TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', CONVERT_TZ(SYSDATE(6), @@session.time_zone, '+00:00')));
        END
        SQL);
}
