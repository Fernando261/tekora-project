<?php

namespace Tests\MySqlV81;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Notifications\MobileIdentityCode;
use App\Services\InquiryMessaging;
use App\Services\InquiryWorkflow;
use App\Services\ProviderAvailability;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class FoundationTest extends LabTestCase
{
    public function test_foundation_migrations_are_idempotent_and_restart_preserves_inquiry(): void
    {
        $expected = array_map(fn ($path) => basename($path, '.php'), mysqlV81MigrationPaths());
        $this->assertCount(35, $expected);
        $records = DB::table('migrations')->orderBy('id')->get();
        $this->assertSame($expected, $records->pluck('migration')->all());
        $this->migrateFoundation();
        $this->assertEquals($records, DB::table('migrations')->orderBy('id')->get());
        $settings = DB::selectOne('SELECT VERSION() AS version, @@foreign_key_checks AS foreign_keys, @@default_storage_engine AS engine, @@transaction_isolation AS isolation_level, @@skip_networking AS skip_networking');
        $this->assertSame('InnoDB', $settings->engine);
        $this->assertSame(1, (int) $settings->foreign_keys);
        $this->assertSame(1, (int) $settings->skip_networking);
        $this->assertSame(['InnoDB'], DB::table('information_schema.TABLES')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_TYPE', 'BASE TABLE')->distinct()->pluck('ENGINE')->all());
        fwrite(STDOUT, sprintf("\nMySQL %s / %s / isolation=%s / migrations=%d / TCP disabled\n", $settings->version, $settings->engine, $settings->isolation_level, count($expected)));

        if (getenv('TEKORA_V81_RESTARTED') !== '1') {
            $provider = $this->account(RoleName::Provider);
            $service = $this->service($provider);
            app(ProviderAvailability::class)->save($provider, [
                'revision' => 0, 'timezone' => 'America/Mexico_City',
                'intervals' => [['day_of_week' => 1, 'start' => '09:00', 'end' => '10:00']],
                'blocked_dates' => ['2032-02-29'],
            ]);
            $inquiries = app(InquiryWorkflow::class);
            $inquiry = $inquiries->open($this->account(RoleName::Client), $service->id, 'Marcador de reinicio V8.1')['inquiry'];
            $inquiry = $inquiries->decide($provider, $inquiry->id, InquiryAction::Accept);
            $reservation = DB::table('service_reservations')->insertGetId($this->reservationRow($inquiry));
            DB::table('service_reservations')->where('id', $reservation)->update([
                'status' => 'confirmed', 'version' => 2, 'last_actor_user_id' => $provider->id,
                'last_reason' => 'confirm', 'updated_at' => '2030-01-02 12:00:00',
            ]);
        }
        $saved = ServiceInquiry::where('message', 'Marcador de reinicio V8.1')->sole();
        $result = app(InquiryWorkflow::class)->open($saved->client, $saved->service_id, 'Reintento después de la lectura');
        $this->assertFalse($result['created']);
        $this->assertSame($saved->id, $result['inquiry']->id);
        $this->assertDatabaseHas('inquiry_notifications', ['service_inquiry_id' => $saved->id, 'event' => 'created']);
        $profile = $saved->service->providerProfile;
        $availability = $profile->availabilitySetting()->sole();
        $this->assertSame(1, $availability->revision);
        $this->assertSame('America/Mexico_City', $availability->timezone);
        $this->assertSame(540, (int) $profile->weeklyIntervals()->sole()->start_minute);
        $this->assertSame(['2032-02-29'], $profile->blockedDates()->pluck('blocked_date')->all());
        $reservation = $saved->reservations()->sole();
        $this->assertSame('confirmed', $reservation->status->value);
        $this->assertSame(2, $reservation->version);
        $this->assertSame('2030-01-07 15:00:00', $reservation->starts_at_utc);
        $this->assertSame(['proposal', 'confirm'], $reservation->events()->orderBy('version')->pluck('reason')->all());
    }

    public function test_active_uniqueness_terminal_history_and_foreign_keys(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);
        $first = $workflow->open($client, $service->id, 'Solicitud original sintética')['inquiry'];
        $this->assertArrayNotHasKey('active_slot', $first->fresh()->getAttributes());
        $row = ['service_id' => $service->id, 'client_user_id' => $client->id, 'service_title' => 'Servicio', 'message' => 'Solicitud sintética'];

        $this->assertMySqlError(1062, fn () => DB::table('service_inquiries')->insert($row + ['status' => 'accepted']));
        $this->assertMySqlError(3819, fn () => DB::table('service_inquiries')->insert($row + ['status' => 'PENDING']));
        $workflow->decide($provider, $first->id, InquiryAction::Reject);
        $second = $workflow->open($client, $service->id, 'Solicitud posterior sintética')['inquiry'];
        $this->assertNotSame($first->id, $second->id);
        $workflow->decide($provider, $second->id, InquiryAction::Accept);
        $this->assertMySqlError(1062, fn () => DB::table('service_inquiries')->insert($row));
        $this->assertMySqlError(1452, fn () => DB::table('service_inquiries')->insert(['client_user_id' => PHP_INT_MAX] + $row));
        $this->assertMySqlError(1451, fn () => $client->delete());
        $this->assertSame(2, ServiceInquiry::where('client_user_id', $client->id)->count());
        $this->assertSame(1, ServiceInquiry::where('client_user_id', $client->id)->active()->count());
        $this->assertDatabaseHas('inquiry_notifications', ['service_inquiry_id' => $first->id, 'event' => 'rejected']);
        $this->assertDatabaseHas('inquiry_notifications', ['service_inquiry_id' => $second->id, 'event' => 'accepted']);
    }

    public function test_notification_failure_rolls_back_open_and_decision(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $workflow = app(InquiryWorkflow::class);

        DB::unprepared("CREATE TRIGGER v81_reject_notification BEFORE INSERT ON inquiry_notifications
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic fault'");
        try {
            $this->assertMySqlError(1644, fn () => $workflow->open($client, $service->id, 'Esta escritura debe revertirse'));
            $this->assertDatabaseMissing('service_inquiries', ['client_user_id' => $client->id]);
        } finally {
            DB::unprepared('DROP TRIGGER v81_reject_notification');
        }

        $found = $workflow->open($client, $service->id, 'Solicitud válida sintética')['inquiry'];
        DB::unprepared("CREATE TRIGGER v81_reject_notification BEFORE INSERT ON inquiry_notifications
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic fault'");
        try {
            $this->assertMySqlError(1644, fn () => $workflow->decide($provider, $found->id, InquiryAction::Accept));
            $this->assertSame('pending', $found->fresh()->status->value);
            $this->assertDatabaseMissing('inquiry_notifications', ['service_inquiry_id' => $found->id, 'event' => 'accepted']);
        } finally {
            DB::unprepared('DROP TRIGGER v81_reject_notification');
        }
        $this->assertSame('accepted', $workflow->decide($provider, $found->id, InquiryAction::Accept)->status->value);
    }

    public function test_concurrent_workers_receive_one_inquiry_and_innodb_blocks_a_conflicting_insert(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $workers = [];
        try {
            $workers[] = $this->startWorker('inquiry-worker.php', 'open', $service->id, $client->id);
            $workers[] = $this->startWorker('inquiry-worker.php', 'open', $service->id, $client->id);
            foreach ($workers as [,$pipes]) {
                fwrite($pipes[0], "go\n");
            }
            $first = $this->workerResult($workers[0]);
            $second = $this->workerResult($workers[1]);
            $this->assertSame($first['id'], $second['id']);
            $this->assertNotSame($first['created'], $second['created']);
            $this->assertSame(1, DB::table('inquiry_notifications')->where('service_inquiry_id', $first['id'])->count());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }

        $other = $this->account(RoleName::Client);
        DB::beginTransaction();
        $worker = null;
        try {
            app(InquiryWorkflow::class)->open($other, $service->id, 'Existe solo dentro de esta transacción');
            $worker = $this->startWorker('inquiry-worker.php', 'insert', $service->id, $other->id);
            fwrite($worker[1][0], "go\n");
            $this->assertSame(['sqlstate' => 'HY000', 'diagnostic' => 1205], $this->workerResult($worker));
        } finally {
            DB::rollBack();
            if ($worker !== null) {
                $this->closeWorker($worker);
            }
        }
        $this->assertDatabaseMissing('service_inquiries', ['client_user_id' => $other->id]);
        $this->assertTrue(app(InquiryWorkflow::class)->open($other, $service->id, 'Reintento después de rollback')['created']);
    }

    public function test_innodb_deadlock_retries_the_aborted_transaction_without_duplicate_effects(): void
    {
        DB::table('cache')->insert([
            ['key' => 'v81-deadlock-1', 'value' => '0', 'expiration' => 1],
            ['key' => 'v81-deadlock-2', 'value' => '0', 'expiration' => 1],
        ]);
        $workers = [];
        try {
            $workers[] = $this->startWorker('inquiry-worker.php', 'deadlock', 1, 2);
            $workers[] = $this->startWorker('inquiry-worker.php', 'deadlock', 2, 1);
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "go\n");
            }
            foreach ($workers as [, $pipes]) {
                $this->assertSame("locked\n", fgets($pipes[1]));
            }
            foreach ($workers as [, $pipes]) {
                fwrite($pipes[0], "cross\n");
            }
            $attempts = [$this->workerResult($workers[0])['attempts'], $this->workerResult($workers[1])['attempts']];
            sort($attempts);
            $this->assertSame([1, 2], $attempts);
            $this->assertSame(['2', '2'], DB::table('cache')->whereIn('key', ['v81-deadlock-1', 'v81-deadlock-2'])
                ->orderBy('key')->pluck('value')->all());
        } finally {
            foreach ($workers as $worker) {
                $this->closeWorker($worker);
            }
        }
    }

    public function test_mobile_bearer_requests_messages_and_reads_keep_contract_and_privacy(): void
    {
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);
        $path = '/api/v1/services/'.$service->id.'/inquiries';
        $payload = ['message' => 'Necesito revisar un proyecto de prueba.'];
        $this->postJson($path, $payload)->assertUnauthorized();
        $token = $this->mobileLogin($client->email);
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk()->assertJsonPath('data.id', $client->id);
        $created = $this->postJson($path, $payload, $this->bearer($token))->assertCreated()->assertJsonPath('data.status', 'pending');
        $id = $created->json('data.id');
        $this->postJson($path, $payload, $this->bearer($token))->assertOk()->assertJsonPath('data.id', $id);

        $inquiry = '/api/v1/inquiries/'.$id;
        $other = $this->account(RoleName::Client);
        $otherToken = $this->mobileLogin($other->email);
        $this->getJson($inquiry, $this->bearer($otherToken))->assertNotFound();
        $this->getJson($inquiry, $this->bearer($token))->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/inquiries', $this->bearer($token))->assertOk()->assertJsonPath('data.0.id', $id);
        app(InquiryWorkflow::class)->decide($provider, $id, InquiryAction::Accept);

        $messages = $inquiry.'/messages';
        $send = ['body' => 'Mensaje sintético del cliente', 'idempotency_key' => (string) Str::uuid()];
        $message = $this->postJson($messages, $send, $this->bearer($token))->assertCreated()->json('data.id');
        $this->postJson($messages, $send, $this->bearer($token))->assertOk()->assertJsonPath('data.id', $message);
        $this->postJson($messages, ['body' => 'Contenido distinto'] + $send, $this->bearer($token))->assertConflict();

        $workflow = app(InquiryMessaging::class);
        $first = $workflow->send($provider, $id, 'Primera respuesta de prueba', (string) Str::uuid())['message'];
        $oldToken = $this->getJson($messages, $this->bearer($token))->assertOk()->json('meta.read_token');
        $last = $workflow->send($provider, $id, 'Segunda respuesta de prueba', (string) Str::uuid())['message'];
        $latestToken = $this->getJson($messages, $this->bearer($token))->assertOk()->json('meta.read_token');
        $this->getJson('/api/v1/conversations', $this->bearer($token))->assertOk()->assertJsonPath('data.0.unread_messages_count', 2);
        $this->postJson($messages.'/read', ['read_token' => $oldToken], $this->bearer($token))->assertOk()->assertJsonPath('data.last_read_message_id', $first->id);
        $initialMarker = DB::table('service_inquiry_reads')->where('service_inquiry_id', $id)->first();
        $this->travel(2)->seconds();
        $this->postJson($messages.'/read', ['read_token' => $latestToken], $this->bearer($token))->assertOk()->assertJsonPath('data.last_read_message_id', $last->id);
        $marker = DB::table('service_inquiry_reads')->where('service_inquiry_id', $id)->first();
        $this->assertSame($initialMarker->created_at, $marker->created_at);
        $this->assertGreaterThan($initialMarker->updated_at, $marker->updated_at);
        $this->travel(2)->seconds();
        $this->postJson($messages.'/read', ['read_token' => $oldToken], $this->bearer($token))->assertOk()->assertJsonPath('data.last_read_message_id', $last->id);
        $this->assertEquals($marker, DB::table('service_inquiry_reads')->where('service_inquiry_id', $id)->first());
        $this->getJson($messages, $this->bearer($token))->assertOk()->assertJsonPath('meta.unread_messages_count', 0);
        $this->assertMySqlError(1452, fn () => DB::table('service_inquiry_reads')->insert([
            'service_inquiry_id' => $id, 'user_id' => $other->id, 'last_read_message_id' => PHP_INT_MAX,
        ]));
        $this->postJson('/api/v1/auth/logout', [], $this->bearer($token))->assertOk();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
    }

    public function test_mobile_identity_recovery_profile_and_production_demo_boundary(): void
    {
        Notification::fake();
        $email = Str::random(10).'@example.test';
        $this->postJson('/api/v1/auth/register', ['email' => $email])->assertAccepted();
        $registrationToken = null;
        Notification::assertSentOnDemand(MobileIdentityCode::class, function ($notice, $channels, $recipient) use ($email, &$registrationToken) {
            $registrationToken = $notice->token;

            return $notice->registration && $recipient->routes['mail'] === strtolower($email);
        });
        $password = 'synthetic-password';
        $this->postJson('/api/v1/auth/register/confirm', [
            'email' => $email, 'token' => $registrationToken, 'name' => 'Cuenta de prueba V8.1',
            'age' => 30, 'gender' => 'Prefiero no decirlo', 'password' => $password, 'password_confirmation' => $password,
        ])->assertAccepted();
        $user = User::where('email', $email)->sole();
        $token = $this->mobileLogin($email, $password);
        $this->patchJson('/api/v1/me/profile', ['name' => 'Nombre actualizado'], $this->bearer($token))->assertOk()
            ->assertJsonPath('data.name', 'Nombre actualizado');
        $this->getJson('/api/v1/me/profile', $this->bearer($token))->assertOk()->assertJsonPath('data.id', $user->id);

        $this->app->instance('env', 'production');
        try {
            $this->get('/demo/checkout')->assertNotFound();
            $this->getJson('/api/v1/reservations/1/demo-checkout', $this->bearer($token))->assertNotFound();
            $this->postJson('/api/v1/reservations/1/demo-checkout', ['method' => 'cash'], $this->bearer($token))->assertNotFound();
        } finally {
            $this->app->instance('env', 'testing');
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertAccepted();
        $reset = Notification::sent($user, MobileIdentityCode::class)->sole()->token;
        $newPassword = 'new-synthetic-password';
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'token' => $reset, 'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk();
        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        $this->assertNotEmpty($this->mobileLogin($email, $newPassword));
        config(['session.driver' => 'database', 'session.connection' => 'mysql']);
        // A new web request resolves the default guard independently of the API.
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->post('/login', ['email' => $email, 'password' => $newPassword])->assertRedirect(route('account.index'));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);

        $this->app->instance('env', 'production');
        try {
            $path = '/cuenta/reservas/1/checkout';
            $response = $this->get($path)->assertNotFound();
            $sessionCookie = config('session.cookie');
            $this->withCookie($sessionCookie, $response->getCookie($sessionCookie)->getValue())
                ->post($path, ['method' => 'cash', '_token' => csrf_token()])->assertNotFound();
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function mobileLogin(string $email, string $password = 'password'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => 'V8.1 lab'])
            ->assertCreated()->json('data.access_token');
    }

    private function bearer(string $token): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }
}
