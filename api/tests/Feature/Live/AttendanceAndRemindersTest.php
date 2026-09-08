<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Live\Actions\RecordAttendance;
use App\Domain\Live\Enums\AttendanceSource;
use App\Domain\Live\Models\Cohort;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\SessionAttendance;
use App\Domain\Notification\Models\Notification;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

it('completes the curriculum item when somebody turns up', function (): void {
    /*
     * A live session is completable but NOT self-markable, like a quiz and an
     * assignment. The difference is only what counts as earning it: there is
     * no score and no submission, so the evidence is attendance.
     */
    $section = CourseSection::factory()->create(['course_id' => $this->course->id]);
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $item = CourseItem::create([
        'course_id' => $this->course->id,
        'section_id' => $section->id,
        'position' => 99,
        'type' => ItemType::LiveSession,
        'itemable_type' => $session->getMorphClass(),
        'itemable_id' => $session->id,
        'title' => 'Week 1 call',
        'is_published' => true,
    ]);

    app(RecordAttendance::class)->handle($session, $this->student->id);

    $progress = ItemProgress::query()
        ->where('course_item_id', $item->id)
        ->where('user_id', $this->student->id)
        ->sole();

    expect($progress->status)->toBe(ItemProgressStatus::Completed);
});

it('does nothing for a session that is not on the spine', function (): void {
    // A cohort's weekly call is real attendance with nothing to complete.
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    app(RecordAttendance::class)->handle($session, $this->student->id);

    expect(ItemProgress::query()->count())->toBe(0)
        ->and(SessionAttendance::query()->count())->toBe(1);
});

it('lets a host mark somebody present, and that beats a click', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    app(RecordAttendance::class)->handle($session, $this->student->id, AttendanceSource::SelfJoin);
    app(RecordAttendance::class)->handle($session, $this->student->id, AttendanceSource::Host);

    /*
     * A person saying "they were there" is better evidence than a link being
     * opened, and somebody whose connection died two minutes in should not be
     * downgraded by the system that watched them try.
     */
    expect(SessionAttendance::query()->sole()->source)->toBe(AttendanceSource::Host);

    // ...and a later click never downgrades the host's mark.
    app(RecordAttendance::class)->handle($session, $this->student->id, AttendanceSource::SelfJoin);
    expect(SessionAttendance::query()->sole()->source)->toBe(AttendanceSource::Host);
});

it('never shortens a recorded duration', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $record = app(RecordAttendance::class);
    $record->handle($session, $this->student->id);

    $this->travel(50)->minutes();
    $record->leave($session, $this->student->id);

    $long = SessionAttendance::query()->sole()->duration_seconds;

    // A second, shorter visit must not shorten the record of the first — the
    // same rule as `watch_max_seconds` in the player.
    $record->handle($session, $this->student->id);
    $record->leave($session, $this->student->id);

    expect(SessionAttendance::query()->sole()->duration_seconds)->toBe($long);
});

it('shows a roster of everybody expected, present and absent', function (): void {
    $absentee = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $absentee->id]);

    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    app(RecordAttendance::class)->handle($session, $this->student->id);

    $response = $this->actingAs($this->instructor)
        ->getJson("/api/v1/live-sessions/{$session->uuid}/attendance")
        ->assertOk()
        ->assertJsonPath('data.expected', 2)
        ->assertJsonPath('data.present', 1);

    // "Who missed it?" is the question a roster is usually opened for, and a
    // list of only the people who turned up cannot answer it.
    $roster = collect($response->json('data.roster'));

    expect($roster->firstWhere('user_id', $this->student->id)['attended'])->toBeTrue()
        ->and($roster->firstWhere('user_id', $absentee->id)['attended'])->toBeFalse();
});

it('refuses a roster for a course somebody does not staff', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $stranger = User::factory()->instructor()->create();

    // Holding `attendance.mark` globally is not permission to read another
    // course's roster.
    $this->actingAs($stranger)
        ->getJson("/api/v1/live-sessions/{$session->uuid}/attendance")
        ->assertForbidden();
});

it('narrows a cohort session to that cohort', function (): void {
    $cohort = Cohort::factory()->open()->create(['course_id' => $this->course->id]);
    $inCohort = User::factory()->withRole(RoleKey::Student)->create();

    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'cohort_id' => $cohort->id,
        'user_id' => $inCohort->id,
    ]);

    $session = LiveSession::factory()->startingAt(now()->addMinutes(5))->create([
        'course_id' => $this->course->id,
        'cohort_id' => $cohort->id,
        'host_id' => $this->instructor->id,
    ]);

    // Showing a cohort's session to everybody on the course is the mistake
    // that makes cohorts pointless.
    $this->actingAs($inCohort)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertOk();

    $this->actingAs($this->student)
        ->postJson("/api/v1/live-sessions/{$session->uuid}/join")
        ->assertForbidden();
});

it('reminds the audience once', function (): void {
    LiveSession::factory()->startingAt(now()->addMinutes(20))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $this->artisan('live:remind')->assertSuccessful();
    $this->artisan('live:remind')->assertSuccessful();

    // `reminder_sent_at` is claimed BEFORE the notifications go out: a crash
    // halfway under-notifies a few, where the reverse mails everybody twice.
    expect(Notification::query()
        ->where('notifiable_id', $this->student->id)
        ->where('type', 'session.reminder')
        ->count())->toBe(1);
});

it('does not remind about a session already under way', function (): void {
    /*
     * Without a floor on the window, a session missed while the scheduler was
     * down would be reminded about after it started — an email saying "starts
     * in 30 minutes" about something that has finished.
     */
    LiveSession::factory()->startingAt(now()->subMinutes(5))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $this->artisan('live:remind')->assertSuccessful();

    expect(Notification::query()->where('type', 'session.reminder')->count())->toBe(0);
});

it('puts the right things in a learner calendar', function (): void {
    $mine = LiveSession::factory()->startingAt(now()->addDays(2))->create([
        'course_id' => $this->course->id,
        'host_id' => $this->instructor->id,
    ]);

    $otherCourse = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    LiveSession::factory()->startingAt(now()->addDays(3))->create([
        'course_id' => $otherCourse->id,
        'host_id' => $this->instructor->id,
    ]);

    $response = $this->actingAs($this->student)
        ->getJson('/api/v1/calendar')
        ->assertOk();

    expect($response->json('data.sessions'))->toHaveCount(1)
        ->and($response->json('data.sessions.0.id'))->toBe($mine->uuid);
});
