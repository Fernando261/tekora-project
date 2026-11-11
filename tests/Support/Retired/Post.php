<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class Post extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'posts';

    protected $guarded = [];
}
