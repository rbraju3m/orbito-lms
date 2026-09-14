<?php

declare(strict_types=1);

namespace App\Domain\Content\Support;

/**
 * The words a lead form asks somebody to agree to.
 *
 * The SERVER's declaration, rendered by the form and copied onto the lead when
 * it is stored — the `credentialFields()` rule applied to consent. A checkbox
 * label the client wrote would be a record of agreement to text nobody on our
 * side can vouch for.
 */
final class LeadConsent
{
    public static function for(string $academyName): string
    {
        return str_replace(':academy', $academyName, (string) config('orbito.leads.consent'));
    }
}
