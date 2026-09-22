<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\Invitation;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * An invitation, for staff holding `invitation.manage`. Carries no token and
 * no hash — nothing here can be followed.
 *
 * `can_resend` / `can_revoke` come from the same `status()` the actions
 * enforce, so a button that would 409 cannot be drawn.
 *
 * @mixin Invitation
 */
final class InvitationResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status();

        return [
            'id' => $this->uuid,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'sent_count' => $this->sent_count,
            'last_sent_at' => $this->last_sent_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'can_resend' => $status->isOpen(),
            'can_revoke' => $status->isOpen(),
        ];
    }
}
