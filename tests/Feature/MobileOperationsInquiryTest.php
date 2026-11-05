<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MobileOperationsFixtures;
use Tests\TestCase;

#[Group('mobile-operations')]
class MobileOperationsInquiryTest extends TestCase
{
    use MobileOperationsFixtures;

    public function test_creation_duplicate_and_pending_cancel_preserve_workflow_and_history(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $body = ['message' => 'Necesito ayuda con mi computadora.'];
        $created = $this->postJson('/api/v1/services/'.$service->id.'/inquiries', $body, $this->bearer($client))->assertCreated();
        $id = $created->json('data.id');
        $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Otra descripción que no se guarda.'], $this->bearer($client))
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.message', $body['message']);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('inquiry_notifications', 1);
        $this->patchJson('/api/v1/inquiries/'.$id.'/cancel', [], $this->bearer($client))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->patchJson('/api/v1/inquiries/'.$id.'/cancel', [], $this->bearer($client))->assertConflict()->assertJsonPath('error.code', 'conflict');
        $this->getJson('/api/v1/inquiries/'.$id, $this->bearer($client))->assertOk()->assertJsonPath('data.message', $body['message']);
    }

    public function test_dual_actor_lists_and_conversations_only_include_client_experience(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $own = $this->inquiry($dual);
        $received = $this->inquiry($this->account(RoleName::Client), $dual);
        $this->message($dual, $received, 'Solo visible en experiencia prestador.');
        $this->message($own->service->providerProfile->user, $own);
        $this->getJson('/api/v1/inquiries', $this->bearer($dual))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->getJson('/api/v1/conversations', $this->bearer($dual))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inquiry_id', $own->id)->assertJsonPath('data.0.unread_messages_count', 1);
        foreach ([$received->id, 999999] as $id) {
            foreach (['', '/messages', '/reservation-options'] as $suffix) {
                $this->getJson('/api/v1/inquiries/'.$id.$suffix, $this->bearer($dual))->assertNotFound();
            }
            $this->postJson('/api/v1/inquiries/'.$id.'/messages', ['body' => []], $this->bearer($dual))->assertNotFound();
            $this->postJson('/api/v1/inquiries/'.$id.'/messages/read', ['read_token' => 'bad'], $this->bearer($dual))->assertNotFound();
            $this->postJson('/api/v1/inquiries/'.$id.'/reservations', [], $this->bearer($dual))->assertNotFound();
            $this->patchJson('/api/v1/inquiries/'.$id.'/cancel', [], $this->bearer($dual))->assertNotFound();
        }
    }

    public function test_hidden_paused_inactive_revoked_and_own_services_cannot_open_inquiries(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $own = $this->service($dual);
        $this->postJson('/api/v1/services/'.$own->id.'/inquiries', ['message' => 'Solicito mi propio servicio.'], $this->bearer($dual))->assertForbidden();
        foreach (['draft', 'paused'] as $status) {
            $service = $this->service($this->account(RoleName::Provider), ['status' => $status]);
            $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Una descripción suficiente.'], $this->bearer($dual))->assertNotFound();
        }
        $service = $this->service($this->account(RoleName::Provider));
        $service->category->update(['is_active' => false]);
        $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Una descripción suficiente.'], $this->bearer($dual))->assertNotFound();
        $this->assertDatabaseCount('service_inquiries', 0);
    }

    public function test_accepted_inquiry_cannot_be_cancelled_and_has_safe_conflict(): void
    {
        $inquiry = $this->inquiry(status: InquiryStatus::Accepted);
        $this->patchJson('/api/v1/inquiries/'.$inquiry->id.'/cancel', [], $this->bearer($inquiry->client))->assertConflict();
        $this->assertSame(InquiryStatus::Accepted, $inquiry->fresh()->status);
    }

