<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class ProfesionistaDisponibilidad extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'profesionista_disponibilidades';

    protected $guarded = [];
}
