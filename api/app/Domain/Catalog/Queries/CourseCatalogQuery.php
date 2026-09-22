<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Enums\CourseLevel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Models\User;
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
            // `product.prices` so the card can show a price without N+1 — the whole
            // point of denormalising nothing here (CLAUDE.md §2).
            ->with(['category', 'owner', 'thumbnail', 'product.prices'])
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
                // Users are central and courses are not: a `whereHas('owner')`
                // is one statement across two schemas (§ Multi-tenancy).
                // Resolve the id centrally, then filter here — `whereIn`, so
                // an unknown instructor matches nothing rather than `IS NULL`.
                fn (Builder $q) => $q->whereIn(
                    'owner_id',
                    User::query()->where('uuid', $filters['instructor'])->pluck('id')->all(),
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
            /*
             * NOT IMPLEMENTED, and Phase 10 did not change that: a price is a
             * row per currency on `product_prices`, so "cheapest first" has no
             * single answer to sort on. Accepted because docs/API.md lists it;
             * it degrades to newest rather than an arbitrary order. The SPA
             * does not offer it.
             */
            'price_asc', 'price_desc' => $query->orderByDesc('published_at'),
            default => $query->orderByDesc('enrollment_count')->orderByDesc('published_at'),
        };

        // A tiebreak, or a page boundary can split a tie so that a course
        // shows on two pages or on none (§ Patterns established in Phase 8).
        // `published_at` has second precision; the id does not repeat.
        $query->orderByDesc('id');
    }
}
