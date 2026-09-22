<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\DownloadDeleted;
use App\Domain\Catalog\Exceptions\DownloadRejected;
use App\Domain\Catalog\Models\BundleItem;
use App\Domain\Catalog\Models\Download;

/**
 * Deleting is refused while anybody owns it — that would take away what they
 * paid for. Archiving is the way to stop selling something.
 *
 * `DownloadDeleted` retires the product: a deleted download whose product
 * stayed active could still be checked out of a basket, and the capture would
 * take the money and find nothing to grant.
 */
final class DeleteDownload
{
    public function handle(Download $download): void
    {
        $owners = $download->grants()->active()->count();

        if ($owners > 0) {
            throw DownloadRejected::hasOwners($owners);
        }

        /*
         * `bundle_items.download_id` cascades, and a cascade is a delete
         * policy: deleting this would silently change what a bundle sells —
         * a published one included, under a price set for its old contents.
         * The author takes it out of the bundle first, and sees that they did.
         */
        $bundles = BundleItem::query()->where('download_id', $download->id)->distinct()->count('bundle_id');

        if ($bundles > 0) {
            throw DownloadRejected::inBundle($bundles);
        }

        $id = $download->id;
        $download->delete();

        DownloadDeleted::dispatch($id);
    }
}
