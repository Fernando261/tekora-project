<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Exceptions\InquiryConflict;
use App\Exceptions\InquiryMessageConflict;
use App\Models\ServiceInquiryMessage;
use App\Models\User;
use App\Services\InquiryMessaging;
use App\Services\InquiryWorkflow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MessageFixtures;
use Tests\TestCase;

class InquiryMessagesConcurrencyTest extends TestCase
{
    use MessageFixtures;

    private function sharedMemory(InquiryStatus $status = InquiryStatus::Pending): array
    {
        $uri = 'file:tekora-messages-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['message_a', 'message_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('message_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'message_a', '--force' => true]));
        $this->assertNotSame(DB::connection('message_a')->getPdo(), DB::connection('message_b')->getPdo());
        foreach (['message_a', 'message_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        $client = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);

        return [$client, $provider, $this->inquiry($client, $provider, $status)];
    }

    public function test_overlapping_replays_from_two_connections_and_unique_index_produce_one_message(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $key = (string) Str::uuid(); $body = 'Un solo mensaje aunque se reenvíe la petición.';
        $messaging = app(InquiryMessaging::class);
        DB::connection('message_a')->beginTransaction();
        $first = $messaging->send($client, $inquiry->id, $body, $key)['message'];
        DB::setDefaultConnection('message_b');
        $clientB = User::findOrFail($client->id);
        try {
            $messaging->send($clientB, $inquiry->id, $body, $key);
            $this->fail('El envío solapado no puede atravesar la transacción en curso.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertStringContainsString('otra operación en curso', $exception->getMessage());
        } finally {
            DB::connection('message_a')->commit();
        }
        $retried = $messaging->send($clientB, $inquiry->id, $body, $key);
        $this->assertFalse($retried['created']);
        $this->assertSame($first->id, $retried['message']->id);
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        try {
            DB::table('service_inquiry_messages')->insert(collect($first->getAttributes())->except('id')->all());
            $this->fail('La base debe rechazar incluso una inserción duplicada fuera del servicio.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertDatabaseCount('service_inquiry_messages', 1);
        }
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public static function closures(): array
    {
        return ['rechazo' => [InquiryAction::Reject], 'cancelación' => [InquiryAction::Cancel], 'finalización' => [InquiryAction::Complete]];
    }

    #[DataProvider('closures')]
    public function test_a_closure_that_wins_the_write_lock_prevents_a_late_message_even_after_retry(InquiryAction $action): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory($action->source());
        $this->message($client, $inquiry, 'Historial previo que sí estaba confirmado.');
        $key = (string) Str::uuid();
        DB::connection('message_a')->beginTransaction();
        app(InquiryWorkflow::class)->decide($action === InquiryAction::Cancel ? $client : $provider, $inquiry->id, $action);
        DB::setDefaultConnection('message_b');
        $clientB = User::findOrFail($client->id);
        try {
            app(InquiryMessaging::class)->send($clientB, $inquiry->id, 'Mensaje tardío que no puede guardarse.', $key);
            $this->fail('La decisión todavía abierta debe impedir el envío solapado.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertStringContainsString('otra operación en curso', $exception->getMessage());
        } finally {
            DB::connection('message_a')->commit();
        }
        try {
            app(InquiryMessaging::class)->send($clientB, $inquiry->id, 'Mensaje tardío que no puede guardarse.', $key);
            $this->fail('El reintento tampoco puede escribir en una solicitud ya cerrada.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertStringContainsString('ya no admite mensajes', $exception->getMessage());
        }
        $this->assertDatabaseCount('service_inquiry_messages', 1);
        $this->assertSame($action->target(), $inquiry->fresh()->status);
        $this->assertSame(0, ServiceInquiryMessage::where('idempotency_key', $key)->count());
        $this->assertDatabaseCount('inquiry_notifications', $action === InquiryAction::Complete ? 3 : 2);
    }

    public function test_a_message_that_wins_the_lock_commits_before_closure_and_cannot_be_followed_by_a_late_message(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        DB::connection('message_a')->beginTransaction();
        $this->message($client, $inquiry, 'Mensaje confirmado antes del rechazo.');
        DB::setDefaultConnection('message_b');
        $providerB = User::findOrFail($provider->id);
        try {
            app(InquiryWorkflow::class)->decide($providerB, $inquiry->id, InquiryAction::Reject);
            $this->fail('La decisión no puede intercalarse dentro del envío que ya tiene el bloqueo.');
        } catch (InquiryConflict $exception) {
            $this->assertSame($inquiry->id, $exception->inquiryId);
        } finally {
            DB::connection('message_a')->commit();
        }
        app(InquiryWorkflow::class)->decide($providerB, $inquiry->id, InquiryAction::Reject);
        $this->assertSame(InquiryStatus::Rejected, $inquiry->fresh()->status);
        try {
            $this->message(User::findOrFail($client->id), $inquiry, 'No guardar después del cierre.');
            $this->fail('La solicitud ya está cerrada.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertDatabaseCount('service_inquiry_messages', 1);
        }
        $this->assertDatabaseCount('inquiry_notifications', 2);
    }

    public function test_distinct_concurrent_submissions_can_retry_and_preserve_both_authors_in_order(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $key = (string) Str::uuid();
        DB::connection('message_a')->beginTransaction();
        $first = $this->message($client, $inquiry, 'Primero el cliente.');
        DB::setDefaultConnection('message_b');
        $providerB = User::findOrFail($provider->id);
        try {
            $this->message($providerB, $inquiry, 'Luego el prestador.', $key);
            $this->fail('El segundo escritor debe esperar a que termine el primero.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertStringContainsString('otra operación en curso', $exception->getMessage());
        } finally {
            DB::connection('message_a')->commit();
        }
        $second = $this->message($providerB, $inquiry, 'Luego el prestador.', $key);
        $this->assertGreaterThan($first->id, $second->id);
        $this->assertSame([$client->id, $provider->id], ServiceInquiryMessage::orderBy('id')->pluck('author_user_id')->all());
        $this->assertDatabaseCount('service_inquiry_messages', 2);
    }
}
