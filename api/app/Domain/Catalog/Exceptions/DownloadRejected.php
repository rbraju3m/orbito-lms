<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Support\Exceptions\DomainException;

/** A request about a download that its current state cannot honour. */
final class DownloadRejected extends DomainException
{
    /**
     * The machine code for THIS rejection. Not `$code` — that is
     * `Exception::$code`, an integer every exception already carries, and
     * shadowing it with a string is a type error waiting for the first
     * caller who reads it.
     */
    private string $reason = 'download_rejected';

    public static function notAvailable(): self
    {
        return self::make('download_not_available', 'This download is not available.');
    }

    /** Free is claimed; paid is bought. Claiming a paid one is asking for it free. */
    public static function requiresPayment(): self
    {
        return self::make('download_requires_payment', 'This download has to be bought, not claimed.');
    }

    /**
     * Deleting a download people own would take away what they paid for.
     * Archiving takes it off sale and leaves every owner their file.
     */
    public static function hasOwners(int $owners): self
    {
        return self::make(
            'download_has_owners',
            sprintf(
                '%d %s this download, so it cannot be deleted. Archive it instead — that takes it off sale without taking it from them.',
                $owners,
                $owners === 1 ? 'person owns' : 'people own',
            ),
        );
    }

    private static function make(string $code, string $message): self
    {
        $exception = new self($message);
        $exception->reason = $code;

        return $exception;
    }

    public function errorCode(): string
    {
        return $this->reason;
    }

    public function status(): int
    {
        return 409;
    }
}
