<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyInstructorStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Analytics\Models\ItemFunnel;
use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseInstructor;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;

/*
 * The dashboards. Every one reads rollups only (ADR-08), and every one has to
 * refuse a reader who does not staff the course — the `.own` keys are held
 * GLOBALLY by every instructor, which is the §9 trap.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [2],
    );

    $this->today = CarbonImmutable::now('UTC')->startOfDay();

    DailyPlatformStat::create([
        'date' => $this->today->toDateString(),
        'new_users' => 4,
        'new_enrollments' => 7,
        'completions' => 2,
        'revenue_minor' => 500000,
        'currency' => 'BDT',
        'active_learners' => 11,
    ]);

    DailyCourseStat::create([
        'date' => $this->today->toDateString(),
        'course_id' => $this->course->id,
        'views' => 40,
        'enrollments' => 7,
        'completions' => 2,
        'revenue_minor' => 500000,
        'currency' => 'BDT',
        'active_learners' => 11,
    ]);
});

it('serves the academy overview from rollups', function (): void {
    $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/overview')
        ->assertOk()
        ->assertJsonPath('data.totals.new_enrollments', 7)
        ->assertJsonPath('data.totals.revenue_minor', 500000)
        // Named for what it is: distinct people cannot be summed across days
        // without counting a regular five times over.
        ->assertJsonPath('data.totals.peak_daily_active', 11)
        ->assertJsonPath('data.top_courses.0.course.title', $this->course->title)
        // Said out loud rather than left for a reader to assume.
        ->assertJsonPath('data.range.timezone', 'UTC');
});

it('returns a dense series, so a chart cannot draw across a gap', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/overview?from='.$this->today->subDays(6)->toDateString())
        ->assertOk();

    // Seven days asked for, seven days returned — six of them with no rollup
    // row at all.
    expect($response->json('data.series'))->toHaveCount(7);
    expect($response->json('data.series.0.new_enrollments'))->toBe(0);
    expect($response->json('data.series.6.new_enrollments'))->toBe(7);
});

it('caps a range nobody meant to ask for', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/overview?from=1990-01-01')
        ->assertOk();

    // A dashboard that can ask for all of history can ask for a table scan.
    expect($response->json('data.series'))->toHaveCount(366);
});

it('refuses the academy overview to somebody who only holds the own key', function (): void {
    $this->actingAs($this->instructor)
        ->getJson('/api/v1/analytics/overview')
        ->assertForbidden();
});

it('lets course staff read their own course', function (): void {
    $this->actingAs($this->instructor)
        ->getJson("/api/v1/analytics/courses/{$this->course->uuid}")
        ->assertOk()
        ->assertJsonPath('data.totals.views', 40);
});

it('refuses another instructor the same course', function (): void {
    /*
     * THE trap this gate exists for. Every instructor holds
     * `analytics.view.own` globally, so a union check would hand any of them
     * the revenue figures for any course in the academy.
     */
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/analytics/courses/{$this->course->uuid}")
        ->assertForbidden();
});

it('lets a co-instructor read it', function (): void {
    $assistant = User::factory()->instructor()->create();
    CourseInstructor::create([
        'course_id' => $this->course->id,
        'user_id' => $assistant->id,
        'role' => CourseInstructorRole::CoInstructor,
        'position' => 1,
    ]);

    $this->actingAs($assistant)
        ->getJson("/api/v1/analytics/courses/{$this->course->uuid}")
        ->assertOk();
});

it('serves the funnel in curriculum order, not worst first', function (): void {
    $items = $this->course->items()->orderBy('position')->get();

    ItemFunnel::create([
        'course_item_id' => $items[0]->id,
        'course_id' => $this->course->id,
        'started' => 10, 'completed' => 9, 'avg_seconds' => 120,
        'drop_off_rate' => 0.1, 'computed_at' => now(),
    ]);
    ItemFunnel::create([
        'course_item_id' => $items[1]->id,
        'course_id' => $this->course->id,
        'started' => 9, 'completed' => 2, 'avg_seconds' => null,
        'drop_off_rate' => 0.7778, 'computed_at' => now(),
    ]);

    $response = $this->actingAs($this->instructor)
        ->getJson("/api/v1/analytics/courses/{$this->course->uuid}/funnel")
        ->assertOk();

    /*
     * "They drop out after the third video" is the insight, and a list sorted
     * by severity destroys the adjacency that makes it visible.
     */
    expect($response->json('data.items.0.item_id'))->toBe($items[0]->uuid)
        ->and($response->json('data.items.1.drop_off_rate'))->toBe(0.7778)
        // Null, not zero: a text lesson has no seconds to average.
        ->and($response->json('data.items.1.avg_seconds'))->toBeNull();
});

it('lets an instructor read their own figures and nobody else read them', function (): void {
    DailyInstructorStat::create([
        'date' => $this->today->toDateString(),
        'instructor_id' => $this->instructor->id,
        'enrollments' => 7,
        'revenue_minor' => 500000,
        'currency' => 'BDT',
        'rating_avg' => 4.5,
    ]);

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/analytics/instructors/{$this->instructor->uuid}")
        ->assertOk()
        ->assertJsonPath('data.totals.enrollments', 7);

    /*
     * `{user}` binds by uuid and resolves globally, so membership of the
     * caller's own identity has to be checked in the controller — otherwise
     * `analytics.view.own` reads as "view anybody's".
     */
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/analytics/instructors/{$this->instructor->uuid}")
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/analytics/instructors/{$this->instructor->uuid}")
        ->assertOk();
});

it('needs a signed-in caller', function (): void {
    $this->getJson('/api/v1/analytics/overview')->assertUnauthorized();
});
