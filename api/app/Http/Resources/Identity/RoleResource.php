<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\Role;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin Role
 */
final class RoleResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'scope' => $this->scope_kind->value,
            'is_system' => $this->is_system,
            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions->pluck('key')->sort()->values(),
            ),
        ];
    }
}
