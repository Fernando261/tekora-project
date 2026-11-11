<?php

namespace Tests\Feature;

use App\Enums\InquiryAction;
use App\Enums\InquiryStatus;
use App\Enums\RoleName;
use App\Models\InquiryReadProgress;
use App\Models\ServiceInquiryMessage;
use App\Services\InquiryWorkflow;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

#[Group('web-w4')]
class TekoraW4Test extends TestCase
{
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $hot = tempnam(sys_get_temp_dir(), 'tekora-w4-hot-');
        if (is_file(public_path('build/manifest.json'))) {
            unlink($hot);
        } else {
            file_put_contents($hot, 'http://127.0.0.1:5173');
        }
        app(\Illuminate\Foundation\Vite::class)->useHotFile($hot);
        $this->beforeApplicationDestroyed(function () use ($hot) {
            if (is_file($hot)) {
                unlink($hot);
            }
        });
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    private function value(DOMXPath $xpath, string $name): string
    {
        return $xpath->query('//input[@name="'.$name.'"]/@value')->item(0)->nodeValue;
    }

    public static function operationalPages(): array
    {
        return array_map(fn ($name) => [$name], ['request-create', 'requests', 'received', 'detail', 'conversations', 'reservations', 'propose', 'reservation', 'reviews', 'review-create', 'review', 'availability', 'services', 'service-create', 'service-edit', 'notifications', 'profile', 'profile-show']);
    }

    #[DataProvider('operationalPages')]
    public function test_operational_pages_keep_native_content_security_and_opt_in_assets(string $page): void
    {
        [$client, $owner, $inquiry, $reservation] = $this->reviewContext();
        $review = $this->publishReview($reservation);
        $unreviewed = $this->completeForReview($inquiry);
        $service = $this->service($owner);
        $pending = $this->inquiry($client, $owner);
        $this->message($owner, $pending);
        $providerPages = ['received', 'availability', 'services', 'service-create', 'service-edit', 'profile', 'profile-show'];
        $this->actingAs(in_array($page, $providerPages) ? $owner : $client, 'web');
        $url = match ($page) {
            'request-create' => route('inquiries.create', $service), 'requests' => route('inquiries.index'),
            'received' => route('inquiries.received'), 'detail' => route('inquiries.show', $pending),
            'conversations' => route('conversations.index'), 'reservations' => route('reservations.index'),
            'propose' => route('reservations.create', $inquiry), 'reservation' => route('reservations.show', $reservation),
            'reviews' => route('reviews.index'), 'review-create' => route('reviews.create', $unreviewed),
            'review' => route('reviews.show', $review), 'availability' => route('availability.edit'),
            'services' => route('provider-services.index'), 'service-create' => route('provider-services.create'),
            'service-edit' => route('provider-services.edit', $service), 'notifications' => route('notifications.index'),
            'profile' => route('account.provider'), 'profile-show' => route('provider-profiles.show', $owner->providerProfile),
        };
        $response = $this->get($url)->assertOk()->assertSee('tk-ops')->assertSee('· Tekora')->assertDontSee('directorio de MindBloom');
        $xpath = $this->document($response->getContent());
        $this->assertSame(1, $xpath->query('//main//h1')->length);
        $this->assertSame(1, $xpath->query('//header[contains(@class,"tk-ops-heading")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-ops-scene and @aria-hidden="true"]')->length);
        $this->assertSame(0, $xpath->query('//script[not(@src)] | //@onclick | //@onchange | //@onerror')->length);
        $policy = $xpath->query('//meta[@http-equiv="Content-Security-Policy"]/@content')->item(0)->nodeValue;
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertSame(1, $xpath->query('//aside[@id="sidebar"]')->length);
        foreach ($xpath->query('//form[@method="POST"]') as $form) {
            $this->assertSame(1, $xpath->query('.//input[@name="_token"]', $form)->length);
        }
    }

