<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

use App\Domain\Live\Events\WebinarPricingChanged;
use App\Domain\Live\Models\Webinar;

/**
 * Edits the webinar itself — its words and how many places it holds.
 *
 * The TIME is not here. A webinar's session is a `LiveSession` like any
 * other, so moving it goes through `RescheduleLiveSession` and the existing
 * `PATCH /live-sessions/{id}`, which resets the reminder and tells the
 * provider. A second path to the same fields is a second set of rules to keep
 * in step.
 *
 * The slug is not here either: it is a link somebody may already hold.
 *
 * Free ⇄ paid IS here, and it is the one field that announces itself:
 * `WebinarPricingChanged` fires only on a real flip, because that is what
 * creates or retires the product a price hangs off. Saving the form again with
 * the switch untouched must announce nothing (§ Patterns established in Phase
 * 16: an operation is not a transition).
 */
final class UpdateWebinar
{
    /** @param  array<string, mixed>  $attributes */
    public function handle(Webinar $webinar, array $attributes): Webinar
    {
        foreach (['title', 'description', 'capacity'] as $field) {
            if (array_key_exists($field, $attributes)) {
                // Present-and-null CLEARS, so a description once written can
                // be removed and a capacity can go back to uncapped.
                $webinar->{$field} = $attributes[$field];
            }
        }

        $wasPaid = $webinar->is_paid;
        $isPaid = array_key_exists('is_paid', $attributes)
            ? (bool) $attributes['is_paid']
            : $wasPaid;

        $webinar->is_paid = $isPaid;
        $webinar->save();

        /*
         * After the save, so the listener that syncs the product reads the new
         * answer rather than the old one. Only on a flip: a title edit leaves
         * the product's own title behind, exactly as a download's does, and
         * that is harmless — an order line snapshots the title it was sold
         * under and never re-reads the product for it.
         */
        if ($wasPaid !== $isPaid) {
            WebinarPricingChanged::dispatch($webinar, $wasPaid, $isPaid);
        }

        return $webinar->refresh();
    }
}
