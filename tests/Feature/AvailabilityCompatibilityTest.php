<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\InquiryNotification;
use App\Models\InquiryReadProgress;
use App\Models\Profesionista;
use App\Models\ProfesionistaDisponibilidad;
use App\Services\InquiryMessaging;
use App\Services\InquiryReadTracking;
use App\Services\ProviderAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AvailabilityFixtures;
use Tests\TestCase;

class AvailabilityCompatibilityTest extends TestCase
{
    use AvailabilityFixtures;

    public function test_matching_legacy_account_neither_supplies_nor_can_edit_central_availability(): void
    {
        $owner = $this->account(RoleName::Provider); $service = $this->service($owner);
        $legacy = Profesionista::create(['nombre' => 'Profesional anterior', 'email' => $owner->email, 'cedula' => 'LEGACY-6A',
            'password' => Hash::make('old-password'), 'google_access_token' => 'fake-preserved-token']);
        $legacyInterval = ProfesionistaDisponibilidad::create(['profesionista_id' => $legacy->id, 'day_of_week' => 1,
            'start_time' => '07:00', 'end_time' => '23:00', 'is_active' => true]);
        DB::table('profesionista_bloqueos')->insert(['profesionista_id' => $legacy->id, 'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 11:00:00']);
        $before = $legacy->fresh()->getAttributes(); $hoursBefore = $legacyInterval->fresh()->getAttributes();
        $this->get(route('catalog.show', $service))->assertOk()->assertSee('Horario no publicado')->assertDontSee('07:00–23:00');
        $this->assertDatabaseCount('provider_availability_settings', 0);
        $this->post('/login-profesionista', ['email' => $legacy->email, 'password' => 'old-password'])->assertRedirect('/dashboard-profesionista');
        $this->get(route('availability.edit'))->assertRedirect('/login');
        $this->patchJson(route('availability.update'), $this->availabilityData())->assertUnauthorized();
        $this->get('/profesionista/disponibilidad')->assertOk();
        app(ProviderAvailability::class)->save($owner, $this->availabilityData());
        $this->assertSame($before, $legacy->fresh()->getAttributes()); $this->assertSame($hoursBefore, $legacyInterval->fresh()->getAttributes());
        $this->assertDatabaseCount('profesionista_bloqueos', 1); $this->assertDatabaseCount('citas', 0);
        $this->assertGuest('web');
    }

    public function test_schedule_edits_leave_inquiries_message_idempotency_notices_and_read_progress_unchanged(): void
    {
        $client = $this->account(RoleName::Client); $provider = $this->account(RoleName::Client, RoleName::Provider);
        $inquiry = $this->inquiry($client, $provider); $key = (string) \Illuminate\Support\Str::uuid();
        $message = $this->message($provider, $inquiry, 'Mensaje privado anterior.', $key);
        $message = $message->fresh();
        app(InquiryReadTracking::class)->mark($client, $inquiry->id, $this->readToken($client, $inquiry));
        $before = [$inquiry->getAttributes(), $message->getAttributes(), InquiryNotification::all()->toArray(), InquiryReadProgress::all()->toArray()];
        app(ProviderAvailability::class)->save($provider, $this->availabilityData());
        app(ProviderAvailability::class)->save($provider, $this->availabilityData(['revision' => 1, 'timezone' => 'America/New_York']));
        $this->assertSame($before, [$inquiry->fresh()->getAttributes(), $message->fresh()->getAttributes(), InquiryNotification::all()->toArray(), InquiryReadProgress::all()->toArray()]);
        $retry = app(InquiryMessaging::class)->send($provider, $inquiry->id, 'Mensaje privado anterior.', $key);
        $this->assertFalse($retry['created']); $this->assertSame($message->id, $retry['message']->id);
        $this->assertSame(0, app(InquiryReadTracking::class)->unreadFor($client, $inquiry));
        $this->assertTrue($provider->hasRole(RoleName::Client));
    }
}
