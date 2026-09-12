<?php

declare(strict_types=1);

namespace App\Domain\Live\Actions;

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

        $webinar->save();

        return $webinar->refresh();
    }
}
