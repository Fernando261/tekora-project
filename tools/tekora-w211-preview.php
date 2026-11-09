<?php

// Reuse the guarded, memory-only W2 export, then add sidebar-specific states.
// Both exports skip this checkout's .env. The preview has no POST backend.
require __DIR__.'/tekora-w2-preview.php';

final class TekoraW211SidebarExport extends \Tests\TestCase
{
    use \Tests\Support\DashboardFixtures;

    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->useEnvironmentPath(sys_get_temp_dir());
        $app->loadEnvironmentFrom('tekora-sidebar-no-env-'.bin2hex(random_bytes(16)));
        if (is_file($app->environmentFilePath())) {
            throw new RuntimeException('Preview requires an absent environment file.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function exportSidebar(string $target, string $origin): void
    {
        $this->setUp();
        $this->app['url']->forceRootUrl($origin);
        $this->app->make(\Illuminate\Foundation\Vite::class)->useHotFile(sys_get_temp_dir().'/tekora-sidebar-no-hot-'.bin2hex(random_bytes(8)));
        $routes = json_decode(file_get_contents($target.'/routes.json'), true, flags: JSON_THROW_ON_ERROR);
        $save = function (string $uri, string $alias, string $name) use ($target, &$routes) {
            $response = $this->get($uri)->assertOk();
            file_put_contents($target.'/'.$name.'.html', $response->getContent());
            $routes[$alias] = $name.'.html';
        };
        $this->actingAs($this->account(\App\Enums\RoleName::Admin), 'web');
        $save('/cuenta', '/preview/admin', 'sidebar-admin');
        $all = $this->account(\App\Enums\RoleName::Client, \App\Enums\RoleName::Provider, \App\Enums\RoleName::Admin);
        $all->update(['name' => 'Alex · Cuenta dual']);
        $this->service($all);
        $this->actingAs($all, 'web');
        $save('/cuenta/prestador/dashboard', '/preview/dual-admin', 'sidebar-dual-admin');
        foreach (range(1, 125) as $unused) {
            $inquiry = $this->inquiry(null, $all);
            $this->message($inquiry->client, $inquiry);
        }
        if (app(\App\Services\NotificationInbox::class)->unreadCount($all) !== 125 || app(\App\Services\InquiryReadTracking::class)->totalUnread($all) !== 125) {
            throw new RuntimeException('Expected actual fixture counts of 125.');
        }
        $save('/cuenta', '/preview/large-counts', 'sidebar-large-counts');
        $this->mock(\App\Services\NotificationInbox::class, fn ($mock) => $mock->shouldReceive('unreadCount')->once()->andReturn(null));
        $save('/cuenta', '/preview/unavailable-count', 'sidebar-unavailable');
        file_put_contents($target.'/routes.json', json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        \Illuminate\Support\Facades\DB::disconnect();
        echo "Sidebar states exported. Real 125/125 counts; null state simulates an unavailable inbox.\n";
    }
}
try {
    (new TekoraW211SidebarExport('exportSidebar'))->exportSidebar($target, $origin);
} catch (\Throwable $exception) {
    fwrite(STDERR, 'Sidebar export failed: '.$exception->getMessage()."\n");
    exit(1);
}
