<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for every expected, business-meaningful failure.
 *
 * `errorCode()` is a STABLE machine string the frontend switches on. Messages are
 * localised and may change; codes may not. See docs/API.md §2.
 */
abstract class DomainException extends RuntimeException
{
    /** @var list<array{field?: string, code: string, message: string}> */
    protected array $details = [];

    abstract public function errorCode(): string;

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }

    /** @return list<array{field?: string, code: string, message: string}> */
    public function details(): array
    {
        return $this->details;
    }
}
