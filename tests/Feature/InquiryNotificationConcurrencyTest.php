<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\RoleName;
use App\Exceptions\InquiryConflict;
use App\Models\InquiryNotification;
use App\Models\User;
use App\Services\InquiryWorkflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryNotificationConcurrencyTest extends TestCase
{
    use InquiryFixtures;

    private function sharedMemory(): array
    {
        $uri = 'file:tekora-notices-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['notice_a', 'notice_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('notice_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'notice_a', '--force' => true]));
        $this->assertNotSame(DB::connection('notice_a')->getPdo(), DB::connection('notice_b')->getPdo());
        foreach (['notice_a', 'notice_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);

        return [$client, $provider, $this->service($provider)];
    }

    public function test_overlapping_creations_and_retry_from_two_connections_produce_one_notice_and_database_rejects_duplicate(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        $workflow = app(InquiryWorkflow::class);
        DB::connection('notice_a')->beginTransaction();
        $first = $workflow->open($client, $service->id, 'Una solicitud enviada desde la primera conexión.')['inquiry'];
        DB::setDefaultConnection('notice_b');
        try {
            $workflow->open(User::findOrFail($client->id), $service->id, 'Segundo envío mientras el primero sigue abierto.');
            $this->fail('La conexión solapada debe recibir conflicto.');
        } catch (InquiryConflict $exception) {
            $this->assertStringContainsString('otra operación en curso', $exception->getMessage());
        } finally {
            DB::connection('notice_a')->commit();
        }
        $retried = $workflow->open(User::findOrFail($client->id), $service->id, 'Reintento sin duplicar ni reemplazar.');
        $this->assertFalse($retried['created']);
        $this->assertSame($first->id, $retried['inquiry']->id);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $notice = InquiryNotification::sole();
        $this->assertSame($provider->id, $notice->user_id);
        try {
            DB::table('inquiry_notifications')->insert(collect($notice->getAttributes())->except('id')->all());
            $this->fail('La base de datos debe rechazar un aviso duplicado incluso fuera del workflow.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertDatabaseCount('inquiry_notifications', 1);
        }
    }

    public function test_overlapping_decisions_and_stale_retry_persist_only_the_successful_event(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Necesito revisar mi equipo de trabajo.')['inquiry'];
        DB::setDefaultConnection('notice_b');
        $providerB = User::findOrFail($provider->id);
        DB::setDefaultConnection('notice_a');
        DB::connection('notice_a')->beginTransaction();
        $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        DB::setDefaultConnection('notice_b');
        try {
            $workflow->decide($providerB, $inquiry->id, InquiryAction::Reject);
            $this->fail('La segunda decisión no debe atravesar la transacción en curso.');
        } catch (InquiryConflict $exception) {
            $this->assertSame($inquiry->id, $exception->inquiryId);
        } finally {
            DB::connection('notice_a')->commit();
        }
        foreach ([InquiryAction::Accept, InquiryAction::Reject] as $action) {
            try {
                $workflow->decide($providerB, $inquiry->id, $action);
                $this->fail('Una decisión repetida u opuesta debe recibir conflicto.');
            } catch (InquiryConflict $exception) {
                $this->assertSame($inquiry->id, $exception->inquiryId);
            }
        }
        $this->assertDatabaseCount('inquiry_notifications', 2);
        $this->assertSame(['created', 'accepted'], InquiryNotification::orderBy('id')->pluck('event')->map->value->all());
        $this->assertDatabaseHas('inquiry_notifications', ['user_id' => $client->id, 'service_inquiry_id' => $inquiry->id, 'event' => 'accepted']);
        $this->assertSame('accepted', $inquiry->fresh()->status->value);
    }

    public function test_two_stale_read_attempts_keep_the_first_read_timestamp(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        app(InquiryWorkflow::class)->open($client, $service->id, 'Necesito revisar mi equipo de trabajo.');
        $notice = InquiryNotification::sole();
        DB::setDefaultConnection('notice_b');
        $stale = InquiryNotification::findOrFail($notice->id);
        $this->assertNull($stale->read_at);
        DB::setDefaultConnection('notice_a');
        $this->actingAs($provider, 'web')->post(route('notifications.read', $notice))->assertStatus(303);
        $readAt = InquiryNotification::findOrFail($notice->id)->getRawOriginal('read_at');
        DB::setDefaultConnection('notice_b');
        $this->travel(1)->hour();
        $this->actingAs(User::findOrFail($provider->id), 'web')->post(route('notifications.read', $stale))->assertStatus(303);
        $this->assertSame($readAt, $stale->fresh()->getRawOriginal('read_at'));
        $this->assertSame(0, InquiryNotification::unread()->count());
    }
}
