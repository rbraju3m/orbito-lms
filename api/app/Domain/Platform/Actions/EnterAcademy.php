<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;

/**
 * Puts a platform operator INSIDE an academy.
 *
 * `users.tenant_id` is one column, so an operator is in one academy at a time
 * and this is how they move. Everything downstream already reads that column:
 * `InitializeTenancyByAuthenticatedUser` opens the schema it names, which is
 * what makes the whole product — catalogue, builder, player, grading — resolve
 * for an operator at all.
 *
 * The Super Admin role is (re)granted here rather than assumed. An academy
 * provisioned before the owner existed has no assignment, and entering one to
 * find no permissions is a confusing way to learn that.
 */
final class EnterAcademy
{
    public function __construct(private readonly EnsurePlatformOwner $owner) {}

    public function handle(User $operator, Tenant $tenant): User
    {
        // Not fillable — which academy an account sits in is decided here,
        // never by a request body.
        $operator->forceFill(['tenant_id' => $tenant->id])->save();

        $tenant->run(fn () => $this->owner->grantSuperAdmin($operator));

        return $operator->refresh();
    }
}
