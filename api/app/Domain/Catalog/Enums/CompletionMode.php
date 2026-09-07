<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/**
 * Kept from the audited reference product — one of its genuinely good ideas.
 */
enum CompletionMode: string
{
    /** The learner may mark the course complete themselves. */
    case Flexible = 'flexible';
    /** Every required item must be completed first. */
    case Strict = 'strict';
}
