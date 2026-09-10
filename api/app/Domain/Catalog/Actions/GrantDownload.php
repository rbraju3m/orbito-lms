<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\DownloadSource;
use App\Domain\Catalog\Events\DownloadGranted;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The ONLY way a grant row comes to exist.
 *
 * Idempotency is the unique index, and this CATCHES the violation rather than
 * checking first (§ Phase 14). A check-then-insert loses exactly the race a
 * double click on "Get it free" — or a webhook delivered twice — creates.
 */
final class GrantDownload
{
    public function handle(
        User $user,
        Download $download,
        DownloadSource $source,
        ?int $orderId = null,
    ): DownloadGrant {
        try {
            $grant = DownloadGrant::create([
                'download_id' => $download->id,
                'user_id' => $user->id,
                'source' => $source,
                'order_id' => $orderId,
                'granted_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already theirs. The second click is answered with the grant the
            // first one made, and fires nothing — anything counting owners
            // counts people, not clicks.
            return DownloadGrant::query()
                ->where('download_id', $download->id)
                ->where('user_id', $user->id)
                ->firstOrFail();
        }

        DownloadGranted::dispatch($grant);

        return $grant;
    }
}
