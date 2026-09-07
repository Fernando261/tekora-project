<?php

// app/Models/Cita.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
  protected $table = 'citas';

  protected $fillable = [
  'user_id',
  'profesionista_id',
  'starts_at',
  'ends_at',
  'start_datetime',
  'end_datetime',
  'google_event_id',
  'status',
  'motivo',
  'estado',
];

  protected $casts = [
    'start_datetime' => 'datetime',
    'end_datetime' => 'datetime',
  ];

  public function user() { return $this->belongsTo(User::class); }
  public function profesionista() { return $this->belongsTo(Profesionista::class); }
}
