<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Events\BundleStatusChanged;
use App\Domain\Catalog\Exceptions\BundleNotPublishable;
use App\Domain\Catalog\Exceptions\BundleTransitionRejected;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Support\BundlePublishChecklist;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every lifecycle move for a bundle. One place owns the legal transitions and
 * the timestamps, so no controller can invent a state (§10).
 */
final class ChangeBundleStatus
{
    public function __construct(private readonly BundlePublishChecklist $checklist) {}

    /**
     * `$actor` is nullable because the system moves a bundle too: a course
     * leaving `published` takes every bundle containing it back to draft, and
     * there is nobody to name for that.
     */
    public function handle(Bundle $bundle, BundleStatus $target, ?User $actor = null): Bundle
    {
        $from = $bundle->status;

        if ($from === $target) {
            return $bundle;
        }

        if (! $from->canTransitionTo($target)) {
            throw BundleTransitionRejected::illegal($from, $target);
        }

        // Publishing is the only transition with requirements, and they are
        // the same rules the studio checklist renders.
        if ($target === BundleStatus::Published) {
            $failures = $this->checklist->blockingFailures($bundle);

            if ($failures !== []) {
                throw new BundleNotPublishable($failures);
            }
        }

        DB::transaction(function () use ($bundle, $target): void {
            $bundle->status = $target;

            // Set once, on first publish: the bundle's birthday, not the
            // timestamp of the last edit. Same rule as a course.
            if ($target === BundleStatus::Published) {
                $bundle->published_at ??= now();
            }

            $bundle->save();
        });

        BundleStatusChanged::dispatch($bundle, $from, $target, $actor?->id);

        return $bundle->refresh();
    }
}
