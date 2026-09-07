<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Enums;

/**
 * Where a lesson's video comes from.
 *
 * An interface-shaped enum rather than a boolean: adding Bunny or Mux in a
 * later phase is a new case plus a resolver, not a schema change (risk R3).
 */
enum VideoProvider: string
{
    case None = 'none';
    case Upload = 'upload';
    case YouTube = 'youtube';
    case Vimeo = 'vimeo';
    case External = 'external';
    case Embed = 'embed';

    public function usesMedia(): bool
    {
        return $this === self::Upload;
    }

    public function usesUrl(): bool
    {
        return in_array($this, [self::YouTube, self::Vimeo, self::External, self::Embed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No video',
            self::Upload => 'Uploaded file',
            self::YouTube => 'YouTube',
            self::Vimeo => 'Vimeo',
            self::External => 'External URL',
            self::Embed => 'Embed code',
        };
    }
}
