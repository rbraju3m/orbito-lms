<?php

declare(strict_types=1);

namespace App\Domain\Certification\Enums;

enum TemplateOrientation: string
{
    case Landscape = 'landscape';
    case Portrait = 'portrait';

    /** Page size in points, which is what dompdf speaks. A4 at 72dpi. */
    public function pageSize(): string
    {
        return 'a4';
    }

    public function label(): string
    {
        return match ($this) {
            self::Landscape => 'Landscape',
            self::Portrait => 'Portrait',
        };
    }
}
