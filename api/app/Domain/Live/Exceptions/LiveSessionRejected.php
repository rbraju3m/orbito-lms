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
     * Publishing something that is not ready to be offered.
     *
     * The blockers come from `WebinarPublishRules`, the same class that draws
     * `is_publishable` and `publish_blockers`, so the disabled button and this
     * rejection cannot disagree. Reported as the FIRST blocker's code — a
     * webinar with no session is `webinar_needs_session`, as it always was —
     * with the whole list in `meta.blockers`, because one line of "not ready"
     * is a dead end on a form with two things wrong with it.
     *
     * @param  non-empty-list<array{code: string, field: string, message: string}>  $blockers
     */
    public static function webinarNotPublishable(array $blockers): self
    {
        $rejection = new self(
            $blockers[0]['message'],
            $blockers[0]['code'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $rejection->meta = ['blockers' => $blockers];

        return $rejection;
    }

    /**
     * Registering free at an event with a ticket price.
     *
     * 423, not 403: the caller has done nothing wrong and there is a way in
     * (§ Patterns established in Phase 6). `meta` carries the product so the
     * page can offer the basket rather than a dead end.
     */
    public static function webinarRequiresPurchase(string $productUuid): self
    {
        $rejection = new self(
            'This webinar is a paid event. Buy a place to register.',
            'webinar_requires_purchase',
            Response::HTTP_LOCKED,
        );

        $rejection->meta = ['product_id' => $productUuid];

        return $rejection;
    }

    /**
     * A paid webinar whose price nobody has set yet.
     *
     * Unreachable through the UI — the same rule stops it being published —
     * but a learner holding a link to one that was published before the price
     * was removed must be told something better than a 500.
     */
    public static function webinarNotOnSale(): self
    {
        return new self(
            'This webinar is not on sale at the moment.',
            'webinar_not_on_sale',
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * Giving up a place that was PAID for.
     *
     * Refused rather than allowed, because the way back in is the one thing
     * this learner cannot do: re-registering at a paid event 423s, so a stray
     * click would lock them out of something they bought. Letting it go is a
     * refund, which is the admin's call and cancels the registration itself.
     */
    public static function webinarPlacePurchased(): self
    {
        return new self(
            'You bought this place. Ask the academy for a refund to give it up.',
            'webinar_place_purchased',
            Response::HTTP_CONFLICT,
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
