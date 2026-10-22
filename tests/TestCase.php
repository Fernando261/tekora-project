<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fallar antes de cualquier migración si se pierde el aislamiento.
        if (! $this->app->environment('testing')
            || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || ! empty(config('database.connections.sqlite.url'))) {
            throw new \LogicException('Las pruebas requieren SQLite :memory: sin DB_URL.');
        }

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }
}
