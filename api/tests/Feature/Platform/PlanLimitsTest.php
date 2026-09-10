<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Actions\ChangeEnrollmentStatus;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Identity\Enums\InstructorStatus;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\ReconcileUsageCounters;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Support\UsageCounters;

/*
 * Plan limits, enforced.
 *
 * The two halves are deliberately different and both are tested here: an
 * academy's OWN decisions (a course, an instructor seat) are blocked at the
 * cap, and a LEARNER's decision to enrol never is. A student who has just
 * paid must not be turned away because their academy is on the wrong plan —
 * that cap is counted, surfaced as over-limit, and left to the operator.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->instructor = User::factory()->instructor()->create();
    $this->counters = app(UsageCounters::class);

    // The harness plan is uncapped so unrelated suites never trip a limit.
    // Each test here caps it, and the UPDATE rolls back with the test.
    $this->plan = Plan::query()
        ->whereKey(Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())->value('plan_id'))
        ->firstOrFail();

    $this->capAt = function (UsageMetric $metric, int $headroom): void {
        $this->plan->forceFill([
            'limits' => [$metric->planKey() => $this->counters->get($metric) + $headroom],
        ])->save();
    };

    $this->createCourse = fn () => $this->actingAs($this->instructor)
        ->postJson('/api/v1/studio/courses', ['title' => 'A course about limits']);
});

/* -------------------------------------------------- courses, enforced */

it('lets an academy create a course while the plan has room', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 1);

    ($this->createCourse)()->assertCreated();
});

it('refuses the course that would exceed the plan, with 402 and how to fix it', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 1);

    ($this->createCourse)()->assertCreated();

    $response = ($this->createCourse)()->assertStatus(402);

    expect($response)->toBeApiError('plan_limit_reached')
        ->and($response->json('error.meta.metric'))->toBe('courses_total')
        ->and($response->json('error.meta.limit'))->toBe($response->json('error.meta.used'))
        ->and($response->json('error.meta.plan'))->toBe($this->plan->name);
});

/* A cap counts DRAFTS. Otherwise Starter builds a catalogue and drip-publishes it. */
it('counts drafts against the course cap, not just published courses', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 1);

    ($this->createCourse)()->assertCreated();

    expect(Course::query()->latest('id')->first()->status->value)->toBe('draft');

    ($this->createCourse)()->assertStatus(402);
});

it('never blocks an academy on an uncapped plan', function (): void {
    $this->plan->forceFill(['limits' => []])->save();

    ($this->createCourse)()->assertCreated();
    ($this->createCourse)()->assertCreated();
    ($this->createCourse)()->assertCreated();
});

/* Deleting frees a slot, or a cap is a ratchet nobody can escape. */
it('frees a slot when a course is deleted', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 1);

    $uuid = ($this->createCourse)()->assertCreated()->json('data.id');
    ($this->createCourse)()->assertStatus(402);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/courses/{$uuid}")
        ->assertSuccessful();

    ($this->createCourse)()->assertCreated();
});

/* -------------------------------------------------- instructor seats */

it('refuses to approve an instructor beyond the seat cap', function (): void {
    ($this->capAt)(UsageMetric::Instructors, 0);

    $profile = User::factory()->instructor(InstructorStatus::Pending)->create()->instructorProfile;

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/instructors/{$profile->id}/review", ['decision' => 'approved'])
        ->assertStatus(402);

    expect($response)->toBeApiError('plan_limit_reached')
        ->and($response->json('error.meta.metric'))->toBe('instructors')
        ->and($profile->refresh()->status)->toBe(InstructorStatus::Pending);
});

/* An academy at its cap must always be able to free a seat. */
it('still lets an academy reject an instructor at the seat cap', function (): void {
    ($this->capAt)(UsageMetric::Instructors, 0);

    $profile = User::factory()->instructor(InstructorStatus::Pending)->create()->instructorProfile;

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/instructors/{$profile->id}/review", ['decision' => 'rejected'])
        ->assertOk();
});

/*
 * Order matters: the already-approved conflict is checked BEFORE the cap, so
 * re-approving somebody never charges them for a seat they already hold.
 */
it('does not spend a seat re-approving an instructor who already holds one', function (): void {
    $profile = User::factory()->instructor(InstructorStatus::Approved)->create()->instructorProfile;
    ($this->capAt)(UsageMetric::Instructors, 0);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/instructors/{$profile->id}/review", ['decision' => 'approved'])
        ->assertStatus(409);
});

/* -------------------------------------------------- students, counted only */

