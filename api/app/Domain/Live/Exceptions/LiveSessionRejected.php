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

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
