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

    /**
     * Optional machine-readable context for a failure the caller can act on —
     * a date to wait for, the item that blocks this one. Serialised as
     * `error.meta`; absent when empty. See docs/API.md §2.
     *
     * @var array<string, mixed>
     */
    protected array $meta = [];

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

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return $this->meta;
    }
}
