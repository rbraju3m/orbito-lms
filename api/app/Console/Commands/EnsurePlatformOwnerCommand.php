<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Actions\EnsurePlatformOwner;
use Illuminate\Console\Command;

/**
 * Creates or repairs the permanent platform owner.
 *
 * Idempotent, and run automatically after every central migration (see
 * EventServiceProvider). It exists as a command as well so a deploy can call
 * it explicitly, and so an installation whose owner was damaged before these
 * protections landed can be put right without a SQL console.
 */
final class EnsurePlatformOwnerCommand extends Command
{
    protected $signature = 'orbito:ensure-owner';

    protected $description = 'Create or repair the permanent platform owner account';

    public function handle(EnsurePlatformOwner $action): int
    {
        $owner = $action->handle();

        if ($owner === null) {
            $this->warn('No PLATFORM_OWNER_EMAIL configured; nothing to do.');

            return self::SUCCESS;
        }

        $this->info("Platform owner ready: {$owner->email}");

        return self::SUCCESS;
    }
}
