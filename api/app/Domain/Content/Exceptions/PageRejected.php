<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** A page move the rules refuse. Switch on the code, never the message. */
final class PageRejected extends DomainException
{
    private function __construct(
        string $message,
        // NOT `$code`: Exception already declares one (see LiveSessionRejected).
        private readonly string $errorCode,
        private readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self(
            'A page needs at least one block before it can be published.',
            'page_not_publishable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /** Somebody else chose a different front page at the same moment. */
    public static function homeTaken(): self
    {
        return new self(
            'Another page was made the front page at the same moment. Reload and try again.',
            'page_home_conflict',
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
