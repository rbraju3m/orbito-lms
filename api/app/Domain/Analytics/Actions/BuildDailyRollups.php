<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Actions;

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyInstructorStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\OrderItemAllocation;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/**
 * Builds one UTC day of every daily rollup.
 *
 * IDEMPOTENT. Every write is an upsert on the primary key, so running the same
 * date twice produces the same rows — which is what makes these tables
 * disposable: drop them and rebuild from the log and the ledger. A builder
 * that appended would make a re-run a corruption, and re-running is exactly
 * what an operator does when a night's job failed.
 *
 * TWO SOURCES, DELIBERATELY. Behaviour comes from the append-only log (ADR-08).
 * MONEY COMES FROM THE ORDERS LEDGER, never from the log — an order is not a
 * course, and splitting a payment across courses inside an event would give
 * the platform total and the per-course totals two definitions free to
 * disagree. The ledger is the one place money is already correct.
 */
final class BuildDailyRollups
{
    public function handle(CarbonImmutable $date): void
    {
        $from = $date->startOfDay();
        $to = $from->addDay();
        $currency = strtoupper((string) config('orbito.currency.base'));

        $this->course($date, $from, $to, $currency);
        $this->platform($date, $from, $to, $currency);
        $this->instructor($date, $currency);
    }

    private function course(CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to, string $currency): void
    {
        // One grouped query for every counted name, rather than one query per
        // metric. The (course_id, name, occurred_at) index exists for this.
        $counts = AnalyticsEvent::query()
            ->selectRaw('course_id, name, COUNT(*) as total')
            ->whereNotNull('course_id')
            ->occurredBetween($from, $to)
            ->whereIn('name', [
                EventName::CourseViewed->value,
                EventName::CourseEnrolled->value,
                EventName::CourseCompleted->value,
            ])
            ->groupBy('course_id', 'name')
            // toBase(): these are AGGREGATE rows, not AnalyticsEvent models.
            // Hydrating them into the model would hand every caller an object
            // whose `name` is a string and whose `id` is missing.
            ->toBase()
            ->get();

        /*
         * Distinct people, over EVERY name — a learner who watched a lesson
         * and answered a question is one active learner, not two. That cannot
         * come out of the grouped query above, so it is its own.
         */
        $active = AnalyticsEvent::query()
            ->selectRaw('course_id, COUNT(DISTINCT actor_id) as actors')
            ->whereNotNull('course_id')
            ->whereNotNull('actor_id')
            ->occurredBetween($from, $to)
            ->groupBy('course_id')
            ->pluck('actors', 'course_id');

        $revenue = $this->courseRevenue($from, $to, $currency);

        /** @var array<int, array<string, int>> $rows */
        $rows = [];

        foreach ($counts as $row) {
            $courseId = (int) $row->course_id;
            $rows[$courseId] ??= ['views' => 0, 'enrollments' => 0, 'completions' => 0];

            $metric = match ((string) $row->name) {
                EventName::CourseViewed->value => 'views',
                EventName::CourseEnrolled->value => 'enrollments',
                EventName::CourseCompleted->value => 'completions',
                default => null,
            };

            if ($metric !== null) {
                $rows[$courseId][$metric] = (int) $row->total;
            }
        }

        // A course with revenue or activity but no counted event still gets a
        // row: a day with a sale and no page views is a real day.
        foreach ([...$active->keys()->all(), ...array_keys($revenue)] as $courseId) {
            $rows[(int) $courseId] ??= ['views' => 0, 'enrollments' => 0, 'completions' => 0];
        }

        if ($rows === []) {
            return;
        }

        DailyCourseStat::query()->upsert(
            array_map(fn (int $courseId): array => [
                'date' => $date->toDateString(),
                'course_id' => $courseId,
                'views' => $rows[$courseId]['views'],
                'enrollments' => $rows[$courseId]['enrollments'],
                'completions' => $rows[$courseId]['completions'],
                'revenue_minor' => $revenue[$courseId] ?? 0,
                'currency' => $currency,
                'active_learners' => (int) ($active[$courseId] ?? 0),
                'created_at' => now(),
                'updated_at' => now(),
            ], array_keys($rows)),
            ['date', 'course_id'],
            ['views', 'enrollments', 'completions', 'revenue_minor', 'currency', 'active_learners', 'updated_at'],
        );
    }

