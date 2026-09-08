<?php

declare(strict_types=1);

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use App\Domain\Analytics\Models\AnalyticsEvent;
use App\Domain\Assessment\Enums\AttemptStatus;
use App\Domain\Assessment\Events\AssignmentGraded;
use App\Domain\Assessment\Events\QuizAttemptGraded;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Actions\IssueCertificate;
use App\Domain\Certification\Models\CertificateTemplate;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Events\CourseCompleted;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * The server half of the log. Everything here is raised by a listener on a
 * domain event, where it cannot be lied about — which is the reason the client
 * endpoint's allowlist is as small as it is.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('logs an enrolment with how they got in', function (): void {
    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    CourseEnrolled::dispatch($enrollment);

    $event = AnalyticsEvent::query()->where('name', EventName::CourseEnrolled)->sole();

    expect($event->course_id)->toBe($this->course->id)
        ->and($event->actor_id)->toBe($this->student->id)
        // The server raised it, so it is not a claim anybody made about
        // themselves.
        ->and($event->source)->toBe(EventSource::Api)
        ->and($event->properties['source'] ?? null)->not->toBeNull();
});

it('logs a completion with how long it took', function (): void {
    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'enrolled_at' => now()->subDays(9),
        'completed_at' => now(),
    ]);

    CourseCompleted::dispatch($enrollment);

    $event = AnalyticsEvent::query()->where('name', EventName::CourseCompleted)->sole();

    /*
     * Settled here rather than derived later: a report computing it from two
     * rows would have to find the enrolment, and the enrolment may since have
     * been deleted.
     */
    expect((int) $event->properties['days_to_complete'])->toBe(9);
});

it('logs a passed quiz but not a failed one', function (): void {
    $scenario = quizScenario(['passing_score_percent' => 50]);

    $attempt = $scenario['quiz']->attempts()->create([
        'course_item_id' => $scenario['item']->id,
        'course_id' => $scenario['course']->id,
        'user_id' => $scenario['student']->id,
        'enrollment_id' => $scenario['enrollment']->id,
        'attempt_number' => 1,
        'status' => AttemptStatus::Graded,
        'started_at' => now()->subMinutes(5),
        'submitted_at' => now(),
        'total_points' => '10.00',
        'earned_points' => '8.00',
        'percent' => '80.00',
    ]);

    QuizAttemptGraded::dispatch($attempt, false);

    // A failure is already countable as submitted minus passed. A second name
    // for it would be a second definition, free to drift.
    expect(AnalyticsEvent::query()->where('name', EventName::QuizPassed)->count())->toBe(0);

    QuizAttemptGraded::dispatch($attempt, true);

    $event = AnalyticsEvent::query()->where('name', EventName::QuizPassed)->sole();

    expect($event->course_item_id)->toBe($scenario['item']->id)
        ->and((float) $event->properties['percent'])->toBe(80.0);
});

it('logs a graded assignment against the LEARNER, not the grader', function (): void {
    $scenario = assignmentScenario();

    $submission = AssignmentSubmission::factory()->create([
        'assignment_id' => $scenario['assignment']->id,
        'course_item_id' => $scenario['item']->id,
        'course_id' => $scenario['course']->id,
        'user_id' => $scenario['student']->id,
        'enrollment_id' => $scenario['enrollment']->id,
        'graded_at' => now(),
    ]);

    AssignmentGraded::dispatch($submission, true);

    $event = AnalyticsEvent::query()->where('name', EventName::AssignmentGraded)->sole();

    // `actor_id` answers "whose activity was this?".
    expect($event->actor_id)->toBe($scenario['student']->id)
        ->and($event->properties['passed'])->toBeTrue();
});

it('logs an issued certificate', function (): void {
    Storage::fake('private');
    CertificateTemplate::factory()->create();
    $this->course->setting->update(['enable_certificate' => true]);

    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'completed_at' => now(),
    ]);

    app(IssueCertificate::class)->handle($enrollment);

    $event = AnalyticsEvent::query()->where('name', EventName::CertificateIssued)->sole();

    expect($event->actor_id)->toBe($this->student->id)
        ->and($event->course_id)->toBe($this->course->id);
});

it('never lets a log failure break the thing it observed', function (): void {
    /*
     * Analytics OBSERVES the system. A full disk or a lock timeout must lose
     * a row in a traffic count, not turn somebody's lesson into a 500.
     */
    Schema::drop('analytics_events');

    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    expect(fn () => CourseEnrolled::dispatch($enrollment))
        ->not->toThrow(Throwable::class);
});
