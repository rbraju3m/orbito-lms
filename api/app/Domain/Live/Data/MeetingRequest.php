<?php

declare(strict_types=1);

namespace App\Domain\Live\Data;

use Carbon\CarbonInterface;

/**
 * What a provider needs to create a meeting.
 *
 * Scalars only, and deliberately not the LiveSession model: a provider
 * implementation that held the row could save it, read the rest of it, or
 * serialise it into an exception — the same reasoning as GatewayAccount in
 * Phase 10.
 */
final class MeetingRequest
{
    public function __construct(
        public readonly string $title,
        public readonly CarbonInterface $startsAt,
        public readonly CarbonInterface $endsAt,
        /** IANA, e.g. 'Asia/Dhaka'. Providers schedule in the host's zone. */
        public readonly string $timezone,
        public readonly ?string $description = null,
        /** The host's email, where a provider needs one to own the meeting. */
        public readonly ?string $hostEmail = null,
    ) {}

    public function durationMinutes(): int
    {
        return max(1, (int) $this->startsAt->diffInMinutes($this->endsAt));
    }
}
