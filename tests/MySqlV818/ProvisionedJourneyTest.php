<?php

namespace Tests\MySqlV818;

use App\Enums\InquiryAction;
use App\Enums\ReservationStatus;
use App\Enums\RoleName;
use App\Models\ServiceReservation;
use App\Models\User;
use App\Notifications\MobileIdentityCode;
use App\Payments\CommercialAmount;
use App\Payments\ServicePricing;
use App\Services\InquiryWorkflow;
use App\Services\ProviderAvailability;
use App\Services\ReservationWorkflow;
use App\Support\BookingClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatalogFixtures;

final class ProvisionedJourneyTest extends TestCase
{
    use CatalogFixtures;

    private const COMPLETED = 'V818 synthetic completed';

    private const LIVE = 'V818 synthetic live';

    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-v818-no-env-'.bin2hex(random_bytes(12)));
        $app->make(Kernel::class)->bootstrap();
        $path = getenv('TEKORA_V818_PRIVATE').'/'.getenv('TEKORA_V818_INSTANCE').'-app.json';
        config(['database.connections.mysql.password' => json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['password']]);
        $s = DB::selectOne('SELECT CURRENT_USER() AS actor, DATABASE() AS db, VERSION() AS version,
            @@skip_networking AS no_tcp, @@global.log_bin AS binlog, @@session.sql_log_bin AS session_binlog,
            @@global.binlog_format AS binlog_format, @@session.foreign_key_checks AS fk, @@session.time_zone AS tz');
        if ($s->actor !== 'tekora_app@localhost' || $s->db !== 'tekora' || $s->version !== '8.4.13'
            || (int) $s->no_tcp !== 1 || (int) $s->binlog !== 1 || (int) $s->session_binlog !== 1
            || $s->binlog_format !== 'ROW' || (int) $s->fk !== 1 || $s->tz !== '+00:00') {
            throw new \RuntimeException('La identidad restringida y los parámetros del laboratorio no coinciden.');
        }

        return $app;
    }

    public static function forbiddenSql(): iterable
    {
        yield 'database creation' => ['CREATE DATABASE forbidden_lab', 1044];
        yield 'schema modification' => ['CREATE TABLE forbidden_lab (id INT)', 1142];
        yield 'database deletion' => ['DROP DATABASE tekora', 1044];
        yield 'alternate schema' => ['SELECT COUNT(*) FROM mysql.user', 1142];
        yield 'global switches' => ['SET GLOBAL log_bin_trust_function_creators=ON', 1227];
        yield 'escalation' => ["GRANT ALL ON tekora.* TO 'tekora_app'@'localhost'", 1044];
        yield 'clock replacement' => ['DROP FUNCTION tekora_booking_now_us', 1370];
        yield 'trigger removal' => ['DROP TRIGGER hold_clock_monotonic', 1142];
        yield 'view removal' => ['DROP VIEW reservation_occupancy', 1142];
        yield 'migrator seal' => ['DELETE FROM tekora_provisioning', 1142];
        yield 'history mutation' => ['DELETE FROM reservation_events', 1142];
        yield 'binary log disabled' => ['SET SESSION sql_log_bin=OFF', 1227];
    }

    #[DataProvider('forbiddenSql')]
    public function test_runtime_account_cannot_administer_mysql(string $sql, int $code): void
    {
        try {
            DB::connection()->getPdo()->exec($sql);
            $this->fail('The runtime account executed a privileged statement.');
        } catch (PDOException $error) {
            $this->assertSame($code, $error->errorInfo[1] ?? null, $error->getMessage());
        }
    }

