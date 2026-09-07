<?php

declare(strict_types=1);

namespace App\Support\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Normalises pagination to the documented contract (docs/API.md §2).
 *
 * Laravel's default paginated payload carries extra keys we do not document;
 * this trims it to exactly what clients are promised, and handles cursor
 * pagination with a different, also-documented shape.
 */
class BaseCollection extends ResourceCollection
{
    /**
     * @param  mixed  $resource
     * @param  class-string|null  $collects
     */
    public function __construct($resource, ?string $collects = null)
    {
        // Laravel guesses the item resource from the collection's class name,
        // which for this base class resolves to an abstract. Default to the
        // plain JsonResource so a collection of already-shaped arrays works.
        $this->collects = $collects ?? JsonResource::class;

        parent::__construct($resource);
    }

    /**
     * Returns a plain list, NOT ['data' => ...].
     *
     * Laravel adds the `data` wrapper at response level. Adding it here too
     * double-wraps any collection nested inside another resource, so
     * `category.children` arrives as `{data: [...]}` instead of `[...]`.
     *
     * @return list<mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->collection->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        $paginator = $this->resource;

        if ($paginator instanceof CursorPaginator) {
            return [
                'meta' => [
                    'per_page' => $paginator->perPage(),
                    'next_cursor' => $paginator->nextCursor()?->encode(),
                    'prev_cursor' => $paginator->previousCursor()?->encode(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ];
        }

        if ($paginator instanceof LengthAwarePaginator) {
            return [
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
                'links' => [
                    'first' => $paginator->url(1),
                    'prev' => $paginator->previousPageUrl(),
                    'next' => $paginator->nextPageUrl(),
                    'last' => $paginator->url($paginator->lastPage()),
                ],
            ];
        }

        return [];
    }

    /**
     * Suppress Laravel's own pagination block; `with()` above is the contract.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [];
    }
}
