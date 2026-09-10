<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Listeners;

use App\Domain\Catalog\Actions\ChangeBundleStatus;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Enums\CourseStatus;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Catalog\Models\Bundle;

/**
 * A course leaving `published` takes every bundle containing it back to draft.
 *
 * Selling access to something nobody can open is worse than a lost sale, and
 * it would succeed — the grant creates an enrolment on an unpublished course
 * and the buyer sees an empty player. Moving the bundle to draft deactivates
 * its product through the normal lifecycle, and the author finds it in draft
 * with a checklist that NAMES the course that did it, rather than a support
 * ticket about a bundle nobody can buy.
 *
 * Deliberately does NOT reverse itself. Re-publishing the course does not
 * re-publish the bundle: an author who has since removed a course, changed the
 * price, or decided against it must not have their bundle put back on sale by
 * a lifecycle change somewhere else.
 */
final class ReconcileBundleSellability
{
    public function __construct(private readonly ChangeBundleStatus $changeStatus) {}

    public function handle(CourseStatusChanged $event): void
    {
        if (! $event->left(CourseStatus::Published)) {
            return;
        }

        $bundles = Bundle::query()
            ->published()
            ->whereHas('items', fn ($query) => $query->where('course_id', $event->course->id))
            ->get();

        foreach ($bundles as $bundle) {
            // Through the Action, so the transition and the timestamps stay in
            // one place (§10) and the product follows as it does for any other
            // lifecycle move. No actor: the system did this, not a person.
            $this->changeStatus->handle($bundle, BundleStatus::Draft);
        }
    }
}
