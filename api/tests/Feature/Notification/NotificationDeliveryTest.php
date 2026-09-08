<?php

declare(strict_types=1);

use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Catalog\Enums\CourseInstructorRole;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseInstructor;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Engagement\Actions\PostDiscussion;
use App\Domain\Engagement\Actions\PublishAnnouncement;
use App\Domain\Engagement\Actions\ReplyToDiscussion;
use App\Domain\Engagement\Events\DiscussionReplied;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification as InboxNotification;
use App\Domain\Notification\Notifications\DomainNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * Who hears about what.
 *
 * Every case here is really the same question asked five ways: is this
 * somebody ELSE acting on your work, or is it you watching your own click?
 * The second kind is what teaches people to ignore a bell.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(['title' => 'Modern Bengali Poetry']),
        [1],
    );

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

/* ------------------------------------------------------------ announcements */

it('tells everybody enrolled, and never the person who pressed publish', function (): void {
    $classmate = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $classmate->id]);

    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
        'title' => 'Week 3 is up',
    ]);

    Notification::fake();

    app(PublishAnnouncement::class)->handle($announcement);

    Notification::assertSentTo([$this->student, $classmate], DomainNotification::class);
    Notification::assertNotSentTo($this->instructor, DomainNotification::class);
});

it('sends nothing when the author asked not to notify', function (): void {
    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
        'notify' => false,
    ]);

    Notification::fake();

    app(PublishAnnouncement::class)->handle($announcement);

    /*
     * `notify` is about DELIVERY, not publication: the announcement is still
     * published and still visible in the course.
     */
    Notification::assertNothingSent();
    expect($announcement->refresh()->isPublished())->toBeTrue();
});

it('does not announce to a suspended learner', function (): void {
    $suspended = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $suspended->id,
        'status' => EnrollmentStatus::Suspended,
        'suspended_at' => now(),
    ]);

    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    Notification::fake();

    app(PublishAnnouncement::class)->handle($announcement);

    // Who hears about a course and who may open it must not disagree.
    Notification::assertSentTo($this->student, DomainNotification::class);
    Notification::assertNotSentTo($suspended, DomainNotification::class);
});

it('publishing twice announces once', function (): void {
    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
    ]);

    Notification::fake();

    app(PublishAnnouncement::class)->handle($announcement);
    app(PublishAnnouncement::class)->handle($announcement->refresh());

    // The event fires on the TRANSITION; a typo fix must not re-notify.
    Notification::assertSentToTimes($this->student, DomainNotification::class, 1);
});

/* --------------------------------------------------------------------- Q&A */

it('tells the asker when somebody answers, and not the person answering', function (): void {
    $discussion = Discussion::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    Notification::fake();

    app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, '<p>Good question.</p>');

    Notification::assertSentTo($this->student, DomainNotification::class);
    Notification::assertNotSentTo($this->instructor, DomainNotification::class);
});

it('tells the person a nested reply hangs under, as well as the asker', function (): void {
    $classmate = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $classmate->id]);

    $discussion = Discussion::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    $parent = app(ReplyToDiscussion::class)
        ->handle($classmate, $discussion, '<p>I think it is the metre.</p>');

    Notification::fake();

    app(ReplyToDiscussion::class)
        ->handle($this->instructor, $discussion, '<p>Close.</p>', $parent);

    // The two people actually addressed — not the whole thread.
    Notification::assertSentTo([$this->student, $classmate], DomainNotification::class);
    Notification::assertNotSentTo($this->instructor, DomainNotification::class);
});

it('says nothing when a reply is deleted', function (): void {
    $discussion = Discussion::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    Notification::fake();

    // DiscussionReplied fires for deletions too, with a null reply id.
    DiscussionReplied::dispatch($discussion->id, null);

    Notification::assertNothingSent();
});

it('tells course staff about a new question', function (): void {
    $assistant = User::factory()->instructor()->create();
    CourseInstructor::create([
        'course_id' => $this->course->id,
        'user_id' => $assistant->id,
        'role' => CourseInstructorRole::CoInstructor,
        'position' => 1,
    ]);

    $this->course->setting->update(['enable_qa' => true]);

    Notification::fake();

    app(PostDiscussion::class)->handle(
        $this->student,
        $this->course->fresh(),
        'What does the third stanza mean?',
        '<p>I cannot follow the turn.</p>',
    );

    Notification::assertSentTo([$this->instructor, $assistant], DomainNotification::class);
    Notification::assertNotSentTo($this->student, DomainNotification::class);
});

it('does not tell an instructor about their own question', function (): void {
    $this->course->setting->update(['enable_qa' => true]);

    Notification::fake();

    app(PostDiscussion::class)->handle(
        $this->instructor,
        $this->course->fresh(),
        'A note to the class',
        '<p>Read ahead.</p>',
    );

    Notification::assertNothingSent();
});

/* ---------------------------------------------------------------- grading */

it('tells the learner when a human has marked their work', function (): void {
    $scenario = assignmentScenario();

    $submission = AssignmentSubmission::factory()->create([
        'assignment_id' => $scenario['assignment']->id,
        'course_item_id' => $scenario['item']->id,
        'course_id' => $scenario['course']->id,
        'user_id' => $scenario['student']->id,
        'enrollment_id' => $scenario['enrollment']->id,
    ]);

    Notification::fake();

    AssignmentGraded::dispatch($submission, true);

    Notification::assertSentTo(
        $scenario['student'],
        DomainNotification::class,
        fn (DomainNotification $n): bool => $n->payload->type === NotificationType::AssignmentGraded
            && $n->payload->actionPath === "/learn/{$scenario['course']->uuid}/{$scenario['item']->uuid}",
    );
});

/* ----------------------------------------------------------- certificates */

it('congratulates on issue, not on render', function (): void {
    // The queue is synchronous here, so an un-faked disk writes a REAL pdf.
    Storage::fake('private');

    CertificateTemplate::factory()->create();
    $this->course->setting->update(['enable_certificate' => true]);

    $enrollment = Enrollment::query()
        ->where('course_id', $this->course->id)
        ->where('user_id', $this->student->id)
        ->firstOrFail();

    $enrollment->update(['completed_at' => now()]);

    Notification::fake();

    app(IssueCertificate::class)->handle($enrollment);

    Notification::assertSentTo(
        $this->student,
        DomainNotification::class,
        fn (DomainNotification $n): bool => $n->payload->type === NotificationType::CertificateIssued,
    );
});

/* ------------------------------------------------- the un-faked round trip */

it('writes a real inbox row in the academy schema and renders a real email', function (): void {
    /*
     * Nothing faked but the clock: this is the one test that proves the
     * database channel writes to the TENANT connection — User is pinned
     * central, and without LivesInTenantSchema this looks for `notifications`
     * in the wrong schema (§16).
     */
    $announcement = Announcement::factory()->create([
        'course_id' => $this->course->id,
        'author_id' => $this->instructor->id,
        'title' => 'Week 3 is up',
    ]);

    app(PublishAnnouncement::class)->handle($announcement);

    $row = InboxNotification::query()
        ->where('notifiable_id', $this->student->id)
        ->firstOrFail();

    expect($row->notifiable_type)->toBe('user')
        // A stable key, not a PHP class name.
        ->and($row->type)->toBe('announcement.published')
        ->and($row->data['title'])->toBe('Week 3 is up')
        ->and($row->data['action_path'])->toBe("/learn/{$this->course->uuid}/announcements")
        ->and($row->read_at)->toBeNull();
});