    public function test_operational_bundle_is_absent_from_approved_dashboards_home_and_marketplace(): void
    {
        [$client, $owner, $inquiry] = $this->reservationContext();
        $this->actingAs($owner, 'web');
        foreach (['/', '/cuenta/cliente', '/cuenta/prestador/dashboard', '/servicios', route('catalog.show', $inquiry->service_id), '/prestadores', route('public-providers.show', $owner->providerProfile)] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(0, $this->document($html)->query('//body[contains(concat(" ",@class," ")," tk-ops ")]')->length);
            $this->assertStringNotContainsString('assets/operations-', $html);
            $this->assertStringNotContainsString('resources/js/tekora/operations.js', $html);
        }
    }

    public static function roles(): array
    {
        return [['guest'], ['client'], ['owner'], ['dual'], ['outsider']];
    }

    #[DataProvider('roles')]
    public function test_private_request_visibility_and_actions_still_follow_the_real_actor(string $role): void
    {
        $inquiry = $this->inquiry();
        if ($role !== 'guest') {
            $actor = match ($role) {
                'client' => $inquiry->client, 'owner' => $inquiry->service->providerProfile->user,
                'dual' => $this->account(RoleName::Client, RoleName::Provider), default => $this->account(RoleName::Client),
            };
            $this->actingAs($actor, 'web');
        }
        $response = $this->get(route('inquiries.show', $inquiry));
        if ($role === 'guest') {
            $response->assertRedirect(route('login'));
        } elseif (in_array($role, ['outsider', 'dual'])) {
            $response->assertForbidden()->assertDontSee($inquiry->message);
        } else {
            $response->assertOk()->assertSee($inquiry->message);
            $xpath = $this->document($response->getContent());
            $this->assertSame($role === 'owner' ? 1 : 0, $xpath->query('//form[@action="'.route('inquiries.accept', $inquiry).'"]')->length);
            $this->assertSame($role === 'client' ? 1 : 0, $xpath->query('//form[@action="'.route('inquiries.cancel', $inquiry).'"]')->length);
        }
    }

    public function test_request_form_keeps_old_text_limits_csrf_and_real_post(): void
    {
        $client = $this->account(RoleName::Client);
        $service = $this->service($this->account(RoleName::Provider));
        $text = '<script>W4-XSS</script> Necesito conversar sobre mi proyecto.';
        $response = $this->actingAs($client)->withSession(['_old_input' => ['message' => $text]])->get(route('inquiries.create', $service))->assertOk()->assertDontSee('<script>W4-XSS', false)->assertSee('no crea una reserva');
        $xpath = $this->document($response->getContent());
        $this->assertSame($text, $xpath->query('//textarea[@name="message"]')->item(0)->textContent);
        $this->assertSame('2000', $xpath->query('//textarea/@maxlength')->item(0)->nodeValue);
        $this->assertSame('10', $xpath->query('//textarea/@minlength')->item(0)->nodeValue);
        $this->post(route('inquiries.store', $service), ['message' => 'corto'])->assertSessionHasErrors('message');
        $this->post(route('inquiries.store', $service), ['message' => 'Necesito revisar la identidad visual de mi proyecto.'])->assertStatus(303);
        $this->assertDatabaseCount('service_inquiries', 1);
        $this->assertDatabaseCount('service_reservations', 0);
    }

    public function test_filtered_request_pagination_retains_the_status_and_received_scope(): void
    {
        $client = $this->account(RoleName::Client);
        $owner = $this->account(RoleName::Provider);
        for ($i = 0; $i < 13; $i++) {
            $this->inquiry($client, $owner);
        }
        $this->inquiry($client, $owner, InquiryStatus::Rejected);
        $this->actingAs($owner)->get(route('inquiries.received', ['status' => 'pending', 'page' => 2]))->assertOk()->assertSee('13 solicitudes')->assertSee('status=pending', false)->assertSee('Página 2 de 2');
        $this->get(route('inquiries.received', ['status' => 'bogus']))->assertStatus(422)->assertSee('Revisa los filtros');
        $this->actingAs($client)->get(route('inquiries.received'))->assertForbidden();
    }

