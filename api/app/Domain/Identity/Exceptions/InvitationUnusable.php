<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invitation link that cannot be used (docs/INVITATIONS.md §3).
 *
 * The reasons are told apart, and that is safe here in a way it is not on the
 * lead form: only somebody holding the token can ask, and the token arrived in
 * the invited mailbox. What they can DO differs by reason — ask for a new
 * link, sign in instead — and a single answer would send all of them to the
 * wrong place (the 423 rule: a refusal says how to get in).
 */
final class InvitationUnusable extends DomainException
{
    private string $errorCode = 'invitation_invalid';

    private int $httpStatus = Response::HTTP_NOT_FOUND;

    public static function unknown(): self
    {
        return self::make('invitation_invalid', Response::HTTP_NOT_FOUND,
            'This invitation link is not valid. Check that you opened the whole link from the email.');
    }

    public static function expired(): self
    {
        return self::make('invitation_expired', Response::HTTP_GONE,
            'This invitation has expired. Ask the academy to send you a new one.');
    }

    public static function revoked(): self
    {
        return self::make('invitation_revoked', Response::HTTP_GONE,
            'This invitation was withdrawn. Contact the academy if you think that is a mistake.');
    }

    public static function accepted(): self
    {
        return self::make('invitation_accepted', Response::HTTP_GONE,
            'This invitation has already been used. Sign in with the account it created.');
    }

    /** Somebody made an account with the address after it was invited. */
    public static function accountExists(): self
    {
        return self::make('account_exists', Response::HTTP_CONFLICT,
            'An account with this email address already exists. Sign in instead.');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    private static function make(string $code, int $status, string $message): self
    {
        $exception = new self($message);
        $exception->errorCode = $code;
        $exception->httpStatus = $status;

        return $exception;
    }
}
