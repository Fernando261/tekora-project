<?php

namespace App\Support;

use App\Enums\ServiceModality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

final class MarketplaceFilters
{
    public static function trustRules(): array
    {
        return [
            'municipality' => ['nullable', Rule::in(array_keys(Municipalities::NAMES))],
            'verified' => ['nullable', 'boolean'],
        ];
    }

    public static function search(Builder $query, string $term, array $columns): void
    {
        $literal = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $query->where(function (Builder $search) use ($literal, $columns) {
            foreach ($columns as $column) {
                $search->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$literal]);
            }
        });
    }

    public static function services(Builder $query, array $filters, bool $search = true): Builder
    {
        if ($search && isset($filters['q']) && $filters['q'] !== '') {
            self::search($query, $filters['q'], ['title', 'description']);
        }
        if (! empty($filters['category'])) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }
        if (! empty($filters['modality'])) {
            $query->whereIn('modality', array_unique([$filters['modality'], ServiceModality::Both->value]));
        }
        if (! empty($filters['municipality'])) {
            $query->whereHas('location', fn (Builder $location) => $location->where('municipality_slug', $filters['municipality']));
        }
        if ($search && ! empty($filters['verified'])) {
            $query->whereHas('providerProfile.credential', fn (Builder $credentials) => $credentials->withApprovedCertificate());
        }

        return $query;
    }

    public static function providers(Builder $query, array $filters): Builder
    {
        if (isset($filters['q']) && $filters['q'] !== '') {
            self::search($query, $filters['q'], ['display_name', 'headline']);
        }
        if (! empty($filters['verified'])) {
            $query->whereHas('credential', fn (Builder $credentials) => $credentials->withApprovedCertificate());
        }

        return $query->whereHas('services', fn (Builder $services) => self::services($services->publiclyVisible(), $filters, search: false));
    }
}
