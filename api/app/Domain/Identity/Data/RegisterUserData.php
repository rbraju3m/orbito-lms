<?php

declare(strict_types=1);

namespace App\Domain\Identity\Data;

/**
 * Registration input, already validated. Actions never see a Request.
 */
final readonly class RegisterUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public bool $wantsToTeach = false,
        public string $timezone = 'UTC',
        public ?string $locale = null,
        // True only when the address has already been proven — an invitation
        // was followed from that mailbox. Never from a request body.
        public bool $emailVerified = false,
    ) {}
}
