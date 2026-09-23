<?php

declare(strict_types=1);

namespace App\Domain\Platform\Enums;

/** Which way a script runs. `<html dir>` on the client, and nothing else. */
enum TextDirection: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';
}
