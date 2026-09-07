<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Identity\Models\User;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

/**
 * Storage is a billable resource on every tier we studied, so bytes are
 * accounted per owner from the first upload.
 */
final class TrackStorageUsage
{
    public function __construct(private readonly UsageCounters $counters) {}

    public function uploaded(MediaUploaded $event): void
    {
        $owner = $event->media->owner;

        $this->counters->increment(UsageMetric::StorageBytes, $owner, $event->media->size_bytes);
        $this->counters->increment(UsageMetric::MediaFiles, $owner);
        $this->counters->increment(UsageMetric::StorageBytes, null, $event->media->size_bytes);
        $this->counters->increment(UsageMetric::MediaFiles);
    }

    public function deleted(MediaDeleted $event): void
    {
        $owner = User::find($event->ownerId);

        $this->counters->decrement(UsageMetric::StorageBytes, $owner, $event->sizeBytes);
        $this->counters->decrement(UsageMetric::MediaFiles, $owner);
        $this->counters->decrement(UsageMetric::StorageBytes, null, $event->sizeBytes);
        $this->counters->decrement(UsageMetric::MediaFiles);
    }
}