    public function test_conversation_gets_do_not_write_and_read_post_is_bound_to_the_rendered_page(): void
    {
        $inquiry = $this->inquiry();
        $owner = $inquiry->service->providerProfile->user;
        for ($i = 0; $i < 23; $i++) {
            $this->message($owner, $inquiry, 'Mensaje recibido '.$i);
        }
        $this->actingAs($inquiry->client);
        $before = $this->reservationRows(['service_inquiry_messages', 'service_inquiry_reads', 'inquiry_notifications', 'activity_notifications']);
        $this->get(route('conversations.index'))->assertOk()->assertSee('23 mensajes recibidos sin leer');
        $detail = $this->get(route('inquiries.show', $inquiry))->assertOk();
        $this->assertSame($before, $this->reservationRows(['service_inquiry_messages', 'service_inquiry_reads', 'inquiry_notifications', 'activity_notifications']));
        $messages = $detail->viewData('messages');
        $xpath = $this->document($detail->getContent());
        $token = $this->value($xpath, 'read_token');
        $late = $this->message($owner, $inquiry, 'Posterior a la página servida');
        $this->post(route('conversations.read', $inquiry), ['read_token' => $token])->assertStatus(303);
        $this->assertSame($messages->getCollection()->max('id'), InquiryReadProgress::sole()->last_read_message_id);
        $this->assertGreaterThan(InquiryReadProgress::sole()->last_read_message_id, $late->id);
        $older = $this->get($messages->nextPageUrl())->assertOk()->assertSee('Mensajes más recientes');
        $this->assertCount(3, $older->viewData('messages'));
        $ids = $this->document($older->getContent())->query('//ol[@class="conversation-messages"]/li');
        $this->assertSame(3, $ids->length);
    }

