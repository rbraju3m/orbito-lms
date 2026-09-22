<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\Invitation;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\InvitationMail;
use Illuminate\Support\Facades\Notification;

/**
 * Mints an invitation's link and mails it — the ONE place a plain token
 * exists (docs/INVITATIONS.md §2). Shared by sending and re-sending, because
 * both are "issue a fresh credential": the row is given the new hash and the
 * old link stops working in the same save.
 *
 * The link lands on the SPA's `/invite`, which POSTs the token: the API never
 * accepts a credential on a GET, which is what proxies and access logs record.
 */
final class InvitationMailer
{
    public function issue(Invitation $invitation, ?User $inviter): void
    {
        $token = Invitation::newToken();
        $expires = now()->addDays((int) config('orbito.invitations.ttl_days'));

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => $expires,
            'last_sent_at' => now(),
            'invited_by' => $inviter?->id,
        ])->save();

        $academy = (string) tenant('slug');

        Notification::route('mail', $invitation->email)->notify(new InvitationMail(
            academyName: (string) tenant('name'),
            roleLabel: $invitation->role->label(),
            inviterName: $inviter?->name,
            url: rtrim(frontend_url(), '/').'/invite?academy='.rawurlencode($academy).'&token='.rawurlencode($token),
            expiresOn: $expires->format('j M Y'),
        ));
    }
}
