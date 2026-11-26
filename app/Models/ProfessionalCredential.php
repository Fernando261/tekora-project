<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProfessionalCredential extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $hidden = ['user_id', 'license_number', 'public_license', 'evidence_path', 'evidence_mime'];

    public function scopeWithApprovedCertificate(Builder $query): void
    {
        $query->where('status', 'verified')->whereNotNull('evidence_path');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['public_license' => 'boolean', 'revision' => 'integer'];
    }
}
