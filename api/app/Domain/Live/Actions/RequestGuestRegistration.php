<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Models\Webinar;
use App\Domain\Live\Models\WebinarRegistration;
use App\Domain\Live\Notifications\GuestMail;
use App\Domain\Live\Support\GuestLinks;
use App\Domain\Live\Support\GuestToken;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A stranger asked for a place. Writes NOTHING (docs/GUEST_REGISTRATION.md).
 *
 * It sends one email to the address typed, and that email is the only way a
 * place can come to exist: a confirmation link, which proves somebody can read
 * the mailbox. So a form cannot book a place in somebody else's name, cannot
 * fill a table, and cannot put a person on a list they never asked to be on.
 *
 * What it CAN do is send mail to an address a stranger typed — the abuse
 * LEADS.md refuses outright. Hence the cap per address per academy, which is
 * SILENT: the caller answers 202 whether a mail went or not, because a 429 on
 * an address would tell a stranger it had been asked for, and would let them
 * lock the real owner out of registering by asking first.
 *
 * If the address already holds a place, the mail says so and carries the
 * manage link rather than a second confirmation — still behind the same cap,
 * and still indistinguishable from the outside.
 */
final class RequestGuestRegistration
{
    public function __construct(private readonly GuestToken $tokens) {}

    public function handle(string $academy, Webinar $webinar, string $email, ?string $name): void
    {
        $email = Str::lower(trim($email));

        // Hashed: the cache is no place to keep a list of addresses.
        $key = 'guest-registration-mail:'.$academy.':'.hash('sha256', $email);

        if (RateLimiter::tooManyAttempts($key, (int) config('orbito.guest_registration.mails_per_address'))) {
            return;
        }

        RateLimiter::hit($key, (int) config('orbito.guest_registration.mail_window_minutes') * 60);

        $webinar->loadMissing('session');
        $when = GuestLinks::when($webinar->session);

        $held = WebinarRegistration::query()
            ->where('webinar_id', $webinar->id)
            ->where('email', $email)
            ->live()
            ->first();

        $mail = $held !== null
            ? new GuestMail(
                subject: 'Your place at '.$webinar->title,
                lines: ['You already hold a place at '.$webinar->title.$when.'.'],
                actionLabel: 'Manage my place',
                actionUrl: GuestLinks::place($academy, $webinar->slug, $this->tokens->place($academy, $held, $webinar->session, now())),
                after: ['Use the same link to join when it starts, or to give your place up.'],
            )
            : new GuestMail(
                subject: 'Confirm your place at '.$webinar->title,
                lines: ['Somebody — we hope you — asked for a place at '.$webinar->title.$when.'.'],
                actionLabel: 'Confirm my place',
                actionUrl: GuestLinks::confirm($academy, $webinar->slug, $this->tokens->confirmation($academy, $webinar, $email, $name, now())),
                after: [
                    'The link works for '.(int) config('orbito.guest_registration.confirm_ttl_hours').' hours.',
                    'If this was not you, ignore this email: nothing has been booked, and you will not hear from us again.',
                ],
            );

        Notification::route('mail', $email)->notify($mail);
    }
}
