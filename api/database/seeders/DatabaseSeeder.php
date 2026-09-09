<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\ChangeTenantStatus;
use App\Domain\Platform\Actions\EnsurePlatformOwner;
use App\Domain\Platform\Actions\ProvisionTenant;
use App\Domain\Platform\Data\NewAcademy;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Seeds the CENTRAL database, then one demo academy.
 *
 * Almost everything this used to do — roles, instructor profiles, the
 * catalogue — now lives inside an academy's schema and cannot be written
 * without one open. So the shape changed: plans and the platform operator are
 * central, and everything else happens inside `$tenant->run()`.
 *
 * Note the two different "super admins", which are genuinely different things:
 * `users.is_super_admin` is the PLATFORM operator, who belongs to no academy
 * and runs the tenant registry; `RoleKey::SuperAdmin` is a role INSIDE an
 * academy that Gate::before grants everything on that academy's own data.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        /*
         * The permanent owner. Created here as well as after every migration,
         * because a seed-only install and a migrate-only deploy are both real
         * and both have to end with somebody able to sign in.
         */
        app(EnsurePlatformOwner::class)->handle();

        if (! app()->environment('local', 'testing')) {
            return;
        }

        /*
         * BELOW THIS LINE IS LOCAL ONLY, and the guard sits above it rather
         * than below for a reason found while auditing the README's claim that
         * this seeder "never creates demo accounts in production".
         *
         * It did. A second platform operator with the password `password` and
         * `is_super_admin` set was created before the environment check, so
         * `db:seed` on a live installation minted a known-credential account
         * that runs the academy registry. Anything with a fixed password
         * belongs after the guard; the permanent owner above is the one
         * exception, and its password is configurable and written once.
         */

        // A disposable operator for demos and fixtures. Unlike the owner this
        // one has no protections and can be deleted freely.
        User::firstOrCreate(
            ['email' => 'operator@orbito.test'],
            [
                'name' => 'Platform Operator',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        )->forceFill(['is_super_admin' => true, 'tenant_id' => null])->save();

        $this->demoAcademy();

        /*
         * Again, and deliberately. The first call ran before any academy
         * existed, so it could neither grant the Super Admin role nor put the
         * owner inside one. The Action is idempotent; this is the run that
         * lands them in the demo academy.
         */
        app(EnsurePlatformOwner::class)->handle();
    }

    private function demoAcademy(): void
    {
        $existing = Tenant::where('slug', 'demo-academy')->first();

        if ($existing !== null) {
            $this->command->info('Demo academy already provisioned.');

            return;
        }

        $tenant = app(ProvisionTenant::class)->handle(new NewAcademy(
            slug: 'demo-academy',
            name: 'Demo Academy',
            ownerName: 'Academy Owner',
            ownerEmail: 'owner@orbito.test',
            ownerPassword: 'password',
        ));

        // Provisioning leaves an academy pending; open it so the demo is usable.
        app(ChangeTenantStatus::class)->approve(
            $tenant,
            User::where('is_super_admin', true)->firstOrFail(),
        );

        $tenant->run(function () use ($tenant): void {
            $this->call(CatalogSeeder::class);

            $this->demoUser($tenant, 'Support Staff', 'staff@orbito.test', RoleKey::Staff);
            $this->demoUser($tenant, 'Student One', 'student@orbito.test', RoleKey::Student);

            $instructor = $this->demoUser($tenant, 'Instructor One', 'instructor@orbito.test', RoleKey::Instructor);
            $instructor->instructorProfile()->updateOrCreate([], [
                'status' => InstructorStatus::Approved,
                'applied_at' => now(),
                'reviewed_at' => now(),
            ]);

            $applicant = $this->demoUser($tenant, 'Pending Instructor', 'applicant@orbito.test', RoleKey::Student);
            $applicant->instructorProfile()->updateOrCreate([], [
                'status' => InstructorStatus::Pending,
                'applied_at' => now(),
                'application_source' => 'instructor_registration',
            ]);

            // Demo rows are written directly rather than through the Actions
            // that normally fire the counter events, so bring counters to truth.
            $this->call(UsageCounterSeeder::class);
        });
    }

    /**
     * The USER row is central; the ROLE assignment is not. This runs inside
     * `$tenant->run()`, which is what makes the second half possible at all.
     */
    private function demoUser(Tenant $tenant, string $name, string $email, RoleKey $role): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password', 'email_verified_at' => now()],
        );

        $user->forceFill(['tenant_id' => $tenant->id])->save();

        // Everyone is a student; elevated roles are additive.
        $user->assignRole(RoleKey::Student);

        if ($role !== RoleKey::Student) {
            $user->assignRole($role);
        }

        return $user->fresh() ?? $user;
    }
}
