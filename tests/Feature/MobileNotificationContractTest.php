<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MobileNotificationContractTest extends TestCase
{
    public function test_pre_m1_routes_remain_identical_with_explicit_m1_and_ux_c1_extensions(): void
    {
        $baseline = json_decode(file_get_contents(base_path('tests/Fixtures/m1-api-baseline.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('b04cf585b236c4eefbd4096e217cc46aaefef94e', $baseline['base_sha']);
        $this->assertCount(28, $baseline['routes']);
        $current = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $this->assertArrayNotHasKey($route->getName(), $current);
            $current[$route->getName()] = ['uri' => $route->uri(), 'methods' => $route->methods(), 'action' => $route->getActionName(),
                'middleware' => $route->gatherMiddleware(), 'wheres' => $route->wheres];
        }
        $m1 = ['api.v1.inquiries.journey' => true, 'api.v1.notifications.index' => true, 'api.v1.notifications.unread-count' => true, 'api.v1.notifications.read' => true];
        ksort($current);
        $current = array_diff_key($current, $this->mobileClientExtensions());
        $this->assertSame($baseline['routes'], array_diff_key($current, $m1));
        $this->assertCount(32, $current);
        $this->assertSame(['source_type' => 'inquiry|activity', 'id' => '[1-9][0-9]{0,18}'], $current['api.v1.notifications.read']['wheres']);
        $this->assertCurrentApiInventory();
    }

    public function test_openapi_describes_the_new_authenticated_operations_and_source_qualified_ids(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['/notifications' => 'get', '/notifications/unread-count' => 'get', '/notifications/{source_type}/{id}/read' => 'post'] as $path => $method) {
            $this->assertSame([['bearerAuth' => []]], $spec['paths'][$path][$method]['security']);
            foreach (['401', '403', '422', '429', '503'] as $status) {
                $this->assertArrayHasKey($status, $spec['paths'][$path][$method]['responses']);
            }
        }
        $example = $spec['components']['examples']['M1Notifications']['value'];
        $this->assertSame('inquiry:2', $example['data'][0]['id']);
        $this->assertSame('activity:2', $example['data'][1]['id']);
        foreach ($example['data'] as $notice) {
            $this->assertSame($notice['source_type'].':'.$notice['source_id'], $notice['id']);
            $this->assertArrayNotHasKey('detail_url', $notice);
            $this->assertArrayNotHasKey('user_id', $notice);
        }
        $this->assertSame(false, $spec['components']['schemas']['CheckoutContext']['properties']['future_payment']['properties']['can_start']['const']);
        $this->assertCount(39, glob(database_path('migrations/*.php')));
    }
}
