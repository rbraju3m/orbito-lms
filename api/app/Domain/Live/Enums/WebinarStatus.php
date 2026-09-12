<?php

declare(strict_types=1);

namespace App\Domain\Live\Enums;

enum WebinarStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Whether it is listed and open to registration. */
    public function isOpen(): bool
    {
        return $this === self::Published;
    }

    /**
     * The states this one may move to.
     *
     * Read by `ChangeWebinarStatus` and rendered as `available_actions`, so a
     * button that would 409 cannot exist (§ The platform owner, the same rule
     * `TenantStatus::allows()` states for the registry).
     *
     * Cancelled is not terminal: calling an event back on is a thing that
     * happens, and the alternative is retyping the whole webinar. It returns
     * to DRAFT rather than straight to published, so somebody has to look at
     * the date before the registrations reopen.
     */
    public function allows(self $target): bool
    {
        if ($this === $target) {
            return false;
        }

        return match ($this) {
            self::Draft, self::Published => true,
            self::Cancelled => $target === self::Draft,
        };
    }

    /** @return list<string> */
    public function availableTransitions(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), fn (self $status): bool => $this->allows($status)),
        ));
    }
}
