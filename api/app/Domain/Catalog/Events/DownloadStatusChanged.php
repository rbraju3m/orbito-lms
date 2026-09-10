<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Models\Download;
use Illuminate\Foundation\Events\Dispatchable;

/** Both ends of the transition, like `CourseStatusChanged` and `BundleStatusChanged`. */
final class DownloadStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Download $download,
        public readonly DownloadStatus $from,
        public readonly DownloadStatus $to,
        public readonly ?int $actorId = null,
    ) {}

    public function became(DownloadStatus $status): bool
    {
        return $this->to === $status && $this->from !== $status;
    }

    public function left(DownloadStatus $status): bool
    {
        return $this->from === $status && $this->to !== $status;
    }
}
