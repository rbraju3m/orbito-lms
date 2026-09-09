<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Exceptions\RegistrationNotOpen;
use App\Domain\Platform\Models\Tenant;

/**
 * Turns the slug in a signup link into the academy a registration belongs to,
 * or refuses.
 *
 * The one place `POST /auth/register` learns which academy it is writing into.
 * Before this existed there was no answer: tenancy resolves from the
 * authenticated user and registration has none, so the account was created
 * with a null `tenant_id` and its Student role was written into whichever
 * academy happened to be open.
 *
 * Existence is checked HERE rather than with an `exists:` rule in the Form
 * Request, deliberately. "This academy is invitation only" and "there is no
 * such academy" are different answers, and a validation rule can only give the
 * second — the same reason an id that merely exists is not treated as
 * authorized anywhere else in this codebase.
 */
final class ResolveSignupAcademy
{
    public function handle(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();

        if ($tenant === null) {
            throw RegistrationNotOpen::noSuchAcademy();
        }

        // Pending, suspended or rejected. Its schema may not even exist, so
        // this must be answered before anything opens a connection to it.
        if (! $tenant->isOpen()) {
            throw RegistrationNotOpen::academyClosed();
        }

        $mode = $tenant->registrationMode();

        if (! $mode->allowsSelfSignup()) {
            throw RegistrationNotOpen::mode($mode);
        }

        return $tenant;
    }
}
