<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\TenantStatus;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Creates — and repairs — the one permanent account that runs this
 * installation. Idempotent, and safe to run on every deploy.
 *
 * The owner is BOTH kinds of super admin, which are otherwise unrelated things
 * (see ../CLAUDE.md § Multi-tenancy):
 *
 *  - `users.is_super_admin` — the platform operator. Central row, runs the
 *    academy registry, reaches a suspended academy.
 *  - `RoleKey::SuperAdmin` — a role INSIDE an academy, which `Gate::before`
 *    turns into everything on that academy's own data. Granted here in every
 *    academy, so entering any one of them works without a further grant.
 *
 * Which academy the owner is currently INSIDE is `users.tenant_id`, moved by
 * `EnterAcademy`. It is adopted here only when it is null and an academy
 * exists, so a fresh install lands somewhere usable rather than on a central
 * connection with no courses in it.
 *
 * The password is written ONCE, at creation. Rewriting it on every run would
 * make config the truth and revert a password the owner had changed — the same
 * create-only rule the gamification registry follows.
 */
final class EnsurePlatformOwner
{
    /** @return User|null null when no owner email is configured */
    public function handle(): ?User
    {
        $email = trim((string) config('orbito.owner.email'));

        if ($email === '') {
            return null;
        }

        // withTrashed: the model refuses deletion, but an account soft-deleted
        // before this feature existed must come back rather than collide with
        // a new row on the unique email index.
        $owner = User::withTrashed()->where('email', $email)->first();

        if ($owner === null) {
            $owner = new User;
            $owner->forceFill([
                'name' => (string) config('orbito.owner.name'),
                'email' => $email,
                // Cast to `hashed`; create-only, see the class docblock.
                'password' => (string) config('orbito.owner.password'),
            ]);
        }

        if ($owner->trashed()) {
            $owner->restore();
        }

        $owner->forceFill([
            'is_super_admin' => true,
            // A suspended owner is a locked-out installation.
            'status' => UserStatus::Active,
            'email_verified_at' => $owner->email_verified_at ?? now(),
            'tenant_id' => $owner->tenant_id ?? $this->firstAcademyId(),
        ])->save();

        $this->grantSuperAdminEverywhere($owner);

        return $owner->refresh();
    }

    /**
     * The role lives in each academy's schema, so this has to be written from
     * inside every one of them.
     *
     * Only ACTIVE academies are walked, for the same reason the scheduled
     * commands do: a suspended one may be mid-restore with no schema at all,
     * and failing on it would leave every later academy ungranted. A PENDING
     * academy is not skipped work — `TenantDatabaseSeeder` grants the role at
     * provisioning, before the academy is ever approved.
     *
     * One academy's failure must not stop the rest.
     */
    private function grantSuperAdminEverywhere(User $owner): void
    {
        // Whatever context the caller was in — normally none, since this runs
        // from a migration or a console command, but a test may be inside one.
        $original = tenancy()->initialized ? tenancy()->tenant : null;

        Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function (Collection $tenants) use ($owner): void {
                /** @var Collection<int, Tenant> $tenants */
                foreach ($tenants as $tenant) {
                    try {
                        $tenant->run(fn () => $this->grantSuperAdmin($owner));
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        // Restore rather than end: `initialize()` short-circuits on an already
        // active tenant, so ending unconditionally would purge a connection —
        // and with it the caller's open transaction. See RunsForEveryTenant.
        if ($original !== null) {
            tenancy()->initialize($original);
        } elseif (tenancy()->initialized) {
            tenancy()->end();
        }
    }

    /**
     * Assign the role inside whichever academy is currently open.
     *
     * Public so `TenantDatabaseSeeder` and `EnterAcademy` can reuse it without
     * walking every academy to grant one.
     */
    public function grantSuperAdmin(User $owner): void
    {
        // The permission memo and the loaded relation are both per-academy;
        // carrying them across a connection switch answers for the wrong one.
        $owner->forgetPermissionCache();

        if (! $owner->hasRole(RoleKey::SuperAdmin)) {
            $owner->assignRole(RoleKey::SuperAdmin);
        }

        $owner->forgetPermissionCache();
    }

    private function firstAcademyId(): ?string
    {
        $id = Tenant::query()
            ->where('status', TenantStatus::Active)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
