<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Catalog\Enums\DownloadStatus;
use App\Support\Exceptions\DomainException;

final class DownloadTransitionRejected extends DomainException
{
    public static function illegal(DownloadStatus $from, DownloadStatus $to): self
    {
        return new self("A {$from->label()} download cannot become {$to->label()}.");
    }

    public function errorCode(): string
    {
        return 'download_transition_rejected';
    }

    public function status(): int
    {
        return 409;
    }
}
