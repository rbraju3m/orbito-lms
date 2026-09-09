<?php

declare(strict_types=1);

namespace App\Http\Resources\Platform;

use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An academy as its OWN administrator sees it.
 *
 * A second resource for the same model, because the audience differs: this one
 * carries no subscription, no lifecycle transitions and no approval record —
 * those are the platform operator's business and belong to `TenantResource`.
 * One resource with conditional fields is one `when()` away from leaking the
 * registry into the academy (ADR-06).
 *
 * @mixin Tenant
 */
final class AcademyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $mode = $this->resource->registrationMode();

        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'support_email' => $this->support_email,

            'registration_mode' => $mode->value,
            'registration_mode_label' => $mode->label(),

            /*
             * The link an academy hands out, RELATIVE — the SPA routes on it
             * internally and renders it absolute at the moment of sharing. A
             * stored absolute URL rots the day the installation changes
             * address, which is the same rule notification `action_path`
             * follows.
             */
            'signup_path' => '/register?academy='.$this->slug,

            /** @var list<array{value: string, label: string, available: bool}> */
            'registration_modes' => array_map(
                fn (RegistrationMode $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                    // Invitations are declared and not built. Saying so beats
                    // an option that silently closes registration instead.
                    'available' => $case !== RegistrationMode::Invite,
                ],
                RegistrationMode::cases(),
            ),
        ];
    }
}
