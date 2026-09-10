<?php

declare(strict_types=1);

namespace App\Domain\Media\Support;

/** "2.4 GB", for a message a person reads. Never for anything stored. */
final class ByteSize
{
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, 1).' '.$units[$i];
    }
}
