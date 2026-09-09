<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Platform\Enums\RegistrationMode;
use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Somebody tried to sign up somewhere they cannot.
 *
 * The reasons are told apart, unlike `tenant.path`'s deliberately uniform 404.
 * That middleware guards a webhook and a printed certificate link, where the
 * tenant is a uuid and a specific answer would turn the endpoint into an
 * oracle for which academies exist. This is the opposite situation: the slug
 * is in a signup link the academy PUBLISHED, so its existence is not a secret,
 * and somebody following that link needs to be told whether the academy is
 * closed, invitation-only, or simply not there — otherwise every case reads as
 * "you typed it wrong".
 */
final class RegistrationNotOpen extends DomainException
{
    public static function noSuchAcademy(): self
    {
        return new self('No academy answers to that address. Check the signup link you were given.');
    }

    public static function academyClosed(): self
    {
        return new self('This academy is not currently open. Its administrator can tell you when it will be.');
    }

    public static function mode(RegistrationMode $mode): self
    {
        $exception = new self(match ($mode) {
            // Not reachable through the Action, which only throws for a mode
            // that refuses; here so the match stays exhaustive.
            RegistrationMode::Open => 'This academy is open for registration.',
            RegistrationMode::Invite => 'This academy is invitation only. Ask its administrator for an invitation.',
            RegistrationMode::Closed => 'This academy does not accept sign-ups. Its administrator creates accounts.',
        });

        $exception->meta = ['registration_mode' => $mode->value];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'registration_not_open';
    }

    /**
     * 403, not 422: nothing about the submitted form is wrong, and a field
     * error would send the SPA looking for a field to attach it to.
     */
    public function status(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
