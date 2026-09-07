<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Queries;

use App\Domain\Assessment\Data\GradingQueueRow;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Identity\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One list of work waiting for a person, across quizzes and assignments.
 *
 * An instructor thinks in terms of "what is there to mark today", not "which
 * table is it in". The two sources are unioned in SQL rather than merged in
 * PHP so the list paginates correctly however long it gets — merging two
 * paginated queries would silently drop rows at every page boundary.
 *
 * Both branches read an index on (course_id, status, submitted_at).
 */
final class GradingQueue
{
    /** @return LengthAwarePaginator<int, GradingQueueRow> */
    public function forCourse(
        Course $course,
        bool $includeQuizzes,
        bool $includeAssignments,
        string $status,
        int $perPage,
    ): LengthAwarePaginator {
        $branches = [];

        if ($includeQuizzes) {
            $branches[] = DB::table('quiz_attempts')
                ->selectRaw("'quiz' as kind")
                ->addSelect(['uuid', 'status', 'submitted_at', 'user_id', 'course_item_id'])
                ->where('course_id', $course->id)
                ->whereNotNull('submitted_at')
                ->when($status !== 'all', fn ($q) => $q->where(
                    'status',
                    $status === 'awaiting_review' ? 'awaiting_review' : $status
                ));
        }

        if ($includeAssignments) {
            $branches[] = DB::table('assignment_submissions')
                ->selectRaw("'assignment' as kind")
                ->addSelect(['uuid', 'status', 'submitted_at', 'user_id', 'course_item_id'])
                ->where('course_id', $course->id)
                // 'awaiting_review' is the quiz vocabulary; an assignment
                // waiting for a person is 'submitted'. The queue speaks one
                // language, so it is translated here rather than in the UI.
                ->when($status !== 'all', fn ($q) => $q->where(
                    'status',
                    $status === 'awaiting_review' ? 'submitted' : $status
                ));
        }

        if ($branches === []) {
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $query = array_shift($branches);

        foreach ($branches as $branch) {
            $query->unionAll($branch);
        }

        /** @var LengthAwarePaginator<int, object> $page */
        $page = DB::query()
            ->fromSub($query, 'queue')
            // Oldest first: the person who has been waiting longest is served
            // first, which is the only fair default for a queue.
            ->orderBy('submitted_at')
            // `submitted_at` has second precision, so two pieces of work
            // handed in together would otherwise order arbitrarily — and an
            // arbitrary order means a row can appear on two pages, or on
            // none. The tiebreak is what makes paging deterministic.
            ->orderBy('kind')
            ->orderBy('uuid')
            ->paginate($perPage);

        return $this->hydrate($page);
    }

    /**
     * @param  LengthAwarePaginator<int, object>  $page
     * @return LengthAwarePaginator<int, GradingQueueRow>
     */
    private function hydrate(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $rows = collect($page->items());

        // Two lookups for the whole page rather than a join in each branch of
        // the union, which would have to be written and indexed twice.
        $users = User::query()
            ->whereIn('id', $rows->pluck('user_id')->unique()->all())
            ->get(['id', 'uuid', 'name'])
            ->keyBy('id');

        $items = CourseItem::query()
            ->whereIn('id', $rows->pluck('course_item_id')->unique()->all())
            ->get(['id', 'uuid', 'title', 'type'])
            ->keyBy('id');

        $mapped = $rows->map(function (object $row) use ($users, $items): GradingQueueRow {
            $user = $users->get($row->user_id);
            $item = $items->get($row->course_item_id);
            $status = (string) $row->status;
            $submittedAt = $row->submitted_at;

            return new GradingQueueRow(
                kind: (string) $row->kind,
                id: (string) $row->uuid,
                status: $status,
                // The two tables spell "waiting for a person" differently
                // ('awaiting_review' and 'submitted'). The queue speaks one
                // language, so it is translated here rather than in the UI.
                awaitingReview: in_array($status, ['awaiting_review', 'submitted'], true),
                // A raw union returns the driver's datetime string; every other
                // timestamp in the API is ISO-8601, and this must match.
                submittedAt: is_string($submittedAt)
                    ? Carbon::parse($submittedAt)->toIso8601String()
                    : null,
                learnerId: $user?->uuid,
                learnerName: $user?->name,
                itemId: $item?->uuid,
                itemTitle: $item?->title,
            );
        })->values()->all();

        return new LengthAwarePaginator(
            $mapped,
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            ['path' => $page->path(), 'pageName' => $page->getPageName()],
        );
    }
}
