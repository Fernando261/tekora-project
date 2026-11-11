<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use Tests\Support\Retired\Profesionista;
use App\Services\ClientDashboardSummary;
use App\Services\InquiryReadTracking;
use App\Services\NotificationInbox;
use App\Services\ReservationWorkflow;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

#[Group('web-w2')]
class ClientDashboardTest extends TestCase
{
    use DashboardFixtures;

    public function test_guests_legacy_provider_admin_and_unassigned_accounts_do_not_gain_client_access(): void
    {
        $this->get(route('account.client'))->assertRedirect(route('login'));
        foreach ([[], [RoleName::Provider], [RoleName::Admin]] as $roles) {
            $this->actingAs($this->account(...$roles), 'web')->get(route('account.client'))->assertForbidden();
        }
        auth('web')->logout();
        $legacy = Profesionista::create(['nombre' => 'Prueba legacy', 'email' => 'legacy-w2@example.test', 'password' => Hash::make('fixture-password'), 'cedula' => 'W2-TEST']);
        $this->actingAs($legacy, 'retired-fixture')->get(route('account.client'))->assertRedirect(route('login'));
    }

    public function test_client_dual_and_client_admin_accounts_have_truthful_empty_states_without_retired_links(): void
    {
        foreach ([[RoleName::Client], [RoleName::Client, RoleName::Provider], [RoleName::Client, RoleName::Admin]] as $roles) {
            $user = $this->account(...$roles);
            $response = $this->actingAs($user, 'web')->get(route('account.client'))->assertOk()->assertSee('Hola, '.$user->name)
                ->assertSee('Tu primera idea puede empezar hoy.')->assertSee('Tu próxima reserva está por llegar.')
                ->assertSee('Cuenta completa')->assertDontSee('/cuenta/citas', false)->assertSee('data-dashboard="client"', false);
            $summary = $response->viewData('summary');
            foreach (['active', 'confirmed', 'requested', 'unread', 'completed', 'cancelled', 'notifications'] as $key) {
                $this->assertSame(0, $summary[$key]);
            }
            $this->assertSame([], $summary['agenda']);
            $this->assertSame([], $summary['latest']);
        }
    }

    public function test_dual_account_only_counts_its_client_side_and_reuses_the_unread_predicate(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        $otherProvider = $this->account(RoleName::Provider);
        $own = $this->inquiry($dual, $otherProvider, InquiryStatus::Accepted);
        $own->service->update(['title' => 'SOLO-COMO-CLIENTE']);
        $this->inquiry($dual, $otherProvider);
        $this->inquiry($dual, $otherProvider, InquiryStatus::Rejected);
        $this->inquiry($dual, $otherProvider, InquiryStatus::Cancelled);
        $this->inquiry($dual, $otherProvider, InquiryStatus::Completed);
        $received = $this->inquiry(null, $dual, InquiryStatus::Accepted);
        $received->service->update(['title' => 'SOLO-COMO-PRESTADOR']);
        $first = $this->message($otherProvider, $own, 'BODY-PRIVATE-ONE');
        $this->message($otherProvider, $own, 'BODY-PRIVATE-TWO');
        $this->message($dual, $own, 'BODY-SELF');
        $this->message($received->client, $received, 'BODY-PROVIDER-SIDE');
        app(InquiryReadTracking::class)->mark($dual, $own->id, $this->readToken($dual, $own, collect([$first])));
        $foreign = $this->inquiry(null, $otherProvider, InquiryStatus::Accepted);
        $this->message($otherProvider, $foreign, 'FOREIGN-BODY');
        $summary = app(ClientDashboardSummary::class)->build($dual);
        $this->assertSame(2, $summary['active']);
        $this->assertSame(1, $summary['unread']);
        $this->assertSame(2, app(InquiryReadTracking::class)->totalUnread($dual));
        $this->assertSame(app(NotificationInbox::class)->unreadCount($dual), $summary['notifications']);
        $this->assertGreaterThan(0, $summary['notifications']);
        $this->assertCount(5, $summary['latest']);
        foreach ($summary['latest'] as $row) {
            $this->assertTrue(Gate::forUser($dual)->allows('view', \App\Models\ServiceInquiry::findOrFail($row['id'])));
        }
        $response = $this->actingAs($dual, 'web')->get(route('account.client'))->assertOk()->assertSee('SOLO-COMO-CLIENTE');
        foreach (['SOLO-COMO-PRESTADOR', 'BODY-PRIVATE', 'BODY-PROVIDER-SIDE', 'FOREIGN-BODY', $otherProvider->email, $received->client->email] as $secret) {
            $response->assertDontSee($secret);
        }
    }

