<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum RoleScope: string
{
    case Global = 'global';
    case Course = 'course';
}