    private function platform(CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to, string $currency): void
    {
        $counts = AnalyticsEvent::query()
            ->selectRaw('name, COUNT(*) as total')
            ->occurredBetween($from, $to)
            ->whereIn('name', [EventName::CourseEnrolled->value, EventName::CourseCompleted->value])
            ->groupBy('name')
            ->pluck('total', 'name');

        $active = (int) AnalyticsEvent::query()
            ->whereNotNull('actor_id')
            ->occurredBetween($from, $to)
            ->distinct()
            ->count('actor_id');

        /*
         * Accounts are CENTRAL and this runs on the tenant connection, so this
         * is a separate query on a pinned model rather than a join — a join
         * across the boundary compiles to one statement and cannot work (§ Multi-tenancy).
         */
        $newUsers = (int) User::query()
            ->where('tenant_id', tenant('id'))
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->count();

        DailyPlatformStat::query()->upsert([[
            'date' => $date->toDateString(),
            'new_users' => $newUsers,
            'new_enrollments' => (int) ($counts[EventName::CourseEnrolled->value] ?? 0),
            'completions' => (int) ($counts[EventName::CourseCompleted->value] ?? 0),
            /*
             * What was actually CHARGED, from the order total.
             *
             * This equals the per-course figures PLUS `download_revenue_minor`
             * below — exactly, and a test asserts it. Bundles are already in
             * the course figures, through their allocations. Downloads have no
             * course, so they need their own line or the platform total would
             * silently stop matching its parts.
             *
             * `orders.discount_minor` is still always 0. When coupons land, an
             * order-level discount has to be allocated across its items
             * (largest remainder, as `RevenueAllocator` does) or this breaks.
             */
            'revenue_minor' => (int) Order::query()
                ->whereNotNull('paid_at')
                ->where('paid_at', '>=', $from)
                ->where('paid_at', '<', $to)
                ->where('currency', $currency)
                ->sum('total_minor'),
            'download_revenue_minor' => $this->downloadRevenue($from, $to, $currency),
            'currency' => $currency,
            'active_learners' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['date'], [
            'new_users', 'new_enrollments', 'completions',
            'revenue_minor', 'download_revenue_minor', 'currency', 'active_learners', 'updated_at',
        ]);
    }

    /** Downloads sold, from the order lines — the snapshot of what was charged. */
    private function downloadRevenue(CarbonImmutable $from, CarbonImmutable $to, string $currency): int
    {
        return (int) OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.purchasable_type', 'download')
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $from)
            ->where('orders.paid_at', '<', $to)
            ->where('orders.currency', $currency)
            ->sum('order_items.total_minor');
    }

    private function instructor(CarbonImmutable $date, string $currency): void
    {
        // Roll the course rows up by owner rather than re-reading the log: the
        // course figures are already built and correct for this date, and a
        // second derivation is a second thing to keep in step.
        $stats = DailyCourseStat::query()
            ->join('courses', 'courses.id', '=', 'analytics_daily_course.course_id')
            ->where('analytics_daily_course.date', $date->toDateString())
            ->groupBy('courses.owner_id')
            ->selectRaw('courses.owner_id, SUM(enrollments) as enrollments, SUM(revenue_minor) as revenue')
            ->toBase()
            ->get();

        if ($stats->isEmpty()) {
            return;
        }

        /*
         * A snapshot of each instructor's average across the courses they own
         * RIGHT NOW. Weighted by rating_count, so a course with two ratings
         * does not count as much as one with two hundred.
         */
        $ratings = Course::query()
            ->whereIn('owner_id', $stats->pluck('owner_id')->all())
            ->where('rating_count', '>', 0)
            ->groupBy('owner_id')
            ->selectRaw('owner_id, SUM(rating_avg * rating_count) / SUM(rating_count) as weighted')
            ->pluck('weighted', 'owner_id');

        DailyInstructorStat::query()->upsert(
            $stats->map(fn (object $row): array => [
                'date' => $date->toDateString(),
                'instructor_id' => (int) $row->owner_id,
                'enrollments' => (int) $row->enrollments,
                'revenue_minor' => (int) $row->revenue,
                'currency' => $currency,
                'rating_avg' => round((float) ($ratings[$row->owner_id] ?? 0), 2),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all(),
            ['date', 'instructor_id'],
            ['enrollments', 'revenue_minor', 'currency', 'rating_avg', 'updated_at'],
        );
    }

    /**
     * Per-course revenue, from the LINE ITEMS of orders paid that day.
     *
     * `purchasable_type` is the morph alias, so this survives the Course class
     * moving. Only orders with a `paid_at` count: an order is an intention and
     * a capture is a fact, and a revenue chart built on intentions reports
     * money nobody paid.
     *
     * @return array<int, int> course id => minor units
     */
    private function courseRevenue(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        $map = [];

        foreach ($this->directCourseRevenue($from, $to, $currency) as $courseId => $amount) {
            $map[$courseId] = ($map[$courseId] ?? 0) + $amount;
        }

        /*
         * Bundle money reaches its courses through the allocations written at
         * order time. Without this half, a bundle counts in the platform total
         * and in NO course figure — and an instructor selling mainly through
         * bundles reads zero on their own dashboard.
         *
         * Summed in PHP rather than as one UNION query because the two halves
         * group different tables by different columns, and the maps are tiny:
         * one row per course that sold anything on one day.
         */
        foreach ($this->allocatedBundleRevenue($from, $to, $currency) as $courseId => $amount) {
            $map[$courseId] = ($map[$courseId] ?? 0) + $amount;
        }

        return $map;
    }

    /**
     * Courses bought directly. The line IS the attribution.
     *
     * @return array<int, int>
     */
    private function directCourseRevenue(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        /** @var array<int, int> */
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.purchasable_type', (new Course)->getMorphClass())
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $from)
            ->where('orders.paid_at', '<', $to)
            ->where('orders.currency', $currency)
            ->groupBy('order_items.purchasable_id')
            ->selectRaw('order_items.purchasable_id as course_id, SUM(order_items.total_minor) as revenue')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->course_id => (int) $row->revenue])
            ->all();
    }

    /**
     * Courses bought inside a bundle, at the share settled when the order was
     * placed. Never recomputed — repricing a course must not rewrite what an
     * old report said it earned.
     *
     * @return array<int, int>
     */
    private function allocatedBundleRevenue(CarbonImmutable $from, CarbonImmutable $to, string $currency): array
    {
        /** @var array<int, int> */
        return OrderItemAllocation::query()
            ->join('order_items', 'order_items.id', '=', 'order_item_allocations.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNotNull('orders.paid_at')
            ->where('orders.paid_at', '>=', $from)
            ->where('orders.paid_at', '<', $to)
            ->where('orders.currency', $currency)
            ->groupBy('order_item_allocations.course_id')
            ->selectRaw('order_item_allocations.course_id as course_id, SUM(order_item_allocations.amount_minor) as revenue')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->course_id => (int) $row->revenue])
            ->all();
    }
}
