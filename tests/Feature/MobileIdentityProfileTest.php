<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Jobs\SendMobileIdentityCode;
use App\Models\User;
use App\Notifications\MobileIdentityCode;
use App\Services\MobileRegistrationProof;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IdentityPhotoFixtures;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

class MobileIdentityProfileTest extends TestCase
{
    use IdentityPhotoFixtures;
    use MobileOperationsFixtures;

    private const REGISTER = '/api/v1/auth/register';

    private const RECOVERY = '/api/v1/auth/forgot-password';

    private const RESET = '/api/v1/auth/reset-password';

    private const PROFILE = '/api/v1/me/profile';

    private const PHOTO = '/api/v1/me/photo';

    public function test_verified_registration_creates_only_a_central_client_and_can_be_retried(): void
    {
        Notification::fake();
        $email = 'nueva@example.test';
        $started = $this->postJson(self::REGISTER, ['email' => ' NUEVA@example.test '])->assertAccepted();
        $this->assertDatabaseCount('users', 0);
        $token = null;
        Notification::assertSentOnDemand(MobileIdentityCode::class, function ($notification, $channels, $recipient) use ($email, &$token) {
            $token = $notification->token;

            return $notification->registration && $recipient->routes['mail'] === $email;
        });
        $started->assertDontSee($token);
        $payload = $this->registration($email, $token);
        $confirmed = $this->postJson(self::REGISTER.'/confirm', $payload)->assertAccepted()->assertDontSee($token);
        $this->postJson(self::REGISTER.'/confirm', ['name' => 'Nombre distinto'] + $payload)->assertAccepted()->assertExactJson($confirmed->json());
        $this->postJson(self::REGISTER, ['email' => $email])->assertAccepted()->assertExactJson($started->json());
        $user = User::sole();
        $this->assertSame($email, $user->email);
        $this->assertSame($payload['name'], $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
        $this->assertDatabaseCount('provider_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $payload['password'], 'device_name' => 'prueba'])
            ->assertCreated()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_registration_proof_cannot_be_reused_for_another_email_or_change_roles(): void
    {
        $email = 'destino@example.test';
        $token = app(MobileRegistrationProof::class)->issue($email);
        $path = self::REGISTER.'/confirm';
        $this->postJson($path, $this->registration('otro@example.test', $token))->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['token']]])->assertDontSee($token);
        $this->postJson($path, $this->registration($email, $token) + ['roles' => ['provider'], 'email_verified_at' => now()->toISOString()])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['request']]]);
        $this->postJson($path, ['password' => str_repeat('á', 40), 'password_confirmation' => str_repeat('á', 40)] + $this->registration($email, $token))
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $this->postJson($path, ['age' => '30'] + $this->registration($email, $token))
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['age']]]);
        $this->travel(31)->minutes();
        $this->postJson($path, $this->registration($email, $token))->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['token']]]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_confirmation_keeps_existing_combined_role_account(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider);
        $user->update(['email' => 'Existing@example.test']);
        $before = $user->refresh()->getRawOriginal();
        $email = 'existing@example.test';
        $proof = app(MobileRegistrationProof::class)->issue($email);
        $this->postJson(self::REGISTER.'/confirm', $this->registration($email, $proof))->assertAccepted();
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertSame(['client', 'provider'], $user->roles()->orderBy('slug')->pluck('slug')->all());

        $provider = $this->account(RoleName::Provider);
        $proof = app(MobileRegistrationProof::class)->issue($provider->email);
        $this->postJson(self::REGISTER.'/confirm', $this->registration($provider->email, $proof))->assertAccepted();
        $this->assertSame(['provider'], $provider->roles()->pluck('slug')->all());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_recovery_is_uniform_and_a_single_use_code_revokes_sanctum_tokens(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider);
        $other = $this->account(RoleName::Provider);
        $oldBearer = $this->bearer($client);
        Notification::fake();

        $started = $this->postJson(self::RECOVERY, ['email' => strtoupper($client->email)])->assertAccepted();
        foreach (['missing@example.test', $other->email] as $email) {
            $this->postJson(self::RECOVERY, ['email' => $email])->assertAccepted()->assertExactJson($started->json());
        }
        $notification = Notification::sent($client, MobileIdentityCode::class)->sole();
        $this->assertFalse($notification->registration);
        Notification::assertNotSentTo($other, MobileIdentityCode::class);
        $started->assertDontSee($notification->token);
        $payload = $this->resetPayload($client->email, $notification->token);
        $this->postJson(self::RESET, $payload)->assertOk()->assertExactJson(['data' => ['message' => 'Contraseña actualizada.']]);
        $this->postJson(self::RESET, $payload)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['token']]]);
        $this->assertTrue(Hash::check($payload['password'], $client->fresh()->password));
        $this->getJson('/api/v1/me', $oldBearer)->assertUnauthorized();
        $this->assertTrue($client->fresh()->hasRole(RoleName::Provider));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_invalid_and_expired_recovery_codes_have_the_same_error_and_do_not_mutate_credentials(): void
    {
        $client = $this->account(RoleName::Client);
        $token = Password::broker()->createToken($client);
        $password = $client->password;
        $failure = $this->postJson(self::RESET, $this->resetPayload($client->email, 'invalid-test-code'))->assertUnprocessable();
        $this->postJson(self::RESET, $this->resetPayload('missing@example.test', $token))->assertUnprocessable()->assertExactJson($failure->json());
        $this->travel(61)->minutes();
        $this->postJson(self::RESET, $this->resetPayload($client->email, $token))->assertUnprocessable()->assertExactJson($failure->json());
        $this->assertSame($password, $client->fresh()->password);
    }

    public function test_web_password_reset_keeps_its_redirect_and_invalidates_mobile_access(): void
    {
        $client = $this->account(RoleName::Client);
        $oldBearer = $this->bearer($client);
        $previousRememberToken = $client->remember_token;
        $payload = $this->resetPayload($client->email, Password::broker()->createToken($client));

        $this->post(route('password.update'), $payload)->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Contraseña actualizada correctamente');
        $this->assertGuest('web');
        $this->assertNotSame($previousRememberToken, $client->fresh()->remember_token);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->getJson('/api/v1/me', $oldBearer)->assertUnauthorized();
        $this->postJson(self::RESET, $payload)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['token']]]);
        $this->postJson('/api/v1/auth/login', ['email' => $client->email, 'password' => $payload['password'], 'device_name' => 'prueba'])
            ->assertCreated()->assertJsonPath('data.user.id', $client->id);
    }

    public function test_reset_rolls_back_password_and_tokens_if_consumption_fails(): void
    {
        $client = $this->account(RoleName::Client);
        $this->bearer($client);
        $token = Password::broker()->createToken($client);
        $password = $client->password;
        DB::unprepared("CREATE TRIGGER block_token_delete BEFORE DELETE ON password_reset_tokens BEGIN SELECT RAISE(ABORT, 'internal synthetic SQL'); END");
        $payload = $this->resetPayload($client->email, $token);
        $this->postJson(self::RESET, $payload)->assertServiceUnavailable()->assertDontSee('internal synthetic SQL')->assertDontSee($token);
        $this->assertSame($password, $client->fresh()->password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        DB::unprepared('DROP TRIGGER block_token_delete');
        $this->postJson(self::RESET, $payload)->assertOk();
    }

    public function test_overlapping_confirmations_cannot_replace_the_account_created_by_the_first_request(): void
    {
        $this->sharedIdentityMemory();
        $email = 'race@example.test';
        $payload = $this->registration($email, app(MobileRegistrationProof::class)->issue($email));
        $replay = ['name' => 'Otro nombre', 'password' => 'other-test-password', 'password_confirmation' => 'other-test-password'] + $payload;
        $path = self::REGISTER.'/confirm';
        $writer = DB::connection('m28_a');
        $writer->beginTransaction();
        try {
            $this->postJson($path, $payload)->assertAccepted();
            DB::setDefaultConnection('m28_b');
            $this->postJson($path, $replay)->assertServiceUnavailable();
        } finally {
            $writer->commit();
        }
        $this->postJson($path, $replay)->assertAccepted();
        $user = User::sole();
        $this->assertSame($payload['name'], $user->name);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertSame(['client'], $user->roles()->pluck('slug')->all());
    }

    public function test_overlapping_resets_leave_only_the_first_password_and_consume_the_token(): void
    {
        $this->sharedIdentityMemory();
        $client = $this->account(RoleName::Client);
        $payload = $this->resetPayload($client->email, Password::broker()->createToken($client));
        $replay = ['password' => 'other-test-password', 'password_confirmation' => 'other-test-password'] + $payload;
        $writer = DB::connection('m28_a');
        $writer->beginTransaction();
        try {
            $this->postJson(self::RESET, $payload)->assertOk();
            DB::setDefaultConnection('m28_b');
            app()->forgetInstance('auth.password');
            Password::clearResolvedInstance('auth.password');
            $this->postJson(self::RESET, $replay)->assertServiceUnavailable();
        } finally {
            $writer->commit();
        }
        $this->postJson(self::RESET, $replay)->assertUnprocessable();
        $this->assertTrue(Hash::check($payload['password'], User::sole()->password));
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_public_flows_require_json_reject_query_fields_and_limit_normalized_addresses(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->post(self::REGISTER, ['email' => 'form@example.test'])->assertUnprocessable();
        $this->postJson(self::REGISTER.'?email=query@example.test', [])->assertUnprocessable();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::RECOVERY, ['email' => $i % 2 ? 'LIMIT@example.test' : 'limit@example.test'])->assertAccepted();
        }
        $this->postJson(self::RECOVERY, ['email' => 'limit@example.test'])->assertTooManyRequests()->assertHeader('Retry-After');
        Queue::assertPushed(SendMobileIdentityCode::class, 3);
        $this->assertContains(ShouldBeEncrypted::class, class_implements(SendMobileIdentityCode::class));
    }

    public function test_credentials_are_not_sent_via_the_log_mailer(): void
    {
        Notification::fake();
        config(['mail.default' => 'failover']);
        foreach ([self::REGISTER, self::RECOVERY] as $path) {
            $this->postJson($path, ['email' => 'test@example.test'])->assertServiceUnavailable()->assertDontSee('test@example.test');
        }
        Notification::assertNothingSent();
    }

    public function test_effective_log_transport_is_rejected_even_if_the_configured_mailer_is_smtp(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'log://localhost']);
        $this->assertInstanceOf(LogTransport::class, Mail::mailer()->getSymfonyTransport());
        Queue::fake();
        foreach ([self::REGISTER, self::RECOVERY] as $path) {
            $this->postJson($path, ['email' => 'synthetic@example.test'])->assertServiceUnavailable()->assertDontSee('synthetic@example.test');
        }
        Queue::assertNothingPushed();
    }

    public function test_smtp_url_is_accepted_without_contacting_a_mail_server(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://smtp.example.test:587', 'queue.default' => 'database']);
        Queue::fake();
        $this->postJson(self::REGISTER, ['email' => 'synthetic@example.test'])->assertAccepted();
        Queue::assertPushed(SendMobileIdentityCode::class, 1);
    }

    public function test_profile_is_bearer_scoped_and_only_the_authenticated_name_is_editable(): void
    {
        $client = $this->account(RoleName::Client, RoleName::Provider);
        $other = $this->account(RoleName::Client);
        $provider = $this->account(RoleName::Provider);
        $before = $other->refresh()->getRawOriginal();
        $this->getJson(self::PROFILE)->assertUnauthorized();
        $this->getJson(self::PROFILE, $this->bearer($provider))->assertForbidden();
        $profile = ['id' => $client->id, 'name' => $client->name, 'email' => $client->email,
            'age' => $client->age, 'gender' => $client->gender, 'email_verified' => true, 'photo_url' => null];
        $this->getJson(self::PROFILE, $this->bearer($client))->assertOk()->assertExactJson(['data' => $profile]);
        $this->patchJson(self::PROFILE, ['name' => 'Cambio rechazado', 'id' => $other->id, 'roles' => ['admin'], 'email_verified' => true], $this->bearer($client))
            ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['request']]]);
        $this->assertSame($profile['name'], $client->fresh()->name);
        $profile['name'] = 'Nombre actualizado';
        for ($i = 0; $i < 2; $i++) {
            $this->patchJson(self::PROFILE, ['name' => $profile['name']], $this->bearer($client))->assertOk()->assertExactJson(['data' => $profile]);
        }
        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }

    public function test_photo_uses_shared_storage_and_only_returns_the_callers_image(): void
    {
        $disk = $this->syntheticPhotoDisk();
        $client = $this->account(RoleName::Client);
        $other = $this->account(RoleName::Client);
        $old = 'avatars/avatar_'.$client->id.'_previous.png';
        $disk->put($old, $this->identityPhotoBytes());
        $client->forceFill(['profile_photo_path' => $old])->save();
        $headers = $this->bearer($client) + ['Content-Type' => 'multipart/form-data'];
        $this->post(self::PHOTO, ['photo' => UploadedFile::fake()->create('not-photo.txt', 1, 'text/plain')], $headers)->assertUnprocessable();
        $disk->assertExists($old);

        $bytes = $this->identityPhotoBytes('webp');
        $file = UploadedFile::fake()->createWithContent('profile.webp', $bytes);
        $this->post(self::PHOTO, ['photo' => $file], $headers)->assertOk()->assertJsonPath('data.photo_url', self::PHOTO)->assertDontSee($old);
        $path = $client->fresh()->profile_photo_path;
        $disk->assertMissing($old);
        $disk->assertExists($path);
        Auth::forgetGuards();
        $this->get(self::PHOTO, ['Authorization' => 'Bearer invalid-synthetic-token'])->assertUnauthorized();
        $this->get(self::PHOTO, $this->bearer($client))->assertOk()->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertContent($bytes);
        $other->forceFill(['profile_photo_path' => $path])->save();
        $this->getJson(self::PROFILE, $this->bearer($other))->assertOk()->assertJsonPath('data.photo_url', null);
        $this->getJson(self::PHOTO, $this->bearer($other))->assertNotFound();
    }

    public function test_a_symbolic_photo_alias_cannot_return_a_different_users_photo(): void
    {
        $disk = $this->syntheticPhotoDisk();
        $client = $this->account(RoleName::Client);
        $other = $this->account(RoleName::Client);
        $foreign = 'avatars/avatar_'.$other->id.'_actual.png';
        $alias = 'avatars/avatar_'.$client->id.'_alias.png';
        $disk->put($foreign, $this->identityPhotoBytes());
        if (! @symlink($disk->path($foreign), $disk->path($alias))) {
            $this->markTestSkipped('Synthetic storage does not support symbolic links.');
        }
        $client->forceFill(['profile_photo_path' => $alias])->save();

        $this->getJson(self::PROFILE, $this->bearer($client))->assertOk()->assertJsonPath('data.photo_url', null);
        $this->getJson(self::PHOTO, $this->bearer($client))->assertNotFound();
        $disk->assertExists($foreign);
    }

    public function test_openapi_documents_m28_without_changing_login_or_me_contracts(): void
    {
        $this->assertCurrentApiInventory();
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($this->m28ApiInventory() as $route) {
            $operation = $spec['paths'][substr($route['uri'], strlen('api/v1'))][strtolower($route['methods'][0])];
            $this->assertSame(in_array('auth:sanctum', $route['middleware'], true) ? [['bearerAuth' => []]] : [], $operation['security']);
            $this->assertArrayHasKey('429', $operation['responses']);
            $this->assertArrayHasKey('503', $operation['responses']);
        }
    }

    private function registration(string $email, string $token): array
    {
        return ['name' => 'Solicitante de prueba', 'age' => 30, 'gender' => 'Prefiero no decirlo'] + $this->resetPayload($email, $token);
    }

    private function resetPayload(string $email, string $token): array
    {
        return ['email' => $email, 'token' => $token, 'password' => 'synthetic-password', 'password_confirmation' => 'synthetic-password'];
    }

    private function syntheticPhotoDisk(): FilesystemAdapter
    {
        $previousStorage = storage_path();
        $syntheticStorage = sys_get_temp_dir().'/tekora-m28-photo-'.bin2hex(random_bytes(8));
        app()->useStoragePath($syntheticStorage);
        $this->beforeApplicationDestroyed(function () use ($previousStorage, $syntheticStorage) {
            app()->useStoragePath($previousStorage);
            (new Filesystem)->deleteDirectory($syntheticStorage);
        });

        return Storage::fake('public');
    }

    private function sharedIdentityMemory(): void
    {
        $uri = 'file:tekora-m28-'.bin2hex(random_bytes(8)).'?mode=memory&cache=shared';
        foreach (['m28_a', 'm28_b'] as $name) {
            config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri,
                'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 0]]);
        }
        DB::setDefaultConnection('m28_a');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'm28_a', '--force' => true]));
        $this->assertNotSame(DB::connection('m28_a')->getPdo(), DB::connection('m28_b')->getPdo());
        foreach (['m28_a', 'm28_b'] as $name) {
            $this->assertSame('', DB::connection($name)->select('PRAGMA database_list')[0]->file);
        }
    }
}
