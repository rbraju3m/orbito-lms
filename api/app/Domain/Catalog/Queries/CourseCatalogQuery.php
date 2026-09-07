<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Models\Course;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The catalogue read model.
 *
 * A purpose-built query with its eager loads declared here, rather than a
 * controller composing relations and hoping. Every filter maps to an index
 * declared in the migration.
 */
final class CourseCatalogQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Course>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->build($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Course>
     */
    public function build(array $filters): Builder
    {
        return Course::query()
            ->listed()
            ->with(['category', 'owner', 'thumbnail'])
            ->when(
                filled($filters['q'] ?? null),
                fn (Builder $q) => $q->whereFullText(['title', 'subtitle'], (string) $filters['q']),
            )
            ->when(
                filled($filters['category'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'category',
                    fn (Builder $c) => $c->where('slug', $filters['category']),
                ),
            )
            ->when(
                filled($filters['tags'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'tags',
                    fn (Builder $t) => $t->whereIn('slug', (array) $filters['tags']),
                ),
            )
            ->when(
                filled($filters['level'] ?? null),
                fn (Builder $q) => $q->where('level', CourseLevel::from((string) $filters['level'])),
            )
            ->when(
                filled($filters['language'] ?? null),
                fn (Builder $q) => $q->where('locale', $filters['language']),
            )
            ->when(
                ($filters['price'] ?? null) === 'free',
                fn (Builder $q) => $q->where('pricing_model', 'free'),
            )
            ->when(
                ($filters['price'] ?? null) === 'paid',
                fn (Builder $q) => $q->where('pricing_model', '!=', 'free'),
            )
            ->when(
                filled($filters['min_rating'] ?? null),
                fn (Builder $q) => $q->where('rating_avg', '>=', (float) $filters['min_rating']),
            )
            ->when(
                filled($filters['instructor'] ?? null),
                fn (Builder $q) => $q->whereHas(
                    'owner',
                    fn (Builder $o) => $o->where('uuid', $filters['instructor']),
                ),
            )
            ->tap(fn (Builder $q) => $this->applySort($q, (string) ($filters['sort'] ?? 'popular')));
    }

    /** @param  Builder<Course>  $query */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'newest' => $query->orderByDesc('published_at'),
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            // Price ordering becomes real in Phase 10; until then it degrades
            // to newest rather than silently returning an arbitrary order.
            'price_asc', 'price_desc' => $query->orderByDesc('published_at'),
            default => $query->orderByDesc('enrollment_count')->orderByDesc('published_at'),
        };
    }
}
