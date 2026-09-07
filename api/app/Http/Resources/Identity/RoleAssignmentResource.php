<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\RoleAssignment;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin RoleAssignment
 */
final class RoleAssignmentResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->whenLoaded('role', fn () => RoleResource::make($this->role)),
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'granted_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
