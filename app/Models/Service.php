<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\ServiceModality;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = ['title', 'description', 'service_category_id', 'modality', 'status'];

    protected function casts(): array
    {
        return ['status' => ServiceStatus::class, 'modality' => ServiceModality::class];
    }

    public function providerProfile(): BelongsTo
    {
        return $this->belongsTo(ProviderProfile::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(ServiceInquiry::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceReview::class);
    }

    public function scopeWithPublishedPrice(Builder $query): void
    {
        // Correlated projection avoids N+1 reads and leaves mobile API contracts intact.
        $query->addSelect(['published_price_cents' => \Illuminate\Support\Facades\DB::table('service_price_versions')
            ->select('total_cents')->whereColumn('service_id', 'services.id')->orderByDesc('version')->limit(1)]);
    }

    // Una sola regla para catálogo, detalle y fichas públicas. No carga datos de User.
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->where('status', ServiceStatus::Published->value)
            ->whereHas('category', fn (Builder $categories) => $categories->active())
            ->whereHas('providerProfile.user.roles', fn (Builder $roles) => $roles->where('slug', RoleName::Provider->value));
    }
}
