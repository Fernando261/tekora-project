<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class Cita extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'citas';

    protected $guarded = [];
}
