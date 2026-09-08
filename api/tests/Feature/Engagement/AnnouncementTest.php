<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\PublishAnnouncement;
use App\Domain\Engagement\Events\AnnouncementPublished;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

it('creates a draft, not a broadcast', function (): void {
    /*
     * A half-finished announcement reaching every enrolled learner because
     * somebody hit save is the failure this prevents.
     */
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/courses/{$this->course->uuid}/announcements", [
            'title' => 'Week 3 is up',
            'body' => '<p>New lessons.</p>',
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_published', false)
        ->assertJsonPath('data.published_at', null);
});

it('hides a draft from learners and shows it to staff', function (): void {
    Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->instructor)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows a published announcement to enrolled learners', function (): void {
    Announcement::factory()->published()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
        'title' => 'Week 3 is up',
    ]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Week 3 is up');
});

it('keeps a scheduled announcement hidden until its time', function (): void {
    // Evaluated live rather than swept, the same as drip and sale prices.
    $announcement = Announcement::factory()->scheduled()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertOk()->assertJsonCount(0, 'data');

    $this->travelTo(now()->addDays(2));

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertOk()->assertJsonCount(1, 'data');

    expect($announcement->fresh()->isPublished())->toBeTrue();
});

it('announces once, however many times it is saved afterwards', function (): void {
    /*
     * An instructor fixing a typo in a published announcement must not notify
     * everybody a second time.
     */
    Event::fake([AnnouncementPublished::class]);

    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    app(PublishAnnouncement::class)->handle($announcement);
    app(PublishAnnouncement::class)->handle($announcement->fresh());

    Event::assertDispatchedTimes(AnnouncementPublished::class, 1);
});

it('publishes and unpublishes over HTTP', function (): void {
    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/announcements/{$announcement->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.is_published', true);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/announcements/{$announcement->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.is_published', false);
});

/* ------------------------------------------------------------ authorization */

it('forbids a learner from posting an announcement', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/announcements", [
            'title' => 'Hello', 'body' => 'Everyone',
        ])
        ->assertForbidden();
});

it('forbids an unrelated instructor from announcing to this course', function (): void {
    /*
     * Every instructor holds announcement.manage globally, so a union check
     * would let any of them email another course's students — the
     * CourseScopedAccessTest trap, and a loud one here.
     */
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/courses/{$this->course->uuid}/announcements", [
            'title' => 'Hello', 'body' => 'Everyone',
        ])
        ->assertForbidden();
});

it('forbids somebody with no access from reading announcements', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/courses/{$this->course->uuid}/announcements")
        ->assertForbidden();
});
