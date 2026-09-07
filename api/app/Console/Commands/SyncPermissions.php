<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Console\Command;

final class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Reconcile roles and permissions in the database with config/permissions.php';

    public function handle(PermissionRegistry $registry): int
    {
        $result = $registry->sync();

        $this->components->info('Permission registry synced.');

        $this->table(
            ['Permissions created', 'Roles created', 'Roles updated'],
            [[$result['permissions_created'], $result['roles_created'], $result['roles_updated']]],
        );

        if ($result['orphans'] !== []) {
            // Reported, not removed: a role may still reference it, and dropping
            // a capability silently is how people lose access without a trace.
            $this->components->warn(
                'Orphaned permissions still in the database (not defined in config): '
                .implode(', ', $result['orphans'])
            );
        }

        return self::SUCCESS;
    }
}
