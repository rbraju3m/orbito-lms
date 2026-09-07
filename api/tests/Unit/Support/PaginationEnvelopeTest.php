<?php

declare(strict_types=1);

use App\Support\Http\Resources\BaseCollection;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class FakeResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id']];
    }
}

it('shapes offset pagination to exactly the documented keys', function (): void {
    $paginator = new LengthAwarePaginator(
        items: [['id' => 1], ['id' => 2]],
        total: 137,
        perPage: 20,
        currentPage: 2,
        options: ['path' => 'http://localhost/api/v1/courses'],
    );

    $payload = (new BaseCollection($paginator, FakeResource::class))
        ->response(Request::create('/api/v1/courses'))
        ->getData(true);

    expect(array_keys($payload))->toEqualCanonicalizing(['data', 'meta', 'links'])
        ->and(array_keys($payload['meta']))
        ->toEqualCanonicalizing(['current_page', 'per_page', 'total', 'last_page'])
        ->and(array_keys($payload['links']))
        ->toEqualCanonicalizing(['first', 'prev', 'next', 'last'])
        ->and($payload['meta']['total'])->toBe(137)
        ->and($payload['meta']['last_page'])->toBe(7)
        ->and($payload['data'])->toHaveCount(2);
});
