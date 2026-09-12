<?php

declare(strict_types=1);

namespace App\Domain\Live\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

final class LiveSessionRejected extends DomainException
{
    private function __construct(
        string $message,
        // NOT `$code`: Exception already declares one, non-readonly, and
        // shadowing it is a fatal error at class-load time.
        private readonly string $errorCode,
        private readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function linkRequired(): self
    {
        return new self(
            'This provider needs a join link supplied by the host.',
            'live_session_link_required',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function providerNotConnected(string $provider): self
    {
        return new self(
            "No account is connected for {$provider}.",
            'live_provider_not_connected',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Connecting the one provider that has nothing to connect.
     *
     * `Manual` is not an integration — the host pastes a link per session —
     * so a row of credentials for it would be a row nothing ever reads.
     */
    public static function providerNeedsNoAccount(string $provider): self
    {
        return new self(
            "The {$provider} provider does not use a connected account.",
            'live_provider_needs_no_account',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Saved credentials that would not be enough to schedule with.
     *
     * `meta.missing` names the keys, because "incomplete" with no list is a
     * dead end on a four-box form (§ Patterns established in Phase 9).
     *
     * @param  list<string>  $missing
     */
    public static function credentialsIncomplete(string $provider, array $missing): self
    {
        $rejection = new self(
            "The {$provider} credentials are incomplete: ".implode(', ', $missing).'.',
            'live_provider_credentials_incomplete',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $rejection->meta = ['missing' => $missing];

        return $rejection;
    }

    public static function endsBeforeItStarts(): self
    {
        return new self(
            'A session cannot end before it starts.',
            'live_session_invalid_window',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /** 422 rather than 403: they did nothing wrong, the moment has passed. */
    public static function notJoinable(): self
    {
        return new self(
            'This session has ended or been cancelled.',
            'live_session_not_joinable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function cohortFull(): self
    {
        return new self('This cohort has no places left.', 'cohort_full', Response::HTTP_CONFLICT);
    }

    public static function cohortClosed(): self
    {
        return new self(
            'This cohort is not open for enrolment.',
            'cohort_closed',
            Response::HTTP_CONFLICT,
        );
    }

    /** A run with sessions or learners is cancelled, never deleted. */
    public static function cohortInUse(): self
    {
        return new self(
            'This cohort has sessions or learners. Cancel it instead of deleting it.',
            'cohort_in_use',
            Response::HTTP_CONFLICT,
        );
    }

    public static function webinarFull(): self
    {
        return new self('This webinar has no places left.', 'webinar_full', Response::HTTP_CONFLICT);
    }

    public static function webinarClosed(): self
    {
        return new self(
            'This webinar is not open for registration.',
            'webinar_closed',
            Response::HTTP_CONFLICT,
        );
    }

    /** @param  list<string>  $allowed */
    public static function webinarTransitionRejected(string $from, string $to, array $allowed): self
    {
        $rejection = new self(
            "A {$from} webinar cannot become {$to}.",
            'webinar_transition_rejected',
            Response::HTTP_CONFLICT,
        );

        // What it CAN become, so a stale screen can correct itself rather
        // than offering the same impossible button again.
        $rejection->meta = ['available_actions' => $allowed];

        return $rejection;
    }

    /**
     * Publishing something nobody can attend.
     *
     * The one content requirement a webinar has: a time and a place. Checked
     * where it is enforced, and rendered by `is_publishable` so the button is
     * disabled rather than refused.
     */
    public static function webinarNeedsSession(): self
    {
        return new self(
            'Schedule the session before publishing this webinar.',
            'webinar_needs_session',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /** Registrations are somebody's record. A webinar with any is cancelled. */
    public static function webinarInUse(): self
    {
        return new self(
            'People have registered for this webinar. Cancel it instead of deleting it.',
            'webinar_in_use',
            Response::HTTP_CONFLICT,
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
