<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Events\DownloadDeleted;
use App\Domain\Catalog\Exceptions\DownloadRejected;
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

        $id = $download->id;
        $download->delete();

        DownloadDeleted::dispatch($id);
    }
}
