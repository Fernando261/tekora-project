<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Emotion extends Model
{
    protected $fillable = [
        'name', 'slug', 'image_path', 'subtitle', 'description', 'accent', 'order'
    ];
}