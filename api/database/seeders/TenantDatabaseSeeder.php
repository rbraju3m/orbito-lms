<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Gamification\Support\GamificationRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PermissionRegistry;
use App\Domain\Platform\Actions\EnsurePlatformOwner;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Runs INSIDE a newly provisioned academy's schema, immediately after its
 * migrations (see TenancyServiceProvider).
 *
 * Roles and permissions moved into the tenant schema with `role_assignments`,
 * so every academy needs its own copy of the registry — an academy with no
 * roles is one where nobody can do anything, including its owner.
 *
 * The gamification registry is here for a quieter version of the same reason.
 * `gamification:sync` runs nightly and would eventually create the rules, but
 * "eventually" means a brand-new academy awards nothing for up to a day —
 * and the learners who notice are the first ones through the door.
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistry::class)->sync();
        app(GamificationRegistry::class)->sync();

        $this->platformOwner();
    }

    /**
     * The platform owner holds Super Admin in EVERY academy, including ones
     * created long after their account was.
     *
     * Granted here rather than by walking the academies afterwards, because a
     * brand-new academy is Pending — and `EnsurePlatformOwner` deliberately
     * skips anything that is not Active, since a suspended academy may have no
     * schema to write into at all.
     *
     * `users` is central, so this reads across the boundary on purpose: the
     * User model is pinned to the central connection and stays there even
     * though this seeder runs with an academy open.
     */
    private function platformOwner(): void
    {
        $email = trim((string) config('orbito.owner.email'));

        if ($email === '') {
            return;
        }

        $owner = User::where('email', $email)->first();

        // Null on a fresh install, where the first academy can be provisioned
        // before the owner row exists. `orbito:ensure-owner` picks it up.
        if ($owner === null) {
            return;
        }

        try {
            app(EnsurePlatformOwner::class)->grantSuperAdmin($owner);
        } catch (Throwable $e) {
            // Provisioning an academy must not fail because one role
            // assignment did; the account is repairable, a half-built schema
            // is not.
            report($e);
        }
    }
}
