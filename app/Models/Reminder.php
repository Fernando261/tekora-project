<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    public function user() {
    return $this->belongsTo(User::class);
}
}
