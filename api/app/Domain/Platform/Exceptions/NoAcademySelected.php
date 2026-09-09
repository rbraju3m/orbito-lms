<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform operator asked for a route that lives inside an academy, while
 * being inside none.
 *
 * 409, not 403: they are not forbidden from anything, they are in the wrong
 * place — the same distinction as the 423 the drip gate uses. `meta` carries
 * the way out, because a refusal that cannot say how to proceed is a dead end.
 *
 * Before this existed the request reached a controller that queried a tenant
 * table on the central connection, and the operator got a 500 naming a missing
 * table instead.
 */
final class NoAcademySelected extends DomainException
{
    public static function make(): self
    {
        $exception = new self(
            'Enter an academy first. This is an operator account, which belongs to none until you do.'
        );

        // Relative, and routed on internally by the SPA — a stored absolute
        // URL rots the day the installation changes address.
        $exception->meta = ['enter_at' => '/platform/academies'];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'no_academy_selected';
    }

    public function status(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
