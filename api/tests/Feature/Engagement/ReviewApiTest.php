<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\SubmitReview;
use App\Domain\Engagement\Models\Review;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [1],
    );
    $this->course->setting->update(['enable_reviews' => true, 'moderate_reviews' => false]);

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

it('writes a review and moves the course rating in one request', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/reviews", [
            'rating' => 4,
            'title' => 'Solid',
            'body' => '<p>Clear and well paced.</p>',
        ])
        ->assertCreated()
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.is_published', true)
        ->assertJsonPath('data.author.is_you', true);

    // Not queued: a learner who still saw the old average would assume the
    // write failed.
    expect($this->course->fresh()->rating_count)->toBe(1);
});

it('replaces rather than appends when the same learner reviews twice', function (): void {
    foreach ([5, 2] as $rating) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/courses/{$this->course->uuid}/reviews", ['rating' => $rating])
            ->assertCreated();
    }

    expect(Review::count())->toBe(1)
        ->and((float) $this->course->fresh()->rating_avg)->toBe(2.0);
});

it('refuses a rating outside one to five', function (): void {
    foreach ([0, 6, -1] as $rating) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/courses/{$this->course->uuid}/reviews", ['rating' => $rating])
            ->assertStatus(422);
    }
});

it('409s a review from somebody who never enrolled', function (): void {
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    expect($this->actingAs($stranger)
        ->postJson("/api/v1/courses/{$this->course->uuid}/reviews", ['rating' => 5])
        ->assertStatus(409))->toBeApiError('review_rejected');
});

/* ------------------------------------------------------------- what is shown */

it('shows a learner their own pending review, and hides other peoples', function (): void {
    /*
     * A learner whose review is in moderation must see that it has not
     * appeared. Silently vanishing is worse than being told it is pending.
     */
    $this->course->setting->update(['moderate_reviews' => true]);

    $other = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $other->id]);
    app(SubmitReview::class)->handle($other, $this->course->fresh(), 1);

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/reviews", ['rating' => 5])
        ->assertCreated()
        ->assertJsonPath('data.is_published', false);

    $response = $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk();

    // Own pending review visible; the other learner's is not.
    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.author.is_you'))->toBeTrue();
});

it('shows a moderator every review, including pending ones', function (): void {
    $this->course->setting->update(['moderate_reviews' => true]);
    app(SubmitReview::class)->handle($this->student, $this->course->fresh(), 3);

    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('never exposes a reviewers email', function (): void {
    // Being honest about a course must not make somebody contactable.
    app(SubmitReview::class)->handle($this->student, $this->course, 5);

    $body = $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain($this->student->email)
        ->toContain($this->student->name);
});

/* ------------------------------------------------------------- authorization */

it('forbids a learner from moderating', function (): void {
    $review = app(SubmitReview::class)->handle($this->student, $this->course, 5);

    $this->actingAs($this->student)
        ->postJson("/api/v1/admin/reviews/{$review->uuid}/moderate", ['status' => 'rejected'])
        ->assertForbidden();

    $this->actingAs($this->student)->getJson('/api/v1/admin/reviews')->assertForbidden();
});

it('forbids a learner from replying as the instructor', function (): void {
    $review = app(SubmitReview::class)->handle($this->student, $this->course, 5);

    $this->actingAs($this->student)
        ->postJson("/api/v1/reviews/{$review->uuid}/reply", ['reply' => 'Thanks!'])
        ->assertForbidden();
});

it('lets the owning instructor reply, but not an unrelated one', function (): void {
    /*
     * Every instructor holds review.reply.own GLOBALLY, so a union check here
     * would let any instructor answer reviews on any course in the academy —
     * the trap CourseScopedAccessTest exists for.
     */
    $review = app(SubmitReview::class)->handle($this->student, $this->course, 5);
    $stranger = User::factory()->instructor()->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/reviews/{$review->uuid}/reply", ['reply' => 'Hello.'])
        ->assertForbidden();

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/reviews/{$review->uuid}/reply", ['reply' => '<p>Thank you.</p>'])
        ->assertOk()
        ->assertJsonPath('data.instructor_reply', '<p>Thank you.</p>');
});

it('lets a learner delete their own review and drops the rating with it', function (): void {
    $review = app(SubmitReview::class)->handle($this->student, $this->course, 5);

    expect($this->course->fresh()->rating_count)->toBe(1);

    $this->actingAs($this->student)
        ->deleteJson("/api/v1/reviews/{$review->uuid}")
        ->assertNoContent();

    expect($this->course->fresh()->rating_count)->toBe(0);
});

it('forbids deleting somebody elses review', function (): void {
    $review = app(SubmitReview::class)->handle($this->student, $this->course, 5);
    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($other)
        ->deleteJson("/api/v1/reviews/{$review->uuid}")
        ->assertForbidden();
});

it('publishes from the moderation queue and the rating follows', function (): void {
    $this->course->setting->update(['moderate_reviews' => true]);
    $review = app(SubmitReview::class)->handle($this->student, $this->course->fresh(), 4);

    expect($this->course->fresh()->rating_count)->toBe(0);

    $admin = User::factory()->withRole(RoleKey::Admin)->create();

    $this->actingAs($admin)->getJson('/api/v1/admin/reviews')
        ->assertOk()->assertJsonCount(1, 'data');

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/reviews/{$review->uuid}/moderate", ['status' => 'published'])
        ->assertOk()
        ->assertJsonPath('data.is_published', true);

    expect($this->course->fresh()->rating_count)->toBe(1);
});

it('requires authentication', function (): void {
    $this->getJson("/api/v1/courses/{$this->course->uuid}/reviews")->assertUnauthorized();
    $this->postJson("/api/v1/courses/{$this->course->uuid}/reviews", ['rating' => 5])
        ->assertUnauthorized();
});

it('says whether the reader may write one, by the same two rules the write enforces', function (): void {
    /*
     * Rendered AND enforced. A page that offers a form the server will refuse
     * is the dead end this codebase keeps declining to ship.
     */
    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk()
        ->assertJsonPath('meta.can_review', true);

    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk()
        ->assertJsonPath('meta.can_review', false);
});

it('says no when the course has reviews switched off, enrolled or not', function (): void {
    $this->course->setting->update(['enable_reviews' => false]);

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/reviews")
        ->assertOk()
        ->assertJsonPath('meta.can_review', false);
});
