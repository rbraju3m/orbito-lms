<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;

/**
 * Staff asked to re-send or revoke an invitation that is already settled —
 * accepted, or revoked. 409: the row is fine, the request is late.
 */
final class InvitationClosed extends DomainException
{
    public static function settled(): self
    {
        return new self('This invitation has already been accepted or revoked.');
    }

    public static function accountExists(): self
    {
        $exception = new self('An account with this email address already exists, so there is nobody left to invite.');
        $exception->meta = ['reason' => 'account_exists'];

        return $exception;
    }

    public function errorCode(): string
    {
        return 'invitation_closed';
    }
}
