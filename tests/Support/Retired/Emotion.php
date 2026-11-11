<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class Emotion extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'emotions';

    protected $guarded = [];
}
