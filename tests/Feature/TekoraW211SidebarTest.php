<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Services\InquiryReadTracking;
use App\Services\NotificationInbox;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DashboardFixtures;
use Tests\TestCase;

#[Group('web-w21')]
#[Group('web-w211')]
class TekoraW211SidebarTest extends TestCase
{
    use DashboardFixtures;

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }

    public static function roles(): array
    {
        return [
            'client only' => [[RoleName::Client], true, false, false],
            'provider only' => [[RoleName::Provider], false, true, false],
            'dual' => [[RoleName::Client, RoleName::Provider], true, true, false],
            'admin only' => [[RoleName::Admin], false, false, true],
            'dual with admin' => [[RoleName::Client, RoleName::Provider, RoleName::Admin], true, true, true],
            'no business role' => [[], false, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_grouping_preserves_every_authorized_link_and_excludes_forbidden_links(array $roles, bool $client, bool $provider, bool $admin): void
    {
        $user = $this->account(...$roles);
        $response = $this->actingAs($user)->get(route('account.index'))->assertOk();
        $xpath = $this->document($response->getContent());
        $expected = ['catalog.index', 'public-providers.index', 'account.index', 'notifications.index'];
        if ($client || $provider) {
            $expected[] = 'conversations.index';
        }
        if ($client) {
            $expected[] = 'reviews.index';
        }
        if ($client || $provider) {
            $expected[] = 'reservations.index';
        }
        if ($client) {
            array_push($expected, 'account.client', 'inquiries.index');
        }
        array_push($expected, ...($provider ? ['provider.dashboard', 'availability.edit', 'account.provider', 'provider-services.index', 'inquiries.received'] : ['account.activate-provider']));
        if ($admin) {
            $expected[] = 'identity.roles.index';
        }
        $expected[] = 'perfil';
        $expected = array_map(fn ($name) => route($name), $expected);
        $actual = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//nav[contains(@class,"tk-sidebar-navigation")]//a/@href')));
        $this->assertSame($expected, $actual);
        $this->assertSame(count($actual), count(array_unique($actual)), 'No duplicate navigation links.');
        $groups = ['Explorar', 'Mi cuenta'];
        if ($client) {
            $groups[] = 'Cliente';
        }
        $groups[] = 'Prestador';
        if ($admin) {
            $groups[] = 'Administración';
        }
        array_push($groups, 'Preferencias', 'Salir');
        $this->assertSame($groups, array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//*[@class="tk-nav-group"]/@aria-label'))));
        $this->assertSame(0, $xpath->query('//*[@class="tk-nav-group" and not(.//a or .//button)]')->length);
        $ids = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//@id')));
        $this->assertSame(count($ids), count(array_unique($ids)));
        $current = $xpath->query('//nav[contains(@class,"tk-sidebar-navigation")]//a[@aria-current="page"]');
        $this->assertSame(1, $current->length);
        $this->assertSame(route('account.index'), $current->item(0)->getAttribute('href'));
        $form = $xpath->query('//nav//form[contains(@class,"logout-form")]')->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('logout'), $form->getAttribute('action'));
        $this->assertSame(1, $xpath->query('.//input[@name="_token" and string-length(@value)>0]', $form)->length);
        $this->assertSame(1, $xpath->query('//details[@data-account-navigation]/summary')->length);
        $this->assertSame(0, $xpath->query('//details[@data-account-navigation and @open]')->length, 'SSR must not cause a mobile collapse after first paint.');
        $this->assertSame(0, $xpath->query('//details[contains(@class,"account-legacy")]/summary')->length);
        $this->assertSame(0, $xpath->query('//details[contains(@class,"account-legacy")]//a')->length);
        $response->assertSee('Los contadores del menú incluyen toda la actividad de tu cuenta.');
        if (! $provider) {
            $this->get(route('provider.dashboard'))->assertForbidden();
        }
        if (! $client) {
            $this->get(route('account.client'))->assertForbidden();
        }
    }

    public static function counters(): array
    {
        return ['zero' => [0, 0], 'positive' => [29, 5], 'three digits' => [125, 125], 'unavailable' => [null, 5]];
    }

    #[DataProvider('counters')]
    public function test_badges_keep_counts_accessible_labels_and_unavailable_state(?int $notifications, int $messages): void
    {
        $this->mock(NotificationInbox::class, fn ($mock) => $mock->shouldReceive('unreadCount')->once()->andReturn($notifications));
        $this->mock(InquiryReadTracking::class, fn ($mock) => $mock->shouldReceive('totalUnread')->once()->andReturn($messages));
        $response = $this->actingAs($this->account(RoleName::Client))->get(route('account.index'))->assertOk();
        $xpath = $this->document($response->getContent());
        foreach (['notifications.index' => $notifications, 'conversations.index' => $messages] as $route => $count) {
            $link = $xpath->query('//nav[contains(@class,"tk-sidebar-navigation")]//a[@href="'.route($route).'"]')->item(0);
            $badge = $xpath->query('./span[contains(@class,"tk-nav-badge")]', $link);
            $this->assertSame($count > 0 ? 1 : 0, $badge->length);
            if ($count > 0) {
                $this->assertSame((string) $count, trim($badge->item(0)->textContent));
                $this->assertStringContainsString((string) $count, $badge->item(0)->getAttribute('aria-label'));
                $this->assertStringContainsString($route === 'notifications.index' ? 'avisos de solicitudes, reservas y reseñas' : 'mensajes sin leer', $badge->item(0)->getAttribute('aria-label'));
            }
        }
        $this->assertSame($notifications === null ? 1 : 0, $xpath->query('//*[@aria-label="Contador de avisos temporalmente no disponible"]')->length);
        $this->assertSame(0, $xpath->query('//script[not(@src)]')->length);
    }

    public function test_real_account_wide_counts_render_29_notifications_and_5_messages_without_writes(): void
    {
        $dual = $this->account(RoleName::Client, RoleName::Provider);
        foreach (range(1, 29) as $index) {
            $inquiry = $this->inquiry(null, $dual);
            if ($index <= 5) {
                $this->message($inquiry->client, $inquiry);
            }
        }
        $this->assertSame(29, app(NotificationInbox::class)->unreadCount($dual));
        $this->assertSame(5, app(InquiryReadTracking::class)->totalUnread($dual));
        $tables = ['inquiry_notifications', 'activity_notifications', 'service_inquiry_reads', 'service_inquiry_messages'];
        $before = $this->reservationRows($tables);
        $response = $this->actingAs($dual)->get(route('account.index'))->assertOk();
        $xpath = $this->document($response->getContent());
        $this->assertSame(['29', '5'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xpath->query('//*[contains(@class,"tk-nav-badge")]'))));
        $this->assertSame($before, $this->reservationRows($tables));
    }

    public function test_horizontal_and_legacy_variants_keep_flat_navigation_and_original_counter_text(): void
    {
        $this->actingAs($this->account(RoleName::Client, RoleName::Provider));
        $this->mock(NotificationInbox::class, fn ($mock) => $mock->shouldReceive('unreadCount')->times(3)->andReturn(29));
        $this->mock(InquiryReadTracking::class, fn ($mock) => $mock->shouldReceive('totalUnread')->times(3)->andReturn(5));
        foreach (['<x-account-navigation horizontal />', '<x-account-navigation />', '<x-account-navigation horizontal tekora />'] as $template) {
            $html = Blade::render($template);
            $xpath = $this->document($html);
            $this->assertSame(0, $xpath->query('//*[@class="tk-nav-group"]')->length);
            $this->assertSame(0, $xpath->query('//*[contains(@class,"tk-nav-badge")]')->length);
            $this->assertSame(str_contains($template, 'horizontal tekora') ? $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " menu-item ")]')->length : 0, $xpath->query('//svg')->length);
            $this->assertStringContainsString('(29)', $html);
            $this->assertStringContainsString('(5)', $html);
            $this->assertSame(0, $xpath->query('//details//a')->length);
            $this->assertSame(1, $xpath->query('//form[@method="POST"]//input[@name="_token"]')->length);
        }
    }
}
