<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\DownloadSource;
use App\Domain\Catalog\Exceptions\DownloadRejected;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Identity\Models\User;

/**
 * A free download is claimed, not bought — the same split as a free course,
 * which has no product and goes straight through `EnrollInCourse`.
 *
 * "Free" means free to signed-in members of THIS academy: nothing is anonymous
 * yet (§ Multi-tenancy), so this is not a lead magnet and cannot be one until
 * the public surface exists.
 */
final class ClaimFreeDownload
{
    public function __construct(private readonly GrantDownload $grant) {}

    public function handle(User $user, Download $download): DownloadGrant
    {
        if (! $download->status->isLive()) {
            throw DownloadRejected::notAvailable();
        }

        // A paid download is reached only through a verified payment
        // (ADR-05). This path is never that.
        if (! $download->pricing_model->isFree()) {
            throw DownloadRejected::requiresPayment();
        }

        return $this->grant->handle($user, $download, DownloadSource::Free);
    }
}
