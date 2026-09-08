<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyInstructorStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Models\Course;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/*
 * The rollups. Two properties matter more than any single figure: they are
 * IDEMPOTENT, and they read behaviour from the log but money from the ledger.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );
    $this->student = User::factory()->withRole(RoleKey::Student)->create();

    $this->day = CarbonImmutable::now('UTC')->subDay()->startOfDay();

    $this->build = fn (?CarbonImmutable $date = null) => app(BuildDailyRollups::class)
        ->handle($date ?? $this->day);
});

function logEvent(EventName $name, CarbonImmutable $at, ?int $courseId = null, ?int $actorId = null): void
{
    AnalyticsEvent::factory()->create([
        'name' => $name,
        'occurred_at' => $at,
        'course_id' => $courseId,
        'actor_id' => $actorId,
    ]);
}

it('counts a day of behaviour out of the log', function (): void {
    logEvent(EventName::CourseViewed, $this->day->addHours(2), $this->course->id, $this->student->id);
    logEvent(EventName::CourseViewed, $this->day->addHours(3), $this->course->id, $this->student->id);
    logEvent(EventName::CourseEnrolled, $this->day->addHours(4), $this->course->id, $this->student->id);
    logEvent(EventName::CourseCompleted, $this->day->addHours(20), $this->course->id, $this->student->id);

    ($this->build)();

    $row = DailyCourseStat::query()->where('course_id', $this->course->id)->sole();

    expect($row->views)->toBe(2)
        ->and($row->enrollments)->toBe(1)
        ->and($row->completions)->toBe(1)
        // One person did four things: one active learner, not four.
        ->and($row->active_learners)->toBe(1);
});

it('is idempotent — a re-run replaces, never appends', function (): void {
    logEvent(EventName::CourseViewed, $this->day->addHours(2), $this->course->id, $this->student->id);

    ($this->build)();
    ($this->build)();
    ($this->build)();

    /*
     * The recovery path for a failed night is "run it again". A builder that
     * appended would make that a corruption.
     */
    expect(DailyCourseStat::query()->count())->toBe(1)
        ->and(DailyCourseStat::query()->sole()->views)->toBe(1);
});

it('does not let a neighbouring day leak in', function (): void {
    // Half-open ranges: occurred_at has millisecond precision, and BETWEEN
    // '...23:59:59' drops the last second of every day.
    logEvent(EventName::CourseViewed, $this->day->subSecond(), $this->course->id, $this->student->id);
    logEvent(EventName::CourseViewed, $this->day->addDay(), $this->course->id, $this->student->id);
    logEvent(EventName::CourseViewed, $this->day->addDay()->subMillisecond(), $this->course->id, $this->student->id);

    ($this->build)();

    expect(DailyCourseStat::query()->sole()->views)->toBe(1);
});

it('takes money from the ledger, not the log', function (): void {
    $order = Order::factory()->create([
        'user_id' => $this->student->id,
        'status' => OrderStatus::Paid,
        'currency' => 'BDT',
        'subtotal_minor' => 250000,
        'discount_minor' => 0,
        'total_minor' => 250000,
        'paid_at' => $this->day->addHours(9),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'purchasable_type' => (new Course)->getMorphClass(),
        'purchasable_id' => $this->course->id,
        'title_snapshot' => $this->course->title,
        'unit_amount_minor' => 250000,
        'total_minor' => 250000,
    ]);

    /*
     * A `payment_completed` event exists too, and is deliberately NOT what
     * this reads: an order is not a course, and splitting a payment inside an
     * event would give the platform total and the per-course totals two
     * definitions free to disagree.
     */
    ($this->build)();

    expect(DailyCourseStat::query()->sole()->revenue_minor)->toBe(250000)
        ->and(DailyPlatformStat::query()->sole()->revenue_minor)->toBe(250000);
});

it('ignores an order that was never paid', function (): void {
    Order::factory()->create([
        'user_id' => $this->student->id,
        'status' => OrderStatus::AwaitingPayment,
        'currency' => 'BDT',
        'total_minor' => 999900,
        'paid_at' => null,
    ]);

    ($this->build)();

    // An order is an intention; a capture is a fact. A chart built on
    // intentions reports money nobody paid.
    expect(DailyPlatformStat::query()->sole()->revenue_minor)->toBe(0);
});

it('gives a course a row for a day with a sale and no page views', function (): void {
    $order = Order::factory()->create([
        'user_id' => $this->student->id,
        'status' => OrderStatus::Paid,
        'currency' => 'BDT',
        'total_minor' => 5000,
        'paid_at' => $this->day->addHours(9),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'purchasable_type' => (new Course)->getMorphClass(),
        'purchasable_id' => $this->course->id,
        'title_snapshot' => $this->course->title,
        'unit_amount_minor' => 5000,
        'total_minor' => 5000,
    ]);

    ($this->build)();

    expect(DailyCourseStat::query()->sole()->revenue_minor)->toBe(5000);
});

it('rolls the course rows up by owner rather than re-reading the log', function (): void {
    logEvent(EventName::CourseEnrolled, $this->day->addHours(4), $this->course->id, $this->student->id);

    ($this->build)();

    $row = DailyInstructorStat::query()->sole();

    expect($row->instructor_id)->toBe($this->instructor->id)
        ->and($row->enrollments)->toBe(1);
});

it('counts new accounts in this academy only', function (): void {
    // Accounts are CENTRAL, so this is a separate query on a pinned model
    // rather than a join across the boundary (§ Multi-tenancy).
    User::factory()->withRole(RoleKey::Student)->create(['created_at' => $this->day->addHours(6)]);

    ($this->build)();

    expect(DailyPlatformStat::query()->sole()->new_users)->toBeGreaterThanOrEqual(1);
});

it('writes nothing for a silent day', function (): void {
    ($this->build)(CarbonImmutable::now('UTC')->subDays(30)->startOfDay());

    // A platform row is still written — zero is a real answer for a day the
    // academy existed — but no course invented one out of nothing.
    expect(DailyCourseStat::query()->count())->toBe(0);
});
