<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\ModerateReview;
use App\Domain\Engagement\Actions\RecalculateCourseRating;
use App\Domain\Engagement\Actions\ReplyToReview;
use App\Domain\Engagement\Actions\SubmitReview;
use App\Domain\Engagement\Enums\ReviewStatus;
use App\Domain\Engagement\Exceptions\ReviewRejected;
use App\Domain\Engagement\Models\Review;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * THE PHASE 12 EXIT CRITERION: rating averages are columns, not AVG() on
 * every card. Everything here exists to prove the column is right, stays
 * right, and is never computed on a read path.
 */

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    $this->course->setting->update(['enable_reviews' => true, 'moderate_reviews' => false]);
});

function reviewer(Course $course): User
{
    $user = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $user->id]);

    return $user;
}

/* -------------------------------------------------- the maintained aggregate */

it('writes the average and count onto the course as reviews land', function (): void {
    app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 5);
    app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 3);

    expect($this->course->fresh())
        ->rating_count->toBe(2)
        ->and((float) $this->course->fresh()->rating_avg)->toBe(4.0);
});

it('renders a course list without computing an average', function (): void {
    foreach (range(1, 3) as $_) {
        app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 4);
    }

    $student = User::factory()->withRole(RoleKey::Student)->create();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($student)->getJson('/api/v1/courses')->assertOk();

    // The whole criterion, asserted directly: no aggregate over reviews on
    // the read path, however many cards the page renders.
    $aggregates = array_filter(
        $queries,
        fn (string $sql) => str_contains($sql, 'avg(') || str_contains($sql, 'AVG('),
    );

    expect($aggregates)->toBeEmpty();
});

it('drops the average back to zero when the last review is withdrawn', function (): void {
    $user = reviewer($this->course);
    app(SubmitReview::class)->handle($user, $this->course, 5);

    expect($this->course->fresh()->rating_count)->toBe(1);

    Review::where('user_id', $user->id)->firstOrFail()->delete();
    app(RecalculateCourseRating::class)->handle($this->course->fresh());

    // Zero, not null: `rating_count` is what tells a card to say "not rated
    // yet" rather than rendering a zero-star course.
    expect($this->course->fresh())
        ->rating_count->toBe(0)
        ->and((float) $this->course->fresh()->rating_avg)->toBe(0.0);
});

it('counts only published reviews toward the average', function (): void {
    $this->course->setting->update(['moderate_reviews' => true]);

    app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 1);
    app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 1);

    // Pending reviews must not drag a course's rating down before a human has
    // looked at them.
    expect($this->course->fresh()->rating_count)->toBe(0);

    $review = Review::first();
    app(ModerateReview::class)->handle($review, ReviewStatus::Published);

    expect($this->course->fresh()->rating_count)->toBe(1);
});

it('removes a rejected review from the average without deleting it', function (): void {
    $review = app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 5);

    app(ModerateReview::class)->handle($review, ReviewStatus::Rejected);

    expect($this->course->fresh()->rating_count)->toBe(0)
        // Kept: an academy that could silently delete criticism would make its
        // own rating meaningless.
        ->and(Review::count())->toBe(1);
});

/* ------------------------------------------------------------- one per learner */

it('replaces a learners review rather than appending another', function (): void {
    $user = reviewer($this->course);

    app(SubmitReview::class)->handle($user, $this->course, 5);
    app(SubmitReview::class)->handle($user, $this->course, 1);

    expect(Review::count())->toBe(1)
        ->and($this->course->fresh()->rating_count)->toBe(1)
        // Otherwise a learner could push a course's average around at will.
        ->and((float) $this->course->fresh()->rating_avg)->toBe(1.0);
});

it('holds one review per learner per course at the database level', function (): void {
    $user = reviewer($this->course);
    app(SubmitReview::class)->handle($user, $this->course, 5);

    expect(fn () => Review::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $user->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

/* ----------------------------------------------------------------- refusals */

it('refuses a review from somebody who never enrolled', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    expect(fn () => app(SubmitReview::class)->handle($stranger, $this->course, 5))
        ->toThrow(ReviewRejected::class);
});

it('still accepts a review from somebody whose access has expired', function (): void {
    /*
     * They genuinely took the course. CourseAccess would say no — it answers
     * "may they consume this?" — which is the wrong question here.
     */
    $user = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $user->id,
        'expires_at' => now()->subDay(),
    ]);

    expect(app(SubmitReview::class)->handle($user, $this->course, 4)->rating)->toBe(4);
});

it('refuses when the course has reviews turned off', function (): void {
    $this->course->setting->update(['enable_reviews' => false]);

    expect(fn () => app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 5))
        ->toThrow(ReviewRejected::class);
});

/* ------------------------------------------------------------ author content */

it('sanitises the body on write, not on render', function (): void {
    $review = app(SubmitReview::class)->handle(
        reviewer($this->course),
        $this->course,
        5,
        'Great',
        '<p>Good <script>alert(1)</script>course</p>',
    );

    // Stored safe, so the API, mobile and any export are all safe.
    expect($review->body)->not->toContain('<script>')
        ->and($review->body)->toContain('Good');
});

it('does not let an instructor reply change the rating or the status', function (): void {
    // Otherwise the average becomes a function of who bothered to respond.
    $review = app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 2);

    app(ReplyToReview::class)->handle($review, '<p>Sorry to hear that.</p>');

    expect($review->fresh())
        ->rating->toBe(2)
        ->status->toBe(ReviewStatus::Published)
        ->and((float) $this->course->fresh()->rating_avg)->toBe(2.0);
});

it('clears replied_at when a reply is removed', function (): void {
    $review = app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 3);
    app(ReplyToReview::class)->handle($review, 'A reply.');

    expect($review->fresh()->replied_at)->not->toBeNull();

    app(ReplyToReview::class)->handle($review->fresh(), '');

    // "Replied" and "has a reply" must not disagree.
    expect($review->fresh())
        ->replied_at->toBeNull()
        ->instructor_reply->toBeNull();
});

/* ------------------------------------------------------------ reconciliation */

it('notices and corrects a rating that has drifted', function (): void {
    app(SubmitReview::class)->handle(reviewer($this->course), $this->course, 5);

    // A missed event, a direct edit, a restored backup. All look like this.
    DB::table('courses')->where('id', $this->course->id)
        ->update(['rating_avg' => 1.0, 'rating_count' => 99]);

    $this->artisan('engagement:reconcile')
        ->expectsOutputToContain('drifted')
        ->assertSuccessful();

    expect($this->course->fresh())
        ->rating_count->toBe(1)
        ->and((float) $this->course->fresh()->rating_avg)->toBe(5.0);
});
