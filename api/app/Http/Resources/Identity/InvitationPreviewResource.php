<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\Invitation;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An invitation, to the person holding its link — so the accept page can say
 * which academy, which address and which role before they choose a password.
 * A second resource because the audience differs (ADR-06): nothing about who
 * sent it or how often.
 *
 * @mixin Invitation
 */
final class InvitationPreviewResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'academy_name' => (string) tenant('name'),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
