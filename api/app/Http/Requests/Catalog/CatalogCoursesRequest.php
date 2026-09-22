<?php

declare(strict_types=1);

namespace App\Http\Requests\Catalog;

use App\Domain\Catalog\Enums\CourseLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The catalogue's filters — the members-only list and the public site's.
 *
 * Both controllers once read the query string raw, and every malformed value
 * reached SQL or an enum cast: `?level=bogus`, `?per_page=-3`, `?q[]=x` were
 * each a 500, on a route anybody on the internet can call. A filter in a URL
 * somebody can share has to be one the server can refuse politely.
 */
final class CatalogCoursesRequest extends FormRequest
{
    /** What is listed is decided by `Course::listed()`, not by who asks. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:120'],
            'level' => ['nullable', Rule::enum(CourseLevel::class)],
            'language' => ['nullable', 'string', 'max:20'],
            'price' => ['nullable', Rule::in(['free', 'paid'])],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'instructor' => ['nullable', 'uuid'],
            // `price_*` are in docs/API.md and still accepted; see applySort().
            'sort' => ['nullable', Rule::in(['popular', 'newest', 'rating', 'price_asc', 'price_desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function catalogFilters(): array
    {
        return collect($this->validated())->except(['page', 'per_page'])->all();
    }

    /** Over the cap is clamped rather than refused, as it always was. */
    public function perPage(): int
    {
        return min(
            (int) ($this->validated('per_page') ?? config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
