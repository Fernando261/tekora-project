<?php

namespace Tests\Support\Retired;

/** Record-only fixture for :memory: migration preservation and identity isolation. */
class Comment extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'comments';

    protected $guarded = [];
}
