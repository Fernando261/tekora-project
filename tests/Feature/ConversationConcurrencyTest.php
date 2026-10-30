<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\InquiryMessageConflict;
use App\Exceptions\InquiryReadFailure;
use App\Models\InquiryReadProgress;
use App\Models\User;
use App\Services\InquiryReadTracking;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReadFixtures;
use Tests\TestCase;

class ConversationConcurrencyTest extends TestCase
{
    use ReadFixtures;

    private function sharedMemory(): array
    {
        $uri = 'file:tekora-read-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['read_a', 'read_b'] as $name) config(['database.connections.'.$name => [
            'driver' => 'sqlite', 'database' => $uri, 'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        DB::setDefaultConnection('read_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'read_a', '--force' => true]));
        $this->assertNotSame(DB::connection('read_a')->getPdo(), DB::connection('read_b')->getPdo());
        foreach (['read_a', 'read_b'] as $name) $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Provider);

        return [$client, $provider, $this->inquiry($client, $provider)];
    }

    public function test_overlapping_old_and_new_reads_keep_the_higher_marker_after_stale_retry(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $this->message($provider, $inquiry); $old = $this->readToken($client, $inquiry);
        $latest = $this->message($provider, $inquiry); $new = $this->readToken($client, $inquiry);
        $tracking = app(InquiryReadTracking::class);
        DB::connection('read_a')->beginTransaction();
        $tracking->mark($client, $inquiry->id, $new);
        DB::setDefaultConnection('read_b'); $clientB = User::findOrFail($client->id);
        try {
            $tracking->mark($clientB, $inquiry->id, $old);
            $this->fail('La segunda escritura no puede intercalarse dentro de la transacción abierta.');
        } catch (InquiryReadFailure $exception) {
            $this->assertSame(409, $exception->status);
        } finally {
            DB::connection('read_a')->commit();
        }
        $before = InquiryReadProgress::sole()->getAttributes();
        $this->travel(1)->hour();
        $tracking->mark($clientB, $inquiry->id, $old);
        $this->assertSame($before, InquiryReadProgress::sole()->getAttributes());
        $this->assertSame($latest->id, InquiryReadProgress::sole()->last_read_message_id);
        $this->assertSame(0, $tracking->unreadFor($clientB, $inquiry));
        $this->assertDatabaseCount('service_inquiry_reads', 1);
    }

    public function test_an_older_read_can_commit_first_and_a_newer_retry_advances_once(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $first = $this->message($provider, $inquiry); $old = $this->readToken($client, $inquiry);
        $latest = $this->message($provider, $inquiry); $new = $this->readToken($client, $inquiry);
        $tracking = app(InquiryReadTracking::class);
        DB::connection('read_a')->beginTransaction(); $tracking->mark($client, $inquiry->id, $old);
        DB::setDefaultConnection('read_b'); $clientB = User::findOrFail($client->id);
        try {
            $tracking->mark($clientB, $inquiry->id, $new);
            $this->fail('La lectura posterior debe recibir un conflicto mientras siga abierta la anterior.');
        } catch (InquiryReadFailure $exception) {
            $this->assertSame(409, $exception->status);
        } finally {
            DB::connection('read_a')->commit();
        }
        $this->assertSame($first->id, InquiryReadProgress::sole()->last_read_message_id);
        $tracking->mark($clientB, $inquiry->id, $new);
        $this->assertSame($latest->id, InquiryReadProgress::sole()->last_read_message_id);
        $before = InquiryReadProgress::sole()->getAttributes();
        $this->travel(1)->day(); $tracking->mark($clientB, $inquiry->id, $new);
        $this->assertSame($before, InquiryReadProgress::sole()->getAttributes());
        $this->assertDatabaseCount('service_inquiry_reads', 1);
    }

    public function test_a_message_retry_after_an_overlapping_read_stays_unread_and_does_not_change_the_marker(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $first = $this->message($provider, $inquiry); $token = $this->readToken($client, $inquiry);
        DB::connection('read_a')->beginTransaction(); app(InquiryReadTracking::class)->mark($client, $inquiry->id, $token);
        DB::setDefaultConnection('read_b'); $providerB = User::findOrFail($provider->id);
        $key = (string) \Illuminate\Support\Str::uuid();
        try {
            $this->message($providerB, $inquiry, 'Llega después de la lectura.', $key);
            $this->fail('SQLite debe serializar también el escritor de mensajes.');
        } catch (InquiryMessageConflict $exception) {
            $this->assertStringContainsString('otra operación en curso', $exception->getMessage());
        } finally {
            DB::connection('read_a')->commit();
        }
        $latest = $this->message($providerB, $inquiry, 'Llega después de la lectura.', $key);
        $this->assertGreaterThan($first->id, $latest->id);
        $this->assertSame($first->id, InquiryReadProgress::sole()->last_read_message_id);
        $this->assertSame(1, app(InquiryReadTracking::class)->unreadFor(User::findOrFail($client->id), $inquiry));
        $this->assertDatabaseCount('service_inquiry_messages', 2);
        $this->assertDatabaseCount('inquiry_notifications', 1);
    }

    public function test_two_participants_read_independently_after_serialized_writes(): void
    {
        [$client, $provider, $inquiry] = $this->sharedMemory();
        $fromClient = $this->message($client, $inquiry); $fromProvider = $this->message($provider, $inquiry);
        $clientToken = $this->readToken($client, $inquiry); $providerToken = $this->readToken($provider, $inquiry);
        $tracking = app(InquiryReadTracking::class);
        DB::connection('read_a')->beginTransaction(); $tracking->mark($client, $inquiry->id, $clientToken);
        DB::setDefaultConnection('read_b'); $providerB = User::findOrFail($provider->id);
        try {
            $tracking->mark($providerB, $inquiry->id, $providerToken);
            $this->fail('Los dos UPSERT deben respetar el bloqueo de escritura SQLite.');
        } catch (InquiryReadFailure $exception) {
            $this->assertSame(409, $exception->status);
        } finally {
            DB::connection('read_a')->commit();
        }
        $tracking->mark($providerB, $inquiry->id, $providerToken);
        $this->assertSame($fromProvider->id, InquiryReadProgress::where('user_id', $client->id)->sole()->last_read_message_id);
        $this->assertSame($fromClient->id, InquiryReadProgress::where('user_id', $provider->id)->sole()->last_read_message_id);
        $this->assertDatabaseCount('service_inquiry_reads', 2);
    }
}
