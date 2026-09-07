<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\User;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The payload for GET /auth/me.
 *
 * It includes the caller's resolved permission keys so the SPA can hide UI it
 * may not use. That is a convenience, not a control: every endpoint authorizes
 * independently and must be tested for the denied path.
 *
 * @mixin User
 */
final class AuthenticatedUserResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => UserResource::make($this->resource)->resolve($request),
            'roles' => $this->roleKeys(),
            'permissions' => $this->globalPermissionKeys(),
            'is_instructor' => $this->isApprovedInstructor(),
            'must_verify_email' => ! $this->hasVerifiedEmail(),
        ];
    }
}
