<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The provider refused, or reported the refund failed: keep the row, with the
 * reason, and let its amount go — a failed refund holds nothing, so all of it
 * can be refunded again.
 *
 * Only a PENDING refund fails. Under the row's lock, so a failure report racing
 * the completion of the same refund cannot un-complete it: whichever lands
 * second finds the status already settled and changes nothing.
 */
final class FailRefund
{
    public function handle(Refund $refund, string $reason): Refund
    {
        return DB::transaction(function () use ($refund, $reason): Refund {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);

            if ($refund->status === RefundStatus::Pending) {
                $refund->forceFill([
                    'status' => RefundStatus::Failed,
                    'failed_at' => now(),
                    'failure_reason' => Str::limit($reason, 250, '…'),
                ])->save();
            }

            return $refund;
        });
    }
}