it('counts a learner as one student however many courses they take', function (): void {
    $learner = User::factory()->create();
    $enroll = app(EnrollInCourse::class);

    $enroll->handle($learner, Course::factory()->published()->create());
    $enroll->handle($learner, Course::factory()->published()->create());

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

/*
 * The bug this event exists for. `suspend()` is reachable on an already
 * suspended row, and a tally that treats the OPERATION as the transition
 * decrements twice — taking the seat off somebody else.
 */
it('does not spend a second decrement on a re-suspend', function (): void {
    $steady = User::factory()->create();
    app(EnrollInCourse::class)->handle($steady, Course::factory()->published()->create());

    $leaver = User::factory()->create();
    $enrollment = app(EnrollInCourse::class)
        ->handle($leaver, Course::factory()->published()->create());

    expect($this->counters->get(UsageMetric::Students))->toBe(2);

    $status = app(ChangeEnrollmentStatus::class);
    $status->suspend($enrollment);
    $status->suspend($enrollment);

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

/* The mirror: extending a LIVE enrolment changes nothing and must count nothing. */
it('does not spend a second increment on extending a live enrolment', function (): void {
    $learner = User::factory()->create();
    $enrollment = app(EnrollInCourse::class)
        ->handle($learner, Course::factory()->published()->create());

    app(ChangeEnrollmentStatus::class)->extend($enrollment, now()->addYear());

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

/* Suspending ONE of two enrolments leaves them a student on the other. */
it('keeps the seat while any other enrolment still grants access', function (): void {
    $learner = User::factory()->create();
    $first = app(EnrollInCourse::class)
        ->handle($learner, Course::factory()->published()->create());
    app(EnrollInCourse::class)->handle($learner, Course::factory()->published()->create());

    app(ChangeEnrollmentStatus::class)->suspend($first);

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

it('takes the seat back and returns it across a suspend and reinstate', function (): void {
    $learner = User::factory()->create();
    $enrollment = app(EnrollInCourse::class)
        ->handle($learner, Course::factory()->published()->create());

    $status = app(ChangeEnrollmentStatus::class);
    $status->suspend($enrollment);

    expect($this->counters->get(UsageMetric::Students))->toBe(0);

    $status->reinstate($enrollment);

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

it('gives the seat back when the last enrolment is revoked', function (): void {
    $learner = User::factory()->create();
    $enrollment = app(EnrollInCourse::class)
        ->handle($learner, Course::factory()->published()->create());

    expect($this->counters->get(UsageMetric::Students))->toBe(1);

    app(ChangeEnrollmentStatus::class)->revoke($enrollment);

    expect($this->counters->get(UsageMetric::Students))->toBe(0);
});

/*
 * The decision recorded in UsageMetric::isEnforced(): a learner is never the
 * one who pays for their academy's plan being too small.
 */
it('lets a learner enrol into an academy that is over its student cap', function (): void {
    ($this->capAt)(UsageMetric::Students, 0);

    $course = Course::factory()->published()->create();

    app(EnrollInCourse::class)->handle(User::factory()->create(), $course);

    expect($this->counters->get(UsageMetric::Students))->toBe(1);
});

/* -------------------------------------------------- the usage panel */

it('reports usage against the plan for the academy admin', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 2);

    $response = $this->actingAs($this->admin)
        ->getJson('/api/v1/admin/academy/usage')
        ->assertOk();

    $courses = collect($response->json('data.limits'))->firstWhere('metric', 'courses_total');

    expect($response->json('data.plan.slug'))->toBe($this->plan->slug)
        ->and($courses['limit'])->toBe($courses['used'] + 2)
        ->and($courses['remaining'])->toBe(2)
        ->and($courses['at_limit'])->toBeFalse()
        ->and($courses['enforced'])->toBeTrue();

    // The student row is reported exactly like the rest, and marked as the
    // thing that will not stop anybody.
    $students = collect($response->json('data.limits'))->firstWhere('metric', 'students');
    expect($students['enforced'])->toBeFalse();
});

it('says a metric is uncapped with null rather than zero', function (): void {
    $this->plan->forceFill(['limits' => []])->save();

    $courses = collect(
        $this->actingAs($this->admin)->getJson('/api/v1/admin/academy/usage')->json('data.limits')
    )->firstWhere('metric', 'courses_total');

    expect($courses['limit'])->toBeNull()
        ->and($courses['remaining'])->toBeNull()
        ->and($courses['at_limit'])->toBeFalse();
});

/* A downgrade puts an academy over its cap. That is a state, not a corruption. */
it('reports over-limit without deleting anything when a plan shrinks', function (): void {
    ($this->capAt)(UsageMetric::CoursesTotal, 1);
    ($this->createCourse)()->assertCreated();

    $this->plan->forceFill([
        'limits' => ['max_courses' => 0],
    ])->save();

    $courses = collect(
        $this->actingAs($this->admin)->getJson('/api/v1/admin/academy/usage')->json('data.limits')
    )->firstWhere('metric', 'courses_total');

    expect($courses['over_limit'])->toBeTrue()
        ->and($courses['at_limit'])->toBeTrue()
        ->and($courses['remaining'])->toBe(0)
        ->and(Course::count())->toBeGreaterThan(0);
});

it('denies the usage panel to somebody without settings.view', function (): void {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/admin/academy/usage')
        ->assertForbidden();
});

/* -------------------------------------------------- drift */

it('reconciles the student counter to the same definition the listener uses', function (): void {
    $learner = User::factory()->create();
    app(EnrollInCourse::class)->handle($learner, Course::factory()->published()->create());
    app(EnrollInCourse::class)->handle($learner, Course::factory()->published()->create());

    // Break it the way a dead queue job would.
    $this->counters->set(UsageMetric::Students, null, 7);

    $drift = app(ReconcileUsageCounters::class)->handle();

    expect($this->counters->get(UsageMetric::Students))->toBe(1)
        ->and(collect($drift)->firstWhere('metric', 'students'))
        ->toMatchArray(['stored' => 7, 'actual' => 1, 'drift' => -6]);
});
