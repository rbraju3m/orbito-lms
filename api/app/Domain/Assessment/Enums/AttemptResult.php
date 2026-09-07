<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Enums;

enum AttemptResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    /** Cannot be decided until a human grades the open questions. */
    case Pending = 'pending';
}
