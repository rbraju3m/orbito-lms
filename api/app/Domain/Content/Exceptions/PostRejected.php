<?php

declare(strict_types=1);

namespace App\Domain\Content\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** A post move the rules refuse. Switch on the code, never the message. */
final class PostRejected extends DomainException
{
    private function __construct(
        string $message,
        // NOT `$code`: Exception already declares one (see LiveSessionRejected).
        private readonly string $errorCode,
        private readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    /**
     * A post with no words in it. 422 rather than 409: the author has done
     * nothing out of order, the post is simply not finished.
     */
    public static function emptyBody(): self
    {
        return new self(
            'A post needs something in it before it can be published.',
            'post_not_publishable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
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
