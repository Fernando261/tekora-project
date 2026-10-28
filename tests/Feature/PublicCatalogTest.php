<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixtures;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use CatalogFixtures;

    public function test_visitors_can_explore_a_service_and_a_separate_public_provider_profile(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner, ['title' => 'Reparación de laptops']);
        $profile = $service->providerProfile;
        $this->get('/servicios')->assertOk()->assertSee('Reparación de laptops')->assertSee($profile->display_name);
        $this->get('/servicios/'.$service->id)->assertOk()->assertSee($service->description)->assertSee('sin verificación profesional');
        $this->get('/prestadores')->assertOk()->assertSee($profile->display_name)->assertSee('1 servicio publicado');
        $this->get('/prestadores/'.$profile->id)->assertOk()->assertSee($profile->display_name)->assertSee($profile->headline)
            ->assertSee($profile->bio)->assertSee('Reparación de laptops');
        $this->get('/perfiles-profesionales/'.$profile->id)->assertRedirect('/login');
        $this->actingAs($this->account(RoleName::Client), 'web')->get('/perfiles-profesionales/'.$profile->id)->assertForbidden();
    }

    public function test_drafts_and_paused_services_are_absent_from_every_public_route_even_for_the_owner(): void
    {
        $owner = $this->account(RoleName::Provider);
        $published = $this->service($owner, ['title' => 'Publicación visible']);
        foreach (['draft', 'paused'] as $status) {
            $private = $this->service($owner, ['title' => 'Oculto '.$status, 'status' => $status]);
            $this->get('/servicios')->assertDontSee($private->title);
            $this->get('/servicios/'.$private->id)->assertNotFound();
            $this->get('/prestadores/'.$private->provider_profile_id)->assertOk()->assertDontSee($private->title);
            $this->actingAs($owner, 'web')->get('/servicios/'.$private->id)->assertNotFound();
        }
        $this->get('/servicios/'.$published->id)->assertOk();
    }

    public function test_only_providers_with_visible_services_appear_and_pausing_the_last_one_hides_the_profile(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $draft = $this->service($this->account(RoleName::Provider), ['status' => 'draft']);
        $empty = $this->account(RoleName::Provider)->providerProfile()->create(['display_name' => 'Sin servicios']);
        $this->get('/prestadores')->assertOk()->assertSee($service->providerProfile->display_name)
            ->assertDontSee($draft->providerProfile->display_name)->assertDontSee('Sin servicios');
        $this->get('/prestadores/'.$draft->provider_profile_id)->assertNotFound();
        $this->get('/prestadores/'.$empty->id)->assertNotFound();
        $this->actingAs($owner, 'web')->patch('/cuenta/servicios/'.$service->id.'/estado', ['status' => 'paused'])->assertRedirect();
        $this->get('/prestadores/'.$service->provider_profile_id)->assertNotFound();
        $this->get('/prestadores')->assertDontSee($service->providerProfile->display_name);
    }

    public function test_category_deactivation_and_provider_role_revocation_hide_services_and_public_profiles(): void
    {
        $owner = $this->account(RoleName::Provider);
        $service = $this->service($owner);
        $service->category->update(['is_active' => false]);
        $this->get('/servicios')->assertOk()->assertDontSee($service->title);
        $this->get('/servicios/'.$service->id)->assertNotFound();
        $this->get('/prestadores/'.$service->provider_profile_id)->assertNotFound();
        $service->category->update(['is_active' => true]);
        $this->get('/servicios/'.$service->id)->assertOk();
        $owner->roles()->detach();
        $this->get('/servicios/'.$service->id)->assertNotFound();
        $this->get('/prestadores')->assertOk()->assertDontSee($service->providerProfile->display_name);
        $this->get('/prestadores/'.$service->provider_profile_id)->assertNotFound();
    }

    public function test_category_and_modality_filters_compose_and_include_both_when_remote_or_onsite_is_requested(): void
    {
        $owner = $this->account(RoleName::Provider);
        foreach (['remote', 'onsite', 'both'] as $modality) {
            $this->service($owner, ['title' => 'Oferta '.$modality, 'modality' => $modality]);
        }
        $this->service($owner, ['title' => 'Otra categoría', 'service_category_id' => $this->category('web')->id]);
        $this->get('/servicios?category=soporte-tecnico&modality=remote')->assertOk()->assertSee('Oferta remote')->assertSee('Oferta both')
            ->assertDontSee('Oferta onsite')->assertDontSee('Otra categoría');
        $this->get('/servicios?category=soporte-tecnico&modality=onsite')->assertOk()->assertSee('Oferta onsite')->assertSee('Oferta both')->assertDontSee('Oferta remote');
        $this->get('/servicios?modality=both')->assertOk()->assertSee('Oferta both')->assertDontSee('Oferta onsite')->assertDontSee('Oferta remote');
    }

    public function test_keyword_search_uses_bound_parameters_and_treats_like_wildcards_literally(): void
    {
        $owner = $this->account(RoleName::Provider);
        $this->service($owner, ['title' => 'Reparación móvil', 'description' => 'Diagnóstico con garantía del 100% y descuento.']);
        $this->service($owner, ['title' => 'Servicio de software', 'description' => 'Soporte para proyectos nuevos.']);
        $this->get('/servicios?q=garant%C3%ADa')->assertOk()->assertSee('Reparación móvil')->assertDontSee('Servicio de software');
        $this->get('/servicios?q=100%25')->assertOk()->assertSee('Reparación móvil')->assertDontSee('Servicio de software');
        $this->get('/servicios?q=0')->assertOk()->assertSee('Reparación móvil')->assertDontSee('Servicio de software');
        $this->get('/servicios?'.http_build_query(['q' => "%' OR 1=1 --"]))->assertOk()->assertSee('No encontramos servicios')
            ->assertDontSee('Reparación móvil')->assertDontSee('Servicio de software');
        $this->get('/servicios?q=_')->assertOk()->assertSee('No encontramos servicios');
    }

    public function test_search_pagination_is_stable_and_preserves_filters(): void
    {
        $owner = $this->account(RoleName::Provider);
        for ($i = 1; $i <= 14; $i++) $this->service($owner, ['title' => 'Servicio numerado '.$i]);
        $response = $this->get('/servicios?q=numerado&category=soporte-tecnico&modality=remote')->assertOk();
        $services = $response->viewData('services');
        $this->assertSame(14, $services->total());
        $this->assertCount(12, $services);
        $this->assertSame('Servicio numerado 14', $services->first()->title);
        $this->assertStringContainsString('q=numerado', $services->nextPageUrl());
        $this->assertStringContainsString('category=soporte-tecnico', $services->nextPageUrl());
        $this->assertStringContainsString('modality=remote', $services->nextPageUrl());
        $second = $this->get('/servicios?q=numerado&category=soporte-tecnico&modality=remote&page=2')->assertOk();
        $this->assertCount(2, $second->viewData('services'));
        $this->assertSame('Servicio numerado 2', $second->viewData('services')->first()->title);
    }

    public function test_invalid_filters_and_empty_results_have_clear_responses(): void
    {
        foreach (['q[]=bad', 'q='.str_repeat('a', 121), 'modality=admin', 'category=unknown', 'page=-1', 'page[]=1'] as $query) {
            $this->get('/servicios?'.$query)->assertStatus(422)->assertSee('Revisa los filtros de búsqueda');
        }
        $this->get('/servicios?q=nothing')->assertOk()->assertSee('No encontramos servicios');
        $this->get('/prestadores')->assertOk()->assertSee('Aún no hay prestadores con servicios publicados');
        $this->get('/servicios/99999')->assertNotFound();
        $this->get('/prestadores/99999')->assertNotFound();
    }

    public function test_public_html_escapes_content_and_does_not_disclose_private_identity_fields(): void
    {
        $owner = $this->account(RoleName::Provider);
        $owner->update(['name' => 'Private legal name', 'gender' => 'PRIVATE-GENDER', 'email' => 'private-address@example.test']);
        $service = $this->service($owner, ['title' => '<script>badTitle()</script>', 'description' => '<img src=x onerror=badDescription()>']);
        $profile = $service->providerProfile;
        $profile->update(['display_name' => '<script>badName()</script>', 'bio' => '<script>badBio()</script>']);
        foreach (['/servicios', '/servicios/'.$service->id, '/prestadores', '/prestadores/'.$profile->id] as $path) {
            $response = $this->get($path)->assertOk();
            foreach (['private-address@example.test', 'PRIVATE-GENDER', 'Private legal name', $owner->password, '<script>bad', '<img src=x onerror='] as $private) {
                $response->assertDontSee($private, false);
            }
            $response->assertSee('&lt;script&gt;badName()', false);
        }
        $this->get('/prestadores/'.$profile->id)->assertSee('&lt;script&gt;badBio()', false);
    }

    public function test_catalog_eager_loading_keeps_query_count_constant_as_the_page_grows(): void
    {
        $owner = $this->account(RoleName::Provider);
        $this->service($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/servicios')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 15; $i++) $this->service($owner);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/servicios')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(6, $large);
    }

    public function test_directory_and_provider_services_are_paginated(): void
    {
        for ($i = 0; $i < 13; $i++) $this->service($this->account(RoleName::Provider));
        $directory = $this->get('/prestadores')->assertOk()->viewData('providers');
        $this->assertSame(13, $directory->total());
        $this->assertCount(12, $directory);
        $owner = $this->account(RoleName::Provider);
        for ($i = 0; $i < 13; $i++) $this->service($owner);
        $profile = $owner->providerProfile()->firstOrFail();
        $first = $this->get('/prestadores/'.$profile->id)->assertOk()->viewData('services');
        $second = $this->get('/prestadores/'.$profile->id.'?page=2')->assertOk()->viewData('services');
        $this->assertSame(13, $first->total());
        $this->assertCount(12, $first);
        $this->assertCount(1, $second);
    }
}
