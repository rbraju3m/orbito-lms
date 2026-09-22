<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a bundle's courses, its downloads, or both, with the whole lists
 * given, in order.
 *
 * Each list is the WHOLE collection, never a delta — the rule
 * `ReorderCurriculum` established in Phase 5. A delta lets two people editing
 * the same bundle interleave into a state neither of them asked for, and here
 * that state is what somebody gets charged for. A list that is null was not
 * sent, and keeps what the bundle already holds of that kind.
 *
 * Courses are positioned before downloads (docs/BUNDLES.md §9). Duplicates are
 * dropped rather than rejected: the unique index would refuse the write, and a
 * client that sent the same id twice meant it once.
 */
final class SetBundleContents
{
    /**
     * @param  list<int>|null  $courseIds
     * @param  list<int>|null  $downloadIds
     */
    public function handle(Bundle $bundle, ?array $courseIds, ?array $downloadIds): Bundle
    {
        $courses = $courseIds === null
            ? $this->held($bundle, 'course_id')
            : $this->known(Course::class, $courseIds);

        $downloads = $downloadIds === null
            ? $this->held($bundle, 'download_id')
            : $this->known(Download::class, $downloadIds);

        DB::transaction(function () use ($bundle, $courses, $downloads): void {
            $bundle->items()->delete();

            $position = 0;

            foreach ($courses as $courseId) {
                $bundle->items()->create(['course_id' => $courseId, 'position' => $position++]);
            }

            foreach ($downloads as $downloadId) {
                $bundle->items()->create(['download_id' => $downloadId, 'position' => $position++]);
            }
        });

        return $bundle->load(['courses', 'downloads']);
    }

    /** @return list<int> what the bundle holds of one kind now, in order */
    private function held(Bundle $bundle, string $column): array
    {
        /** @var list<int> */
        return $bundle->items()
            ->whereNotNull($column)
            ->pluck($column)
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * The ids given, deduplicated, in order, and only those that exist.
     *
     * Silently dropping an id that does not exist would leave the author
     * looking at a bundle missing something they just added, with no reason
     * given. The Form Request rejects unknown ids; this is the backstop for
     * one deleted between validation and here.
     *
     * @param  class-string<Course|Download>  $model
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function known(string $model, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        $known = $model::query()->whereIn('id', $ids)->pluck('id')->all();

        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $known, true)));
    }
}