    public function test_ordinary_artisan_mysql_commands_stay_blocked(): void
    {
        foreach (['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe'] as $command) {
            try {
                $this->app->make(Kernel::class)->call($command, ['--database' => 'mysql', '--force' => true]);
                $this->fail('An ordinary MySQL migration command bypassed the guard.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('tekora:mysql:provision', $error->getMessage());
            }
        }

        $this->assertSame(1, DB::table('pricing_write_lock')->count());
    }

    public function test_identity_registration_sessions_recovery_and_checkout_boundary(): void
    {
        $this->withoutVite();
        $email = 'v818-'.Str::lower(Str::random(12)).'@example.test';
        $password = Str::random(28);
        $this->post('/registro', [
            'name' => 'Cliente sintético', 'age' => 28, 'gender' => 'Prefiero no decirlo',
            'email' => $email, 'password' => $password, 'password_confirmation' => $password, 'intent' => 'client',
        ])->assertRedirect('/login');
        $client = User::whereEmail($email)->sole();
        $this->post('/login', ['email' => $email, 'password' => $password])->assertRedirect('/cuenta');
        $this->get('/cuenta')->assertOk();
        $this->assertDatabaseHas('sessions', ['user_id' => $client->id]);

        $this->app['env'] = 'production';
        $this->get('/demo/checkout')->assertNotFound();
        $this->app['env'] = 'testing';
        auth()->forgetGuards();
        $headers = $this->bearer($client);
        $this->get('/api/v1/me/dashboard', $headers + ['Accept' => 'application/json'])->assertOk();
        auth()->forgetGuards();
        Notification::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertAccepted();
        $code = Notification::sent($client, MobileIdentityCode::class)->sole();
        $newPassword = Str::random(28);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'token' => $code->token, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk();
        $this->assertTrue(Hash::check($newPassword, $client->fresh()->password));
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $email]);
    }

    public function test_historical_journey_with_lab_clock(): void
    {
        $this->at(time() - 4 * 86400);
        [$provider, $inquiry, $reservation, $headers] = $this->journey(self::COMPLETED);
        $this->at(CarbonImmutable::parse($reservation->ends_at_utc, 'UTC')->getTimestamp() + 1);
        $this->assertSame(ReservationStatus::Completed, app(ReservationWorkflow::class)
            ->decide($provider, $reservation->id, 'complete', $this->action(2))->status);
        app(InquiryWorkflow::class)->decide($provider, $inquiry, InquiryAction::Complete);
        auth()->forgetGuards();
        $review = $this->postJson("/api/v1/reservations/{$reservation->id}/review", ['rating' => 5, 'comment' => 'Synthetic restored review'], $headers)
            ->assertCreated()->json('data.id');
        $this->assertDatabaseHas('activity_notifications', ['user_id' => $provider->id, 'service_review_id' => $review, 'event' => 'review_published']);
    }

    public function test_live_clock_journey(): void
    {
        DB::statement('SET SESSION timestamp=946684800');
        DB::statement('SET @tekora_v818_lab_clock=946684800000000');
        try {
            $sample = (int) DB::selectOne('SELECT tekora_booking_now_us() AS sampled')->sampled;
            $this->assertEqualsWithDelta(time() * 1000000, $sample, 5000000);
        } finally {
            DB::statement('SET SESSION timestamp=DEFAULT');
            DB::statement('SET @tekora_v818_lab_clock=NULL');
        }
        [, , $reservation] = $this->journey(self::LIVE);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    public function test_persisted_journey(): void
    {
        $this->withoutVite();
        foreach ([self::COMPLETED => 'completed', self::LIVE => 'confirmed'] as $label => $status) {
            $inquiry = DB::table('service_inquiries')->where('message', $label)->first();
            $this->assertNotNull($inquiry);
            $reservation = ServiceReservation::whereServiceInquiryId($inquiry->id)->sole();
            $this->assertSame($status, $reservation->status->value);
            $this->assertSame('committed', $reservation->hold->state);
            $this->assertSame(900000000, $reservation->hold->expires_at_us - $reservation->hold->accepted_at_us);
            $this->assertDatabaseHas('service_quotes', ['id' => $reservation->quote_id, 'total_cents' => 100010]);
            $this->assertDatabaseHas('quote_acceptances', ['quote_id' => $reservation->quote_id, 'client_user_id' => $inquiry->client_user_id]);
            $this->assertDatabaseHas('service_inquiry_messages', ['service_inquiry_id' => $inquiry->id]);
            $this->assertDatabaseHas('activity_notifications', ['service_reservation_id' => $reservation->id, 'event' => 'reservation_confirmed']);
            $this->assertSame(['held', 'committed'], $reservation->hold->events()->orderBy('version')->pluck('to_state')->all());
            $headers = $this->bearer(User::findOrFail($inquiry->client_user_id));
            $this->getJson("/api/v1/reservations/{$reservation->id}/checkout-context", $headers)->assertOk();
            if ($status === 'completed') {
                $this->assertDatabaseHas('service_reviews', ['service_reservation_id' => $reservation->id, 'rating' => 5]);
            }
            auth()->forgetGuards();
        }
        $watermark = (int) DB::table('reservation_hold_clock')->where('id', 1)->value('high_water_us');
        $this->assertGreaterThan(0, $watermark);
        $this->assertLessThanOrEqual(BookingClock::micros(), $watermark);
    }

    private function journey(string $label): array
    {
        $this->withoutVite();
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        app(ProviderAvailability::class)->save($provider, [
            'revision' => 0, 'timezone' => 'UTC', 'blocked_dates' => [],
            'intervals' => array_map(fn ($day) => ['day_of_week' => $day, 'start' => '08:00', 'end' => '19:00'], range(1, 7)),
        ]);
        app(ServicePricing::class)->publish($provider, $service->id, [
            'amount_cents' => 100010, 'expected_version' => 0, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('1,000.10');
        $headers = $this->bearer($client);
        $create = $this->postJson("/api/v1/services/{$service->id}/inquiries", ['message' => $label], $headers)->assertCreated();
        $id = $create->json('data.id');
        app(InquiryWorkflow::class)->decide($provider, $id, InquiryAction::Accept);
        $url = "/api/v1/inquiries/{$id}";
        $this->postJson($url.'/messages', ['body' => 'Synthetic message', 'idempotency_key' => (string) Str::uuid()], $headers)->assertCreated();
        $this->getJson($url.'/messages', $headers)->assertOk();
        $quote = $this->postJson($url.'/quotes', ['idempotency_key' => (string) Str::uuid()], $headers)->assertOk()->json('data.id');
        $this->postJson("/api/v1/quotes/{$quote}/accept", [], $headers)->assertUnprocessable();
        $this->postJson("/api/v1/quotes/{$quote}/accept", ['acknowledge' => true], $headers)->assertOk()
            ->assertJsonPath('data.acceptance.state', 'accepted');
        $this->getJson($url.'/reservation-options', $headers)->assertOk()
            ->assertJsonPath('data.commercial.accepted_quotes.0.total_cents', 100010);
        $proposal = ['date' => BookingClock::date(BookingClock::micros())->addDay()->toDateString(), 'time' => '09:00',
            'availability_revision' => 1, 'idempotency_key' => (string) Str::uuid(), 'quote_id' => $quote];
        $idReservation = $this->postJson($url.'/reservations', $proposal, $headers)->assertCreated()->json('data.id');
        $held = app(ReservationWorkflow::class)->decide($provider, $idReservation, 'confirm', $this->action());
        $this->assertSame(ReservationStatus::Held, $held->effectiveStatus());
        $this->assertSame(900000000, $held->hold->expires_at_us - $held->hold->accepted_at_us);
        $reservationUrl = "/api/v1/reservations/{$idReservation}";
        $this->getJson($reservationUrl.'/checkout-context', $headers)->assertOk()->assertJsonPath('data.hold.state', 'held')
            ->assertJsonPath('data.confirmation_policy.requires_verified_payment', false);
        $this->assertSame(15002, CommercialAmount::fromCents(100010)->commissionCents);
        $this->getJson($reservationUrl.'/demo-checkout', $headers)->assertNotFound();
        $commit = $this->action();
        $this->patchJson($reservationUrl.'/commit', $commit, $headers)->assertOk();
        $this->patchJson($reservationUrl.'/commit', $commit, $headers)->assertOk();
        $this->getJson($url.'/journey', $headers)->assertOk();
        $this->getJson('/api/v1/notifications/unread-count', $headers)->assertOk();
        $this->getJson('/api/v1/notifications', $headers)->assertOk();
        $this->assertDatabaseHas('activity_notifications', ['service_reservation_id' => $idReservation, 'user_id' => $provider->id, 'event' => 'reservation_confirmed']);
        auth()->forgetGuards();

        return [$provider, (int) $id, $held->fresh(), $headers];
    }

    private function at(int $seconds): void
    {
        DB::statement('SET @tekora_v818_lab_clock=?', [$seconds * 1000000]);
        $this->travelTo(BookingClock::date($seconds * 1000000));
        $this->assertSame($seconds, intdiv(BookingClock::micros(), 1000000));
    }

    private function action(int $version = 1): array
    {
        return ['version' => $version, 'idempotency_key' => (string) Str::uuid()];
    }

    private function bearer(User $client): array
    {
        $password = Str::random(28);
        $client->update(['password' => Hash::make($password)]);
        auth()->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => $password, 'device_name' => 'V818 lab'])
            ->assertCreated()->json('data.access_token');
        $this->assertIsString($token);
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }
}
