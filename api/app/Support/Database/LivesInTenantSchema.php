<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * For a tenant-schema model that a CENTRAL model has a relation to.
 *
 * Eloquent's `newRelatedInstance()` copies the parent's connection onto the
 * child whenever the child has none of its own. `User` is pinned to the
 * central connection, so `$user->courses()` would go looking for `courses` in
 * the central database — where it does not exist — and the failure reads as a
 * missing table rather than a crossed boundary.
 *
 * Returning the CURRENT default fixes it without hardcoding anything: stancl
 * rewrites `database.default` to 'tenant' on initialize and back on revert, so
 * this tracks whichever context the request is actually in, and is never
 * inherited from a pinned parent because it is never null.
 *
 * Add this to any tenant model that a central model points at. Nothing else
 * needs it — tenant-to-tenant relations already share the default connection,
 * and tenant-to-central ones inherit the central model's own pin.
 */
trait LivesInTenantSchema
{
    public function getConnectionName(): ?string
    {
        return config('database.default');
    }
}
