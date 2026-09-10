<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Listeners;

use App\Domain\Catalog\Events\BundleCreated;
use App\Domain\Catalog\Events\BundleStatusChanged;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CoursePricingChanged;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;

/**
 * Catalog announces; Commerce decides whether there is anything to sell.
 *
 * `SyncCourseProduct` was written in Phase 10 and never wired to anything, so
 * no product was ever created outside a factory — which meant no course could
 * be priced, and `PublishChecklist` blocked every paid course anyway. The
 * whole paid path was unreachable and the suite could not see it, because
 * every commerce test starts from `Product::factory()`. This is the wire.
 */
final class SyncProductForPurchasable
{
    public function __construct(
        private readonly SyncCourseProduct $syncCourse,
        private readonly SyncBundleProduct $syncBundle,
    ) {}

    public function courseCreated(CourseCreated $event): void
    {
        $this->syncCourse->handle($event->course);
    }

    /** Publishing activates the product; archiving or unpublishing stops it. */
    public function courseStatusChanged(CourseStatusChanged $event): void
    {
        $this->syncCourse->handle($event->course);
    }

    /** Free ⇄ paid is the change that creates or retires the product. */
    public function coursePricingChanged(CoursePricingChanged $event): void
    {
        $this->syncCourse->handle($event->course);
    }

    /**
     * A bundle gets its product while still a DRAFT — the checklist wants a
     * price, and a price hangs off a product.
     */
    public function bundleCreated(BundleCreated $event): void
    {
        $this->syncBundle->handle($event->bundle);
    }

    public function bundleStatusChanged(BundleStatusChanged $event): void
    {
        $this->syncBundle->handle($event->bundle);
    }
}
