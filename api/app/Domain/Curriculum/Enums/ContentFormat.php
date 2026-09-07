<?php

declare(strict_types=1);

namespace App\Domain\Curriculum\Enums;

enum ContentFormat: string
{
    case Html = 'html';
    case Markdown = 'markdown';
}
