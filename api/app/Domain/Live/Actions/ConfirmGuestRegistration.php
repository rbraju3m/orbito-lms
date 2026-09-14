<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Exceptions\LiveSessionRejected;
use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Notifications\GuestMail;
use App\Domain\Live\Support\GuestLinks;
use App\Domain\Live\Support\GuestToken;
use Illuminate\Support\Facades\Notification;

/**
 * Somebody followed the confirmation link: the mailbox has answered, so the
 * place can be held (docs/GUEST_REGISTRATION.md §3).
 *
 * Through `RegisterForWebinar::forGuest()` — the same transaction and the same
 * lock a member's registration takes, so the full, closed and paid refusals
 * are the member's refusals and nothing here re-decides them. Those answers
 * are now safe to give plainly: only somebody holding the mail can ask.
 *
 * Following the link twice is somebody clicking twice. The place is held
 * once, and the "you are registered" mail with the manage link goes only when
 * the place was not already live.
 *
 * @return array{registration: WebinarRegistration, token: string}
 */
final class ConfirmGuestRegistration
{
    public function __construct(
        private readonly GuestToken $tokens,
        private readonly RegisterForWebinar $register,
    ) {}

    /** @return array{registration: WebinarRegistration, token: string} */
    public function handle(string $academy, string $token): array
    {
        $claim = $this->tokens->readConfirmation($token, $academy, now());

        $webinar = $claim === null
            ? null
            : Webinar::query()->where('uuid', $claim['webinar'])->with('session')->first();

        if ($claim === null || $webinar === null) {
            throw LiveSessionRejected::guestLinkInvalid();
        }

        $wasHeld = WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $claim['email'])
            ->live()
            ->exists();

        $registration = $this->register->forGuest($webinar, $claim['email'], $claim['name']);
        $placeToken = $this->tokens->place($academy, $registration, $webinar->session, now());

        if (! $wasHeld) {
            Notification::route('mail', $registration->email)->notify(new GuestMail(
                subject: 'You are registered for '.$webinar->title,
                lines: ['Your place at '.$webinar->title.GuestLinks::when($webinar->session).' is confirmed.'],
                actionLabel: 'Manage my place',
                actionUrl: GuestLinks::place($academy, $webinar->slug, $placeToken),
                after: [
                    'Use the same link to join when it starts, or to give your place up.',
                    'We will remind you shortly before it begins, and tell you if it is called off.',
                ],
            ));
        }

        return ['registration' => $registration->setRelation('webinar', $webinar), 'token' => $placeToken];
    }
}
