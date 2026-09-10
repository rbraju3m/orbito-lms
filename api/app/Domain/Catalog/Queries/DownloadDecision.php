<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Models\DownloadGrant;

/** The answer `DownloadAccess` gives, with the reason it gave it. */
final readonly class DownloadDecision
{
    private function __construct(
        public bool $granted,
        public string $reason,
        public ?DownloadGrant $grant = null,
    ) {}

    public static function grant(string $reason, ?DownloadGrant $grant = null): self
    {
        return new self(true, $reason, $grant);
    }

    public static function deny(string $reason, ?DownloadGrant $grant = null): self
    {
        return new self(false, $reason, $grant);
    }
}
