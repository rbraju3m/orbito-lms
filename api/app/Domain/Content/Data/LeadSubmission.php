<?php

declare(strict_types=1);

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\LeadSource;

/**
 * A lead form that passed every check, with its source already RESOLVED by
 * the server — `sourceId` and `sourceTitle` came from the database, never
 * from the request — and the consent wording the server rendered.
 */
final readonly class LeadSubmission
{
    public function __construct(
        public string $email,
        public ?string $name,
        public LeadSource $source,
        public ?int $sourceId,
        public ?string $sourceTitle,
        public string $consentText,
    ) {}
}