    public function test_lists_validation_pagination_and_explicit_field_privacy(): void
    {
        $client = $this->account(RoleName::Client);
        $first = $this->inquiry($client);
        $last = $this->inquiry($client, status: InquiryStatus::Cancelled);
        $this->getJson('/api/v1/inquiries?per_page=1', $this->bearer($client))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $last->id);
        $this->getJson('/api/v1/inquiries?status=pending', $this->bearer($client))->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonCount(1, 'data');
        foreach (['per_page=51', 'page[]=1', 'client_user_id=1', 'status=unknown'] as $query) {
            $this->getJson('/api/v1/inquiries?'.$query, $this->bearer($client))->assertUnprocessable();
        }
        $response = $this->getJson('/api/v1/inquiries/'.$first->id, $this->bearer($client))->assertOk();
        foreach (['email', 'password', 'client_user_id', 'provider_profile_id', 'phone', 'roles', 'oauth'] as $secret) {
            $this->assertStringNotContainsString('"'.$secret.'"', $response->getContent());
        }
        foreach ([['message' => 'short'], ['message' => str_repeat('x', 2001)], ['message' => 'Suficiente descripción', 'status' => 'accepted']] as $data) {
            $this->postJson('/api/v1/services/'.$first->service_id.'/inquiries', $data, $this->bearer($client))->assertUnprocessable();
        }
    }

    public function test_bearer_expiration_revocation_abilities_and_current_role_apply_to_operations(): void
    {
        $user = $this->account(RoleName::Client, RoleName::Provider);
        $this->actingAs($user, 'web')->getJson('/api/v1/inquiries')->assertUnauthorized();
        $this->getJson('/api/v1/inquiries', $this->bearer($user))->assertOk();
        $user->tokens()->delete();
        $this->getJson('/api/v1/inquiries', $this->bearer($user))->assertUnauthorized();
        $wrong = $user->createToken('mobile:wrong', ['provider:write'], now()->addDay())->plainTextToken;
        Auth::forgetGuards();
        $this->getJson('/api/v1/inquiries', ['Authorization' => 'Bearer '.$wrong])->assertForbidden();
        $expired = $user->createToken('mobile:expired', ['client:read'], now()->subSecond())->plainTextToken;
        Auth::forgetGuards();
        $this->getJson('/api/v1/inquiries', ['Authorization' => 'Bearer '.$expired])->assertUnauthorized();
        $valid = $user->createToken('mobile:valid', ['client:read'], now()->addDay())->plainTextToken;
        $user->roles()->wherePivot('role_id', \App\Models\Role::where('slug', 'client')->value('id'))->detach();
        Auth::forgetGuards();
        $this->getJson('/api/v1/inquiries', ['Authorization' => 'Bearer '.$valid])->assertForbidden();
    }

    public function test_inquiry_rate_limit_uses_sanctum_identity_and_recovers(): void
    {
        $client = $this->account(RoleName::Client);
        $inquiry = $this->inquiry($client);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/services/'.$inquiry->service_id.'/inquiries', ['message' => 'Descripción suficiente'], $this->bearer($client))->assertOk();
        }
        $this->postJson('/api/v1/services/'.$inquiry->service_id.'/inquiries', ['message' => 'Descripción suficiente'], $this->bearer($client))
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/services/'.$inquiry->service_id.'/inquiries', ['message' => 'Descripción suficiente'], $this->bearer($client))->assertOk();
    }

    public function test_notification_failure_rolls_back_inquiry_and_returns_json_without_sql(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        DB::unprepared("CREATE TRIGGER fail_api_notice BEFORE INSERT ON inquiry_notifications BEGIN SELECT RAISE(ABORT, 'PRIVATE SQL'); END");
        config(['app.debug' => true]);
        \Illuminate\Support\Facades\Log::spy();
        $response = $this->postJson('/api/v1/services/'.$service->id.'/inquiries', ['message' => 'Texto privado no filtrado'], $this->bearer($client))->assertStatus(503)->assertJsonPath('error.code', 'temporarily_unavailable');
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('Texto privado', $response->getContent());
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('inquiry_notifications', 0);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('Fallo de almacenamiento en API móvil.', ['exception' => \Illuminate\Database\QueryException::class]);
    }
}
