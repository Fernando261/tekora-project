<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Exceptions\InquiryConflict;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Services\InquiryWorkflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\InquiryFixtures;
use Tests\TestCase;

class InquiryConcurrencyTest extends TestCase
{
    use InquiryFixtures;

    private function sharedMemory(): array
    {
        $uri = 'file:tekora-inquiries-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['inquiry_a', 'inquiry_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('inquiry_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'inquiry_a', '--force' => true]));
        $this->assertNotSame(DB::connection('inquiry_a')->getPdo(), DB::connection('inquiry_b')->getPdo());
        foreach (['inquiry_a', 'inquiry_b'] as $name) {
            $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        }
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $service = $this->service($provider);

        return [$client, $provider, $service];
    }

    public function test_two_connections_with_stale_pending_reads_cannot_make_opposing_decisions(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Necesito revisar el equipo de trabajo.')['inquiry'];
        $staleA = ServiceInquiry::findOrFail($inquiry->id);
        DB::setDefaultConnection('inquiry_b');
        $staleB = ServiceInquiry::findOrFail($inquiry->id);
        $clientB = User::findOrFail($client->id);
        $this->assertSame(InquiryStatus::Pending, $staleA->status);
        $this->assertSame(InquiryStatus::Pending, $staleB->status);
        DB::setDefaultConnection('inquiry_a');
        $accepted = $workflow->decide($provider, $staleA->id, InquiryAction::Accept);
        $before = $accepted->getAttributes();
        DB::setDefaultConnection('inquiry_b');
        try {
            $workflow->decide($clientB, $staleB->id, InquiryAction::Cancel);
            $this->fail('La decisión obsoleta no debe sobrescribir la aceptación.');
        } catch (InquiryConflict $conflict) {
            $this->assertSame($inquiry->id, $conflict->inquiryId);
        }
        $this->assertSame($before, ServiceInquiry::findOrFail($inquiry->id)->getAttributes());
        $this->assertSame(0, DB::table('service_inquiries')->where('id', $inquiry->id)->where('status', 'pending')->update(['status' => 'rejected']));
    }

    public function test_database_unique_index_prevents_an_active_duplicate_from_another_connection(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        $workflow = app(InquiryWorkflow::class);
        $inquiry = $workflow->open($client, $service->id, 'Necesito revisar el equipo de trabajo.')['inquiry'];
        $workflow->decide($provider, $inquiry->id, InquiryAction::Accept);
        DB::setDefaultConnection('inquiry_b');
        try {
            DB::table('service_inquiries')->insert(['service_id' => $service->id, 'client_user_id' => $client->id,
                'service_title' => $service->title, 'message' => 'No duplicar la solicitud activa.', 'status' => 'pending']);
            $this->fail('El índice debe bloquear incluso una escritura que omita el workflow.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertDatabaseCount('service_inquiries', 1);
        }
    }

    public function test_overlapping_writers_receive_a_recoverable_conflict_then_find_the_original_submission(): void
    {
        [$client, $provider, $service] = $this->sharedMemory();
        $workflow = app(InquiryWorkflow::class);
        DB::connection('inquiry_a')->beginTransaction();
        $first = $workflow->open($client, $service->id, 'Necesito revisar el equipo de trabajo.')['inquiry'];
        DB::setDefaultConnection('inquiry_b');
        try {
            $workflow->open(User::findOrFail($client->id), $service->id, 'Un segundo envío solapado.');
            $this->fail('La segunda conexión no puede escribir sobre la transacción todavía abierta.');
        } catch (InquiryConflict $conflict) {
            $this->assertStringContainsString('otra operación en curso', $conflict->getMessage());
        } finally {
            DB::connection('inquiry_a')->commit();
        }
        $retried = $workflow->open(User::findOrFail($client->id), $service->id, 'No reemplazar el envío original.');
        $this->assertFalse($retried['created']);
        $this->assertSame($first->id, $retried['inquiry']->id);
        $this->assertSame('Necesito revisar el equipo de trabajo.', $retried['inquiry']->message);
        $this->assertDatabaseCount('service_inquiries', 1);
    }
}
