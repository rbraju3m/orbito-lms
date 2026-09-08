<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Gamification\Support\GamificationRegistry;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

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
    }
}
