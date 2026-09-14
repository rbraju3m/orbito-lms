<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

/**
 * Which page of the public site a lead was captured on. `Site` is the front
 * page and names nothing; the others name the course or webinar by slug on
 * the way in, resolved by the server — a client cannot attribute a lead to a
 * draft it could not have been reading.
 */
enum LeadSource: string
{
    case Site = 'site';
    case Course = 'course';
    case Webinar = 'webinar';

    public function label(): string
    {
        return match ($this) {
            self::Site => 'Front page',
            self::Course => 'Course page',
            self::Webinar => 'Event page',
        };
    }

    public function namesSubject(): bool
    {
        return $this !== self::Site;
    }
}
