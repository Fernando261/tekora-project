<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Profesionista;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CitasRegressionTest extends TestCase
{
    private function professional(string $email, string $cedula): Profesionista
    {
        return Profesionista::create([
            'nombre' => 'Profesional de prueba', 'email' => $email,
            'password' => Hash::make('test-password'), 'cedula' => $cedula,
        ]);
    }

    public function test_a_request_still_persists_both_legacy_date_and_state_fields_for_its_owner(): void
    {
        $user = User::factory()->create();
        $professional = $this->professional('first@example.test', 'TEST-001');

        $this->actingAs($user, 'web')->post('/citas', [
            'profesionista_id' => $professional->id,
            'starts_at' => '2030-01-07T10:00:00-06:00',
            'ends_at' => '2030-01-07T10:50:00-06:00',
        ])->assertRedirectToRoute('citas.create', ['profesionista' => $professional->id]);

        $cita = Cita::firstOrFail();
        $this->assertSame($user->id, $cita->user_id);
        $this->assertSame('pendiente', $cita->estado);
        $this->assertSame('pending', $cita->status);
        $this->assertSame($cita->getRawOriginal('start_datetime'), $cita->starts_at);
        $this->assertSame($cita->getRawOriginal('end_datetime'), $cita->ends_at);
        $this->assertNull($cita->google_event_id);
    }

    public function test_another_professional_cannot_accept_or_reject_a_request(): void
    {
        $owner = $this->professional('owner@example.test', 'TEST-001');
        $other = $this->professional('other@example.test', 'TEST-002');
        $cita = Cita::create([
            'user_id' => User::factory()->create()->id, 'profesionista_id' => $owner->id,
            'start_datetime' => '2030-01-07 10:00:00', 'end_datetime' => '2030-01-07 10:50:00',
            'starts_at' => '2030-01-07 10:00:00', 'ends_at' => '2030-01-07 10:50:00',
            'estado' => 'pendiente', 'status' => 'pending',
        ]);

        $this->actingAs($other, 'profesionista');
        $this->post('/citas/'.$cita->id.'/aceptar')->assertNotFound();
        $this->post('/citas/'.$cita->id.'/rechazar')->assertNotFound();
        $this->assertSame('pendiente', $cita->fresh()->estado);
        $this->assertSame('pending', $cita->fresh()->status);
    }
}
