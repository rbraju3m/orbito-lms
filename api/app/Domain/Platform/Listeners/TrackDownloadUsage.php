<?php

declare(strict_types=1);

namespace App\Domain\Platform\Listeners;

use App\Domain\Catalog\Events\DownloadCreated;
use App\Domain\Catalog\Events\DownloadDeleted;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Support\UsageCounters;

/**
 * How many downloads the academy has — drafts included, the way
 * `courses_total` counts drafts. Academy-wide only: a download has no owner.
 */
final class TrackDownloadUsage
{
    public function __construct(private readonly UsageCounters $counters) {}

    public function created(DownloadCreated $event): void
    {
        $this->counters->increment(UsageMetric::Downloads);
    }

    public function deleted(DownloadDeleted $event): void
    {
        $this->counters->decrement(UsageMetric::Downloads);
    }
}
