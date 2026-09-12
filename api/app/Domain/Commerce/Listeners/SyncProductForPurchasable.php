<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Listeners;

use App\Domain\Catalog\Events\BundleCreated;
use App\Domain\Catalog\Events\BundleDeleted;
use App\Domain\Catalog\Events\BundleStatusChanged;
use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CoursePricingChanged;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Catalog\Events\DownloadCreated;
use App\Domain\Catalog\Events\DownloadDeleted;
use App\Domain\Catalog\Events\DownloadPricingChanged;
use App\Domain\Catalog\Events\DownloadStatusChanged;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;
use App\Domain\Commerce\Actions\SyncDownloadProduct;
use App\Domain\Commerce\Actions\SyncWebinarProduct;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Live\Events\WebinarCreated;
use App\Domain\Live\Events\WebinarDeleted;
use App\Domain\Live\Events\WebinarPricingChanged;
use App\Domain\Live\Events\WebinarStatusChanged;

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
        private readonly SyncDownloadProduct $syncDownload,
        private readonly SyncWebinarProduct $syncWebinar,
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

    public function downloadCreated(DownloadCreated $event): void
    {
        $this->syncDownload->handle($event->download);
    }

    public function downloadStatusChanged(DownloadStatusChanged $event): void
    {
        $this->syncDownload->handle($event->download);
    }

    public function downloadPricingChanged(DownloadPricingChanged $event): void
    {
        $this->syncDownload->handle($event->download);
    }

    /**
     * A webinar gets its product while still a DRAFT, like a bundle: the
     * publish rule wants a price, and a price hangs off a product.
     */
    public function webinarCreated(WebinarCreated $event): void
    {
        $this->syncWebinar->handle($event->webinar);
    }

    /** Publishing opens the sale; cancelling or unpublishing closes it. */
    public function webinarStatusChanged(WebinarStatusChanged $event): void
    {
        $this->syncWebinar->handle($event->webinar);
    }

    public function webinarPricingChanged(WebinarPricingChanged $event): void
    {
        $this->syncWebinar->handle($event->webinar);
    }

    /**
     * A deleted purchasable must stop being sellable IMMEDIATELY.
     *
     * The bundles slice shipped without this: a deleted bundle's product
     * stayed active, so a basket still holding it could check out, capture
     * the payment, and grant nothing. Retired, not deleted — an order line
     * that already references the product must keep making sense.
     */
    public function bundleDeleted(BundleDeleted $event): void
    {
        $this->retire('bundle', $event->bundleId);
    }

    public function downloadDeleted(DownloadDeleted $event): void
    {
        $this->retire('download', $event->downloadId);
    }

    public function webinarDeleted(WebinarDeleted $event): void
    {
        $this->retire('webinar', $event->webinarId);
    }

    private function retire(string $type, int $id): void
    {
        Product::query()
            ->where('purchasable_type', $type)
            ->where('purchasable_id', $id)
            ->update(['status' => ProductStatus::Inactive]);
    }
}
