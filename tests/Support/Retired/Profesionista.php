<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class Profesionista extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'profesionistas';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        // Only simulate an unrelated identity/session in isolated security tests.
        config(['auth.guards.retired-fixture' => ['driver' => 'session', 'provider' => 'retired-fixture'],
            'auth.providers.retired-fixture' => ['driver' => 'eloquent', 'model' => self::class]]);
    }
}
