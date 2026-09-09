<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;

/**
 * The settings an academy changes about itself.
 *
 * Deliberately narrow. `slug` names the academy's database for its whole life
 * and `status` is the platform operator's lever, so neither is reachable from
 * here — an academy cannot reopen itself after being suspended, which is the
 * point of suspension.
 */
final class UpdateAcademySettings
{
    /** @param array{registration_mode?: string, support_email?: string|null} $changes */
    public function handle(Tenant $academy, array $changes): Tenant
    {
        if (array_key_exists('registration_mode', $changes)) {
            // Stored in the `data` blob as the string the enum round-trips.
            $academy->registration_mode = RegistrationMode::from(
                $changes['registration_mode']
            )->value;
        }

        if (array_key_exists('support_email', $changes)) {
            $academy->support_email = $changes['support_email'];
        }

        $academy->save();

        return $academy->refresh();
    }
}
