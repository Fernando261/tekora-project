<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_named_mindbloom_home_page_requires_a_user_session(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect('/login');
    }
}