    public function test_message_hidden_key_real_post_replay_conflict_and_readonly_still_work(): void
    {
        $inquiry = $this->inquiry();
        $this->actingAs($inquiry->client);
        $xpath = $this->document($this->get(route('inquiries.show', $inquiry))->assertOk()->getContent());
        $key = $this->value($xpath, 'idempotency_key');
        $data = ['body' => 'Mi mensaje real desde el formulario W4.', 'idempotency_key' => $key];
        $this->post(route('inquiries.messages.store', $inquiry), $data)->assertStatus(303);
        $this->post(route('inquiries.messages.store', $inquiry), $data)->assertStatus(303);
        $this->assertSame(1, ServiceInquiryMessage::where('idempotency_key', $key)->count());
        $this->post(route('inquiries.messages.store', $inquiry), ['body' => 'Otro texto', 'idempotency_key' => $key])->assertStatus(409)->assertSee('No se pudo enviar');
        app(InquiryWorkflow::class)->decide($inquiry->client, $inquiry->id, InquiryAction::Cancel);
        $response = $this->get(route('inquiries.show', $inquiry))->assertOk()->assertSee('modo solo lectura');
        $this->assertSame(0, $this->document($response->getContent())->query('//textarea[@name="body"]')->length);
        $this->post(route('inquiries.messages.store', $inquiry), ['body' => str_repeat('a', 2001), 'idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('body');
        $this->post(route('inquiries.messages.store', $inquiry), ['body' => 'Mensaje tardío', 'idempotency_key' => (string) Str::uuid()])->assertStatus(409);
    }

    public function test_reservation_form_keeps_revision_timezone_key_and_versioned_actions(): void
    {
        [$client, $owner, $inquiry] = $this->reservationContext();
        $this->actingAs($client);
        $html = $this->get(route('reservations.create', $inquiry))->assertOk()->assertSee('America/Mexico_City')->assertSee('60 minutos')->getContent();
        $xpath = $this->document($html);
        $key = $this->value($xpath, 'idempotency_key');
        $this->assertSame('1', $this->value($xpath, 'availability_revision'));
        $data = $this->reservationData(['idempotency_key' => $key]);
        $this->post(route('reservations.store', $inquiry), $data)->assertStatus(303);
        $this->post(route('reservations.store', $inquiry), $data)->assertStatus(303);
        $reservation = $inquiry->reservations()->sole();
        $this->assertSame('requested', $reservation->status->value);
        $this->assertSame('America/Mexico_City', $reservation->timezone);
        $this->actingAs($owner);
        $response = $this->get(route('reservations.show', $reservation))->assertOk()->assertSee('No es una reserva confirmada');
        $this->assertSame('1', $this->value($this->document($response->getContent()), 'version'));
        $this->patch(route('reservations.confirm', $reservation), ['version' => 0])->assertSessionHasErrors('version');
        $this->patch(route('reservations.confirm', $reservation), ['version' => 2])->assertStatus(409);
        $this->patch(route('reservations.confirm', $reservation), ['version' => 1])->assertStatus(303);
        $this->get(route('reservations.show', $reservation))->assertOk()->assertSee('confirmó este horario');
    }

    public function test_expired_reservation_never_offers_a_confirmation_action(): void
    {
        [$client, $owner, $inquiry] = $this->reservationContext();
        $reservation = $this->proposeReservation($inquiry);
        $this->travelTo(\Carbon\Carbon::parse($reservation->ends_at_utc, 'UTC')->addMinute());
        $response = $this->actingAs($owner)->get(route('reservations.show', $reservation))->assertOk()->assertSee('Propuesta expirada');
        $this->assertSame(0, $this->document($response->getContent())->query('//form[@action="'.route('reservations.confirm', $reservation).'"]')->length);
    }

    public function test_review_uses_five_native_radios_and_withdrawal_is_explicit_and_irreversible(): void
    {
        [$client, $owner, $inquiry, $reservation] = $this->reviewContext();
        $this->actingAs($client);
        $response = $this->get(route('reviews.create', $reservation))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(['1', '2', '3', '4', '5'], array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//input[@type="radio" and @name="rating"]/@value'))));
        $this->assertSame('1000', $xpath->query('//textarea[@name="comment"]/@maxlength')->item(0)->nodeValue);
        $this->post(route('reviews.store', $reservation), ['rating' => 6])->assertSessionHasErrors('rating');
        $this->post(route('reviews.store', $reservation), ['rating' => 4, 'comment' => 'Opinión real de prueba.'])->assertStatus(303);
        $review = $reservation->review()->sole();
        $this->get(route('reviews.show', $review))->assertOk()->assertSee('irreversible')->assertSee('tk-ops-danger');
        $this->patch(route('reviews.withdraw', $review))->assertStatus(303);
        $this->get(route('reviews.show', $review))->assertOk()->assertSee('Retirada')->assertDontSee('Retirar reseña de la vista pública');
        $this->post(route('reviews.store', $reservation), ['rating' => 5])->assertStatus(409);
        $this->assertDatabaseCount('service_reviews', 1);
        $this->get(route('catalog.show', $inquiry->service_id))->assertOk()->assertSee('Aún no hay reseñas verificadas');
    }

    public function test_availability_keeps_revision_old_rows_native_fields_and_42_interval_limit(): void
    {
        [$client, $owner] = $this->reservationContext();
        $this->actingAs($owner);
        $response = $this->withSession(['_old_input' => ['revision' => '1', 'timezone' => 'UTC', 'intervals' => [['day_of_week' => 1, 'start' => '10:00', 'end' => '12:00']], 'blocked_dates' => ['2030-02-12']]])->get(route('availability.edit'))->assertOk()->assertSee('value="10:00"', false)->assertSee('2030-02-12');
        $xpath = $this->document($response->getContent());
        $this->assertSame('1', $this->value($xpath, 'revision'));
        $this->assertSame(7, $xpath->query('//fieldset[@data-day]')->length);
        $this->assertGreaterThanOrEqual(14, $xpath->query('//form//input[@type="time"]')->length);
        $this->patch(route('availability.update'), $this->availabilityData(['revision' => 1, 'intervals' => array_fill(0, 43, ['day_of_week' => 1, 'start' => '09:00', 'end' => '10:00'])]))->assertSessionHasErrors('intervals');
        $this->assertSame(1, $owner->providerProfile->availabilitySetting()->sole()->revision);
    }

    public function test_service_states_inactive_categories_old_input_and_http_methods_are_preserved(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $this->actingAs($owner);
        $this->get(route('provider-services.index'))->assertOk()->assertSee('Pausar publicación')->assertSee('Retirar a borrador');
        $this->patch(route('provider-services.status', $service), ['status' => 'paused'])->assertStatus(303);
        $this->get(route('provider-services.index'))->assertOk()->assertSee('Volver a publicar')->assertDontSee('Ver publicación');
        $service->category->update(['is_active' => false]);
        $response = $this->withSession(['_old_input' => ['title' => '<script>W4-XSS</script>']])->get(route('provider-services.edit', $service))->assertOk()->assertSee('categoría anterior está inactiva')->assertDontSee('<script>W4-XSS', false);
        $this->assertSame('PUT', $this->value($this->document($response->getContent()), '_method'));
        $this->get(route('catalog.show', $service))->assertNotFound();
    }

    public function test_notification_get_never_marks_either_family_read_and_both_post_targets_remain(): void
    {
        [$client, $owner, $inquiry] = $this->reservationContext();
        $this->proposeReservation($inquiry);
        $this->actingAs($owner);
        $before = $this->reservationRows(['inquiry_notifications', 'activity_notifications']);
        $response = $this->get(route('notifications.index'))->assertOk()->assertSee('No leída');
        $this->assertSame($before, $this->reservationRows(['inquiry_notifications', 'activity_notifications']));
        $xpath = $this->document($response->getContent());
        $actions = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//main//form[@method="POST"]/@action')));
        $this->assertNotEmpty(array_filter($actions, fn ($url) => str_contains($url, '/actividad/')));
        $this->assertNotEmpty(array_filter($actions, fn ($url) => ! str_contains($url, '/actividad/')));
        foreach ($actions as $action) {
            $this->post($action)->assertStatus(303);
        }
        $this->assertSame(0, $owner->inquiryNotifications()->whereNull('read_at')->count());
        $this->assertSame(0, $owner->activityNotifications()->whereNull('read_at')->count());
    }

    public function test_rendered_operational_form_requires_csrf_and_does_not_bypass_permissions(): void
    {
        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, \Tests\Support\EnforcedCsrf::class);
        $client = $this->account(RoleName::Client);
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $this->actingAs($client)->withSession(['_token' => 'w4-csrf']);
        $xpath = $this->document($this->get(route('inquiries.create', $service))->assertOk()->getContent());
        $payload = ['message' => 'Necesito conversar sobre el proyecto de mi equipo.'];
        $this->post(route('inquiries.store', $service), $payload)->assertStatus(419);
        $this->post(route('inquiries.store', $service), $payload + ['_token' => 'wrong'])->assertStatus(419);
        $this->assertDatabaseCount('service_inquiries', 0);
        $this->actingAs($owner)->post(route('inquiries.store', $service), $payload + ['_token' => 'w4-csrf'])->assertForbidden();
        $this->actingAs($client)->post(route('inquiries.store', $service), $payload + ['_token' => $this->value($xpath, '_token')])->assertStatus(303);
        $this->assertDatabaseCount('service_inquiries', 1);
    }

    public static function fallbackViews(): array
    {
        return array_map(fn ($name) => [$name], ['inquiries.conflict', 'inquiries.message-error', 'inquiries.rate-limited', 'inquiries.invalid-filters', 'conversations.read-error', 'reservations.error', 'reviews.error', 'availability.error', 'notifications.error']);
    }

    #[DataProvider('fallbackViews')]
    public function test_failure_views_render_without_any_database_query_or_account_counters(string $view): void
    {
        $data = ['message' => 'Revisa el estado actual.', 'detailUrl' => '/cuenta/solicitudes', 'messages' => ['Filtro inválido.'], 'listRoute' => 'inquiries.index'];
        DB::enableQueryLog();
        DB::flushQueryLog();
        $html = view($view, $data)->render();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([], $queries);
        $xpath = $this->document($html);
        $this->assertSame(1, $xpath->query('//main//h1')->length);
        $this->assertSame(0, $xpath->query('//aside | //script[not(contains(@src,"@vite/client"))] | //form')->length);
        $this->assertStringContainsString('tk-ops-fallback', $html);
        $this->assertStringNotContainsString('SQLSTATE', $html);
    }

    public static function assetModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('assetModes')]
    public function test_operational_assets_reference_both_real_manifest_files_or_both_hot_entries(bool $build): void
    {
        $publicPath = public_path();
        $temporary = sys_get_temp_dir().'/tekora-w4-vite-'.bin2hex(random_bytes(8));
        mkdir($temporary, 0700);
        $vite = app(\Illuminate\Foundation\Vite::class);
        $hot = $temporary.'/hot';
        try {
            if ($build && ! is_file($publicPath.'/build/manifest.json')) {
                $this->markTestSkipped('Run npm ci and npm run build for the real manifest scenario.');
            }
            if (! $build) {
                app()->usePublicPath($temporary);
                file_put_contents($hot, 'http://127.0.0.1:5173');
            }
            $vite->useHotFile($hot);
            $this->actingAs($this->account(RoleName::Client));
            $xpath = $this->document($this->get(route('inquiries.index'))->assertOk()->getContent());
            $manifest = $build ? json_decode(file_get_contents($publicPath.'/build/manifest.json'), true) : [];
            foreach (['js', 'css'] as $type) {
                $entry = 'resources/'.$type.'/tekora/operations.'.$type;
                $path = $build ? 'build/'.$manifest[$entry]['file'] : $entry;
                $selector = $type === 'js' ? '//script[@type="module"]/@src' : '//link[@rel="stylesheet"]/@href';
                $urls = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query($selector)));
                $this->assertCount(1, array_filter($urls, fn ($url) => str_ends_with(parse_url($url, PHP_URL_PATH), '/'.$path)), $entry);
            }
        } finally {
            app()->usePublicPath($publicPath);
            $vite->useHotFile($publicPath.'/hot');
            if (is_file($hot)) {
                unlink($hot);
            }
            rmdir($temporary);
        }
    }

    public function test_all_operational_gets_are_read_only_and_api_history_are_unchanged(): void
    {
        [$client, $owner, $inquiry, $reservation] = $this->reviewContext();
        $this->actingAs($owner);
        $tables = DB::table('sqlite_master')->where('type', 'table')->where('name', 'not like', 'sqlite_%')->pluck('name')->all();
        $before = $this->reservationRows($tables);
        foreach (['/cuenta/solicitudes/recibidas', '/cuenta/mensajes', route('inquiries.show', $inquiry), '/cuenta/reservas', route('reservations.show', $reservation), '/cuenta/disponibilidad', '/cuenta/servicios', '/cuenta/prestador', '/cuenta/notificaciones'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame($before, $this->reservationRows($tables));
        $this->assertCount(37, glob(database_path('migrations/*.php')));
        $this->assertCount(21, array_filter(app('router')->getRoutes()->getRoutes(), fn ($route) => str_starts_with($route->uri(), 'api/')));
        $this->get('/mindbloom/inicio')->assertNotFound();
        $this->assertNull(app('router')->getRoutes()->getByName('profesionista.dashboard'));
    }
}
