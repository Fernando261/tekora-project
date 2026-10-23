<?php

namespace Tests\Feature;

use App\Models\Profesionista;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private function professional(): Profesionista
    {
        return Profesionista::create([
            'nombre' => 'Profesional de prueba',
            'email' => 'professional@example.test',
            'password' => Hash::make('test-password'),
            'cedula' => 'TEST-001',
        ]);
    }

    public function test_a_user_can_register_without_authenticating_the_professional_guard(): void
    {
        $this->post('/registro', [
            'name' => 'Cliente de prueba', 'age' => 25, 'gender' => 'Prefiero no decirlo',
            'email' => 'client@example.test', 'password' => 'test-password',
        ])->assertRedirect('/login')->assertSessionHas('success');

        $user = User::where('email', 'client@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-password', $user->password));
        $this->assertGuest('web');
        $this->assertGuest('profesionista');
        $this->assertDatabaseCount('profesionistas', 0);
    }

    public function test_a_professional_can_register_with_the_correct_panel_and_password_hash(): void
    {
        $this->post('/registro-profesionista', [
            'nombre' => 'Profesional de prueba', 'email' => 'professional@example.test',
            'password' => 'test-password', 'password_confirmation' => 'test-password',
            'cedula' => 'TEST-001',
        ])->assertRedirect('/registro?role=prof')->assertSessionHas('success');

        $professional = Profesionista::firstOrFail();
        $this->assertTrue(Hash::check('test-password', $professional->password));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('profesionista');
    }

    public function test_duplicate_professional_credentials_are_validation_errors_without_changing_the_account(): void
    {
        $professional = $this->professional()->fresh();

        $this->post('/registro-profesionista', [
            'nombre' => 'Otro nombre', 'email' => $professional->email,
            'password' => 'another-password', 'password_confirmation' => 'another-password',
            'cedula' => $professional->cedula,
        ])->assertSessionHasErrors(['email', 'cedula']);

        $this->assertSame($professional->getAttributes(), $professional->fresh()->getAttributes());
        $this->assertDatabaseCount('profesionistas', 1);
    }

    public function test_invalid_user_registration_is_not_persisted(): void
    {
        $this->post('/registro', [
            'name' => '', 'age' => 0, 'gender' => '', 'email' => 'invalid', 'password' => '123',
        ])->assertSessionHasErrors(['name', 'age', 'gender', 'email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_each_login_uses_its_own_guard_even_with_the_same_email_in_both_tables(): void
    {
        $professional = $this->professional();
        $user = User::factory()->create(['email' => $professional->email, 'password' => 'user-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'user-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('profesionista');

        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login-profesionista', [
            'email' => $professional->email, 'password' => 'test-password',
        ])->assertRedirect('/dashboard-profesionista');

        $this->assertAuthenticatedAs($professional, 'profesionista');
        $this->assertGuest('web');
    }

    public function test_wrong_credentials_do_not_authenticate_either_guard(): void
    {
        $professional = $this->professional();
        User::factory()->create(['email' => $professional->email]);

        foreach (['/login', '/login-profesionista'] as $path) {
            $this->post($path, ['email' => $professional->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->assertGuest('web');
        $this->assertGuest('profesionista');
    }

    public function test_malformed_login_fields_return_validation_errors_for_both_guards(): void
    {
        foreach (['/login', '/login-profesionista'] as $path) {
            $this->post($path, ['email' => ['invalid'], 'password' => ['invalid']])
                ->assertSessionHasErrors(['email', 'password']);
        }
    }

    public function test_each_login_is_limited_by_normalized_identity_and_recovers_after_one_minute(): void
    {
        foreach (['/login', '/login-profesionista'] as $path) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $email = $attempt % 2 ? 'LIMIT@example.test' : 'limit@example.test';
                $this->post($path, ['email' => $email, 'password' => 'incorrect'])
                    ->assertRedirect()->assertSessionHasErrors('email');
            }

            $this->post($path, ['email' => 'limit@example.test', 'password' => 'incorrect'])
                ->assertStatus(429)->assertHeader('Retry-After');
        }

        $this->travel(61)->seconds();
        $this->post('/login', ['email' => 'limit@example.test', 'password' => 'incorrect'])
            ->assertRedirect()->assertSessionHasErrors('email');
    }

    public function test_the_ip_limit_also_restricts_attempts_against_different_emails(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->post('/login', ['email' => "person{$attempt}@example.test", 'password' => 'incorrect'])
                ->assertRedirect();
        }

        $this->post('/login', ['email' => 'another@example.test', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_each_identity_can_log_out_using_its_endpoint(): void
    {
        $user = User::factory()->create();
        $professional = $this->professional();

        $this->actingAs($user, 'web')->post('/logout')->assertRedirect('/login');
        $this->assertGuest('web');

        $this->actingAs($professional, 'profesionista')->post('/logout-profesionista')
            ->assertRedirect('/login-profesionista');
        $this->assertGuest('profesionista');
    }

    public function test_the_user_password_reset_notification_and_form_are_connected(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPassword::class);

        $this->get('/reset-password/test-token')->assertOk()->assertViewIs('auth.reset-password');
    }

    public function test_a_valid_reset_changes_only_the_target_user_password(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $oldOtherPassword = $other->password;
        $token = Password::broker('users')->createToken($user);

        $this->post('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
        $this->assertSame($oldOtherPassword, $other->fresh()->password);
        $this->assertFalse(Password::broker('users')->tokenExists($user, $token));
    }

    public function test_an_invalid_reset_does_not_change_the_password(): void
    {
        $user = User::factory()->create();
        $oldPassword = $user->password;

        $this->post('/reset-password', [
            'email' => $user->email, 'token' => 'invalid-token',
            'password' => 'replacement-password', 'password_confirmation' => 'replacement-password',
        ])->assertSessionHasErrors('email');

        $this->assertSame($oldPassword, $user->fresh()->password);
    }
}
