<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ServiceLocation extends Model
{
    protected $primaryKey = 'service_id';

    public $incrementing = false;

    public $timestamps = false;
}
