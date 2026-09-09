<?php

declare(strict_types=1);

namespace App\Domain\Platform\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Stancl\Tenancy\Tenancy;

/**
 * Opens an academy for a user who has JUST authenticated.
 *
 * The `tenant` middleware cannot do this. It reads the authenticated user to
 * decide which schema to open, and on the login and register routes there is
 * no user until the controller has run — which is exactly why those routes sit
 * outside it (§ Multi-tenancy).
 *
 * That left a hole the test harness could not see. Login answers with the
 * caller's roles and permissions, and `role_assignments` is a TENANT table, so
 * the payload was being built against the central connection: "Base table or
 * view not found: role_assignments". Every test passes because the harness
 * leaves an academy open for the whole test — the same class of bug
 * `ScheduledCommandTest` exists to defeat, arriving on the busiest route in
 * the API.
 *
 * When no academy can be opened the caller genuinely HAS no academy roles, so
 * the relation is set to empty rather than left unloaded. Unloaded would lazy
 * load on the central connection and reintroduce the same 500.
 */
final class AuthenticatedAcademy
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Open the caller's academy, then load everything the session payload
     * reads — in that order, because most of it lives in the academy.
     *
     * `roleAssignments` AND `instructor_profiles` are both tenant tables, and
     * so is anything a later phase adds to this payload. Loading first and
     * opening second fails on whichever one it reaches, so the order here is
     * the whole point of the class.
     */
    public function prepare(User $user): User
    {
        $opened = $this->open($user);

        // `users` and `tenants` are central, so this is safe either way.
        return $this->loadPayloadRelations($user->fresh(['tenant']) ?? $user, $opened);
    }

    /**
     * The same payload for a request the `tenant` middleware has already
     * handled — `GET /auth/me`.
     *
     * It reaches here with the academy open for a member, and WITHOUT one for
     * a platform operator who has entered none. That second case must answer
     * rather than 500: it is the endpoint the SPA uses to discover it has no
     * academy and offer the registry.
     */
    public function forCurrentRequest(User $user): User
    {
        return $this->loadPayloadRelations(
            $user->loadMissing(['tenant', 'socialLinks']),
            $this->tenancy->initialized,
        );
    }

    private function loadPayloadRelations(User $user, bool $opened): User
    {
        if ($opened) {
            $user->load(['roleAssignments.role.permissions', 'instructorProfile']);

            return $user;
        }

        /*
         * Set, not left unloaded. An unloaded relation lazy loads the moment
         * the resource asks, on the central connection, which is exactly the
         * 500 this class exists to prevent. Empty is also the truth: somebody
         * inside no academy holds no roles and has no instructor profile.
         */
        $user->setRelation('roleAssignments', new Collection);
        $user->setRelation('instructorProfile', null);

        return $user;
    }

    private function open(User $user): bool
    {
        /*
         * Already inside one. Respect it rather than deciding again — the
         * caller opened it, and anything just written for this user is in THAT
         * schema.
         *
         * Registration no longer depends on this: `RegisterUser` now takes the
         * academy explicitly and sets `tenant_id`, so the branch below finds
         * it. The short-circuit stays because closing an academy the caller
         * opened, mid-request, is never this class's decision to make.
         */
        if ($this->tenancy->initialized) {
            return true;
        }

        // A platform operator may belong to no academy at all. Theirs is the
        // central surface; they hold no roles until they enter one.
        if ($user->tenant_id === null) {
            return false;
        }

        $tenant = Tenant::find($user->tenant_id);

        // A closed academy is not opened here. `GET /auth/me` refuses it with
        // a 403 a moment later, and answering the login with permissions for
        // an academy they cannot reach would be the more confusing of the two.
        if ($tenant === null || ! $tenant->isOpen()) {
            return false;
        }

        $this->tenancy->initialize($tenant);

        return true;
    }
}
