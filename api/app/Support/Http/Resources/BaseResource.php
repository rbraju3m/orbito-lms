<?php

declare(strict_types=1);

namespace App\Support\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every API resource extends this. It exists so response shape decisions have
 * exactly one home and so `collection()` returns our paginated collection.
 */
abstract class BaseResource extends JsonResource
{
    public static function collection($resource): BaseCollection
    {
        return new BaseCollection($resource, static::class);
    }
}
