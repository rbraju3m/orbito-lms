<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Support\Console\RunsForEveryTenant;
use Illuminate\Console\Command;

/**
 * Retention on the delivery log. It exists to debug an integration, and every
 * row carries a payload with somebody's name and email in it — neither is a
 * reason to keep it for ever. Pending rows are never touched: they are still
 * work, whatever their age.
 */
final class PruneWebhookDeliveries extends Command
{
    use RunsForEveryTenant;

    protected $signature = 'webhooks:prune {--days= : Override the configured retention}';

    protected $description = 'Delete settled webhook deliveries past their retention window, in every academy';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('orbito.webhooks.retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least one day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        $failed = $this->forEachTenant(function () use ($cutoff, &$total): void {
            do {
                $deleted = WebhookDelivery::query()
                    ->whereIn('status', [DeliveryStatus::Succeeded, DeliveryStatus::Failed])
                    ->where('created_at', '<', $cutoff)
                    ->limit(5_000)
                    ->delete();

                $total += $deleted;
            } while ($deleted > 0);
        });

        $this->info("Pruned {$total} webhook deliveries older than {$cutoff->toDateString()}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
