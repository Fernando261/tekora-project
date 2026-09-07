<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfesionistaDisponibilidad extends Model
{
    protected $table = 'profesionista_disponibilidades';

    protected $fillable = [
        'profesionista_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_active',
    ];

    public $timestamps = true;

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */
    public function profesionista()
    {
        return $this->belongsTo(Profesionista::class);
    }
}
