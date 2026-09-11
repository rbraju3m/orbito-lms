<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\PaymentEvent;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A person has dealt with a refund report the webhook could not settle.
 *
 * It records THAT they did, who, and what they wrote — it changes no money and
 * no access: whatever the fix was (a refund recorded, a learner refunded
 * again) went through the refund dialog, where it is checked like any other.
 *
 * The first resolution stands. Two admins resolving at once, or one double
 * click, must not overwrite who looked first and what they found.
 */
final class ResolveRefundReport
{
    public function handle(User $actor, PaymentEvent $event, ?string $note): PaymentEvent
    {
        return DB::transaction(function () use ($actor, $event, $note): PaymentEvent {
            $event = PaymentEvent::query()->lockForUpdate()->findOrFail($event->id);

            if ($event->resolved_at === null) {
                $event->forceFill([
                    'resolved_at' => now(),
                    'resolved_by' => $actor->id,
                    'resolution_note' => $note,
                ])->save();
            }

            return $event;
        });
    }
}
