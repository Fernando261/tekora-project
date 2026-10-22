<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;





class Profesionista extends Authenticatable
{
    use Notifiable;

    protected $table = 'profesionistas'; // Nombre de la tabla

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'cedula',
        'nombre','email','cedula',
        'google_access_token','google_refresh_token','google_calendar_id','google_token_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google_access_token',
        'google_refresh_token',
    ];
}
