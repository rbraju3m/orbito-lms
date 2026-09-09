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

            /*
             * The two super-admin answers, which are unrelated (see
             * ../CLAUDE.md § Multi-tenancy). `is_platform_operator` is the
             * central flag that opens the academy registry; the Super Admin
             * ROLE is already in `roles` above.
             *
             * `academy` is which academy the caller is inside. For a member
             * that is the only one they will ever see; for an operator it is
             * whichever they entered, and null when they entered none — which
             * is the state where the product screens have no data behind them.
             */
            'is_platform_operator' => $this->resource->is_super_admin,
            'is_platform_owner' => $this->isPlatformOwner(),
            'academy' => $this->resource->tenant !== null ? [
                'id' => $this->resource->tenant->id,
                'slug' => $this->resource->tenant->slug,
                'name' => $this->resource->tenant->name,
            ] : null,
        ];
    }
}
