<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

/**
 * Which attempt counts when a learner has several.
 */
enum GradingPolicy: string
{
    case Highest = 'highest';
    case Latest = 'latest';
    case First = 'first';
    case Average = 'average';

    public function label(): string
    {
        return match ($this) {
            self::Highest => 'Highest score',
            self::Latest => 'Most recent attempt',
            self::First => 'First attempt',
            self::Average => 'Average of all attempts',
        };
    }
}