    public function test_reservations_distinguish_status_future_boundary_and_utc_conversion(): void
    {
        [$client, $provider] = $this->reservationContext('America/New_York');
        $workflow = app(ReservationWorkflow::class);
        $make = function ($date, $state) use ($client, $provider, $workflow) {
            $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            $r = $this->proposeReservation($inquiry, compact('date'));
            if ($state === 'confirmed' || $state === 'completed') {
                $r = $workflow->decide($provider, $r->id, 'confirm', ['version' => 1]);
            }
            if ($state === 'cancelled') {
                $r = $workflow->decide($client, $r->id, 'cancel', ['version' => 1]);
            }
            if ($state === 'completed') {
                $this->travelTo(Carbon::parse($r->ends_at_utc, 'UTC'));
                $r = $workflow->decide($provider, $r->id, 'complete', ['version' => 2]);
            }

            return $r;
        };
        $make('2030-01-03', 'requested');
        $make('2030-01-04', 'confirmed');
        $confirmed = $make('2030-01-14', 'confirmed');
        $make('2030-01-15', 'requested');
        $make('2030-01-16', 'cancelled');
        $make('2030-01-06', 'completed');
        $this->travelTo(Carbon::parse('2030-01-10 12:00:00', 'UTC'));
        $summary = app(ClientDashboardSummary::class)->build($client);
        foreach (['confirmed', 'requested', 'completed', 'cancelled'] as $key) {
            $this->assertSame(1, $summary[$key]);
        }
        $this->assertSame([$confirmed->id], array_column($summary['agenda'], 'id'));
        $this->assertSame('14/01/2030 08:00', $summary['agenda'][0]['date']);
        $this->assertSame('09:00', $summary['agenda'][0]['end']);
        $this->assertSame('2030-01-14T14:00:00+00:00', $summary['agenda'][0]['iso']);
        $this->assertSame('America/Mexico_City', $summary['timezone']);
        $this->travelTo(Carbon::parse($confirmed->starts_at_utc, 'UTC'));
        $boundary = app(ClientDashboardSummary::class)->build($client);
        $this->assertSame(0, $boundary['confirmed']);
        $this->assertSame(1, $boundary['requested']);
        $this->assertSame([], $boundary['agenda']);
        $this->assertSame('confirmed', $confirmed->fresh()->status->value);
    }

    public function test_identity_queries_and_unknown_filters_are_rejected_without_selecting_another_account(): void
    {
        $client = $this->account(RoleName::Client);
        foreach (['user_id=999', 'client_user_id=999', 'provider_id=999', 'period=30', 'user_id[]=1', 'page=2', 'role=admin'] as $query) {
            $this->actingAs($client, 'web')->get(route('account.client').'?'.$query)->assertStatus(422)
                ->assertSee('Accede sin filtros de identidad')->assertDontSee('SQLSTATE');
        }
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_loaded_roles_cannot_bypass_revocation_and_names_are_escaped(): void
    {
        $client = $this->account(RoleName::Client);
        $client->update(['name' => '<script>PRIVATE-XSS</script>']);
        $this->actingAs($client)->get(route('account.client'))->assertOk()->assertSee('&lt;script&gt;PRIVATE-XSS&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE-XSS</script>', false);
        $client->load('roles');
        $client->roles()->detach();
        $this->get(route('account.client'))->assertForbidden();
    }

    public function test_opening_both_dashboards_is_read_only_including_markers_and_notifications(): void
    {
        [$client, $provider, $inquiry, $reservation] = $this->reviewContext();
        $this->publishReview($reservation);
        $this->message($provider, $inquiry);
        $own = $this->inquiry($provider, null, InquiryStatus::Accepted);
        $this->message($own->service->providerProfile->user, $own);
        $tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
        $before = $this->reservationRows($tables);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($provider, 'web')->get(route('account.client'))->assertOk();
        $this->get(route('provider.dashboard'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame($before, $this->reservationRows($tables));
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => preg_match('/^\s*(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|REPLACE)\b/i', $q['query']))));
    }

    public function test_lists_are_limited_ordered_and_queries_constant_without_private_bodies(): void
    {
        [$client, $provider, $inquiry] = $this->reservationContext();
        $workflow = app(ReservationWorkflow::class);
        $r = $this->proposeReservation($inquiry);
        $workflow->decide($provider, $r->id, 'confirm', ['version' => 1]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $small = app(ClientDashboardSummary::class)->build($client);
        $smallQueries = DB::getQueryLog();
        DB::disableQueryLog();
        for ($day = 8; $day <= 17; $day++) {
            $inquiry = $this->inquiry($client, $provider, InquiryStatus::Accepted);
            $r = $this->proposeReservation($inquiry, ['date' => sprintf('2030-01-%02d', $day)]);
            $workflow->decide($provider, $r->id, 'confirm', ['version' => 1]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $large = app(ClientDashboardSummary::class)->build($client);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(count($smallQueries), count($queries));
        $this->assertLessThanOrEqual(7, count($queries));
        $this->assertCount(5, $large['agenda']);
        $this->assertCount(5, $large['latest']);
        $this->assertSame(11, $large['confirmed']);
        $this->assertSame($small['agenda'][0]['id'], $large['agenda'][0]['id']);
        $ids = array_column($large['latest'], 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(body|comment|email)\b/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/^select\s+\*/i', $query['query']);
        }
    }

    public function test_database_failure_is_safe_and_unavailable_global_counter_is_not_faked_as_zero(): void
    {
        $client = $this->account(RoleName::Client);
        DB::statement('DROP TABLE activity_notifications');
        $this->actingAs($client, 'web')->get(route('account.client'))->assertOk()->assertSee('Avisos temporalmente no disponibles')->assertDontSee('0 notificaciones sin leer');
        DB::statement('DROP TABLE service_inquiry_reads');
        $this->get(route('account.client'))->assertStatus(503)->assertSee('no está disponible temporalmente')->assertDontSee('SQLSTATE')->assertDontSee('service_inquiry_reads');
    }
}
