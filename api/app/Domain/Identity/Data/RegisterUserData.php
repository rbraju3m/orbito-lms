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
        public string $locale = 'en',
    ) {}
}
