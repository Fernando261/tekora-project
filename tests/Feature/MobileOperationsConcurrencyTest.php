<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Services\InquiryMessaging;
use App\Services\InquiryWorkflow;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileOperationsConcurrencyTest extends TestCase
{
    use MobileOperationsFixtures;

    private function sharedMemory(): void
    {
        $uri = 'file:mobile-operations-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['mobile_a', 'mobile_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('mobile_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'mobile_a', '--force' => true]));
        $this->assertNotSame(DB::connection('mobile_a')->getPdo(), DB::connection('mobile_b')->getPdo());
        foreach (['mobile_a', 'mobile_b'] as $name) {
            $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        }
        // Evitar que el bookkeeping de Sanctum consuma primero el bloqueo que se está ensayando.
        config(['sanctum.last_used_at' => false]);
    }

    public function test_api_open_during_an_uncommitted_writer_conflicts_then_recovers_the_original(): void
    {
        $this->sharedMemory();
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $this->bearer($client);
        DB::connection('mobile_a')->beginTransaction();
        $first = app(InquiryWorkflow::class)->open($client, $service->id, 'La descripción original no se sustituye.')['inquiry'];
        DB::setDefaultConnection('mobile_b');
        try {
            $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Un segundo envío solapado.'], $this->bearer($client))->assertConflict();
        } finally {
            DB::connection('mobile_a')->commit();
        }
        $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Reintento después del bloqueo.'], $this->bearer($client))
            ->assertOk()->assertJsonPath('data.id', $first->id)->assertJsonPath('data.message', 'La descripción original no se sustituye.');
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_api_message_during_an_uncommitted_writer_has_no_partial_duplicate(): void
    {
        $this->sharedMemory();
        $inquiry = $this->inquiry();
        $client = $inquiry->client;
        $data = $this->messageData();
        $this->bearer($client);
        DB::connection('mobile_a')->beginTransaction();
        $first = app(InquiryMessaging::class)->send($client, $inquiry->id, $data['body'], $data['idempotency_key'])['message'];
        DB::setDefaultConnection('mobile_b');
        try {
            // El lookup propietario puede encontrar el bloqueo antes de entrar al workflow.
            $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/messages', $data, $this->bearer($client))->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        } finally {
            DB::connection('mobile_a')->commit();
        }
        $this->postJson('/api/v1/inquiries/'.$inquiry->id.'/messages', $data, $this->bearer($client))->assertOk()->assertJsonPath('data.id', $first->id);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
    }
}
