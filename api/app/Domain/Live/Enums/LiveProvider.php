<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

/**
 * Where a live session actually happens.
 *
 * `Manual` is not a placeholder — it is the one that works today and the one
 * most academies will use. Somebody schedules the meeting in whatever tool
 * they already pay for and pastes the link; Orbito owns the schedule, the
 * roster and the attendance, which is the part a video provider does badly.
 *
 * Zoom and Google Meet are written and have NEVER been contacted, for the
 * same reason StripeGateway had not in Phase 10: neither can be proven
 * without credentials. `isAvailable()` is what stops one being selected by an
 * academy that has not connected an account.
 */
enum LiveProvider: string
{
    case Manual = 'manual';
    case Zoom = 'zoom';
    case GoogleMeet = 'google_meet';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Paste a link',
            self::Zoom => 'Zoom',
            self::GoogleMeet => 'Google Meet',
        };
    }

    /** Whether an implementation exists at all. */
    public function isImplemented(): bool
    {
        return true;
    }

    /**
     * Whether this provider needs the academy to connect an account.
     *
     * Manual does not — that is the whole point of it, and it is why a fresh
     * academy can schedule a live session on day one without an integration.
     */
    public function needsAccount(): bool
    {
        return $this !== self::Manual;
    }

    /**
     * Whether the host supplies the link themselves.
     *
     * The API validates on this rather than on the enum case, so adding a
     * fourth provider does not mean revisiting every form request.
     */
    public function isHostSupplied(): bool
    {
        return $this === self::Manual;
    }
}
