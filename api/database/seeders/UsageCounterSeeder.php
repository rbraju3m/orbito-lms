<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Platform\Actions\ReconcileUsageCounters;
use Illuminate\Database\Seeder;

/**
 * Demo rows are written directly rather than through the Actions that fire the
 * counter events, so bring the counters to truth after seeding.
 */
final class UsageCounterSeeder extends Seeder
{
    public function run(ReconcileUsageCounters $reconcile): void
    {
        $reconcile->handle();
    }
}
