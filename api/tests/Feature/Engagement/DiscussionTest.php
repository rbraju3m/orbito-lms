<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\AcceptDiscussionAnswer;
use App\Domain\Engagement\Actions\PostDiscussion;
use App\Domain\Engagement\Actions\ReplyToDiscussion;
use App\Domain\Engagement\Enums\DiscussionStatus;
use App\Domain\Engagement\Enums\DiscussionType;
use App\Domain\Engagement\Exceptions\DiscussionRejected;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();

    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(),
        [2],
    );
    $this->course->setting->update(['enable_qa' => true]);
    $this->item = $this->course->items()->first();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
});

function ask(User $user, Course $course, string $title = 'A question'): Discussion
{
    return app(PostDiscussion::class)->handle($user, $course, $title, '<p>Body</p>');
}

/* ------------------------------------------------ the maintained counters */

it('maintains reply_count and last_reply_at as replies arrive', function (): void {
    $discussion = ask($this->student, $this->course);

    expect($discussion->reply_count)->toBe(0)
        ->and($discussion->status)->toBe(DiscussionStatus::Open);

    app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'One.');
    app(ReplyToDiscussion::class)->handle($this->student, $discussion->fresh(), 'Two.');

    expect($discussion->fresh())
        ->reply_count->toBe(2)
        ->and($discussion->fresh()->last_reply_at)->not->toBeNull()
        // `answered` follows the reply count; nobody set it by hand.
        ->and($discussion->fresh()->status)->toBe(DiscussionStatus::Answered);
});

it('lists threads without counting replies per row', function (): void {
    // The whole reason reply_count is a column (CLAUDE.md §10).
    $discussion = ask($this->student, $this->course);
    app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Hello.');

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/discussions")
        ->assertOk()
        ->assertJsonPath('data.0.reply_count', 1);

    $counts = array_filter(
        $queries,
        fn (string $sql) => str_contains($sql, 'discussion_replies') && str_contains($sql, 'count('),
    );

    expect($counts)->toBeEmpty();
});

it('rolls the counters back when a reply is deleted', function (): void {
    $discussion = ask($this->student, $this->course);
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Hi.');

    expect($discussion->fresh()->reply_count)->toBe(1);

    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/discussion-replies/{$reply->uuid}")
        ->assertNoContent();

    expect($discussion->fresh())
        ->reply_count->toBe(0)
        // Back to `open`, because the replies genuinely went away.
        ->status->toBe(DiscussionStatus::Open);
});

/* ------------------------------------------------------- accepting an answer */

it('resolves a thread when the asker accepts an answer', function (): void {
    $discussion = ask($this->student, $this->course);
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Because.');

    app(AcceptDiscussionAnswer::class)->handle($discussion->fresh(), $reply);

    expect($discussion->fresh())
        ->status->toBe(DiscussionStatus::Resolved)
        ->accepted_reply_id->toBe($reply->id);
});

it('keeps a resolved thread resolved when somebody adds a footnote', function (): void {
    /*
     * `followsReplies()` is the single place this distinction lives — without
     * it the recounter would quietly demote every resolved thread the moment
     * anybody replied.
     */
    $discussion = ask($this->student, $this->course);
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Because.');
    app(AcceptDiscussionAnswer::class)->handle($discussion->fresh(), $reply);

    app(ReplyToDiscussion::class)->handle($this->student, $discussion->fresh(), 'Thanks!');

    expect($discussion->fresh())
        ->status->toBe(DiscussionStatus::Resolved)
        ->reply_count->toBe(2);
});

it('lets somebody take back an acceptance without losing the replies', function (): void {
    $discussion = ask($this->student, $this->course);
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Because.');
    app(AcceptDiscussionAnswer::class)->handle($discussion->fresh(), $reply);

    app(AcceptDiscussionAnswer::class)->handle($discussion->fresh(), null);

    expect($discussion->fresh())
        ->accepted_reply_id->toBeNull()
        // `answered`, not `open` — the replies did not disappear.
        ->status->toBe(DiscussionStatus::Answered);
});

it('refuses to accept an answer on a comment', function (): void {
    $comment = app(PostDiscussion::class)->handle(
        $this->student, $this->course, 'Just saying', '<p>Nice.</p>', DiscussionType::Comment,
    );
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $comment, 'Agreed.');

    expect(fn () => app(AcceptDiscussionAnswer::class)->handle($comment->fresh(), $reply))
        ->toThrow(DiscussionRejected::class);
});

it('refuses to accept a reply from another thread', function (): void {
    // Otherwise any reply id in the academy could answer any question.
    $one = ask($this->student, $this->course, 'First');
    $two = ask($this->student, $this->course, 'Second');
    $strayReply = app(ReplyToDiscussion::class)->handle($this->instructor, $two, 'Elsewhere.');

    expect(fn () => app(AcceptDiscussionAnswer::class)->handle($one->fresh(), $strayReply))
        ->toThrow(DiscussionRejected::class);
});

/* ------------------------------------------------------------- the threading */

it('flattens a reply to a reply rather than nesting a third level', function (): void {
    $discussion = ask($this->student, $this->course);
    $top = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Top.');
    $second = app(ReplyToDiscussion::class)->handle($this->student, $discussion->fresh(), 'Second.', $top);
    $third = app(ReplyToDiscussion::class)->handle($this->student, $discussion->fresh(), 'Third.', $second);

    expect($second->parent_id)->toBe($top->id)
        // Re-parented to the top-level reply, not to $second.
        ->and($third->parent_id)->toBe($top->id);
});

it('marks an instructors reply as one, decided by the server', function (): void {
    $discussion = ask($this->student, $this->course);

    // A learner cannot claim the badge, however they shape the request.
    $this->actingAs($this->student)
        ->postJson("/api/v1/discussions/{$discussion->uuid}/replies", [
            'body' => 'I think so.',
            'is_instructor_reply' => true,
        ])
        ->assertCreated();

    expect(DiscussionReply::first()->is_instructor_reply)->toBeFalse();

    $this->actingAs($this->instructor)
        ->postJson("/api/v1/discussions/{$discussion->uuid}/replies", ['body' => 'Correct.'])
        ->assertCreated();

    expect(DiscussionReply::where('user_id', $this->instructor->id)->first()->is_instructor_reply)
        ->toBeTrue();
});

/* ------------------------------------------------------------- authorization */

it('refuses a question from somebody with no access to the course', function (): void {
    /*
     * Unlike a review: a question is participation in a course you are
     * CURRENTLY taking, so this resolves through CourseAccess (ADR-03).
     */
    $stranger = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($stranger)
        ->postJson("/api/v1/courses/{$this->course->uuid}/discussions", [
            'title' => 'Can I ask?', 'body' => 'Hello',
        ])
        ->assertForbidden();
});

it('refuses a question when the course has Q&A turned off', function (): void {
    $this->course->setting->update(['enable_qa' => false]);

    expect(fn () => ask($this->student, $this->course->fresh()))
        ->toThrow(DiscussionRejected::class);
});

it('hides a hidden thread from everyone but a moderator, its author included', function (): void {
    $discussion = ask($this->student, $this->course);

    $admin = User::factory()->withRole(RoleKey::Admin)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $admin->id]);

    $this->actingAs($admin)
        ->patchJson("/api/v1/discussions/{$discussion->uuid}/moderate", ['status' => 'hidden'])
        ->assertOk();

    // Its author does not get to read it either — that is what hiding means.
    $this->actingAs($this->student)
        ->getJson("/api/v1/discussions/{$discussion->uuid}")
        ->assertForbidden();

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/discussions")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('refuses a reply to a hidden thread', function (): void {
    $discussion = Discussion::factory()->hidden()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    expect(fn () => app(ReplyToDiscussion::class)->handle($this->student, $discussion, 'Hello'))
        ->toThrow(DiscussionRejected::class);
});

it('forbids a learner from moderating', function (): void {
    $discussion = ask($this->student, $this->course);

    $this->actingAs($this->student)
        ->patchJson("/api/v1/discussions/{$discussion->uuid}/moderate", ['is_pinned' => true])
        ->assertForbidden();
});

it('lets the asker accept, and forbids an unrelated learner', function (): void {
    $discussion = ask($this->student, $this->course);
    $reply = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion, 'Because.');

    $other = User::factory()->withRole(RoleKey::Student)->create();
    Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $other->id]);

    $this->actingAs($other)
        ->postJson("/api/v1/discussions/{$discussion->uuid}/accept", ['reply_id' => $reply->uuid])
        ->assertForbidden();

    $this->actingAs($this->student)
        ->postJson("/api/v1/discussions/{$discussion->uuid}/accept", ['reply_id' => $reply->uuid])
        ->assertOk()
        ->assertJsonPath('data.is_resolved', true);
});

it('lets a learner delete their own reply but not somebody elses', function (): void {
    $discussion = ask($this->student, $this->course);
    $mine = app(ReplyToDiscussion::class)->handle($this->student, $discussion, 'Mine.');
    $theirs = app(ReplyToDiscussion::class)->handle($this->instructor, $discussion->fresh(), 'Theirs.');

    $this->actingAs($this->student)
        ->deleteJson("/api/v1/discussion-replies/{$theirs->uuid}")
        ->assertForbidden();

    $this->actingAs($this->student)
        ->deleteJson("/api/v1/discussion-replies/{$mine->uuid}")
        ->assertNoContent();
});

/* --------------------------------------------------------- item attachment */

it('attaches a question to one lesson and filters by it', function (): void {
    app(PostDiscussion::class)->handle(
        $this->student, $this->course, 'About 4:12', '<p>?</p>',
        DiscussionType::Question, $this->item,
    );
    ask($this->student, $this->course, 'General');

    $this->actingAs($this->student)
        ->getJson("/api/v1/courses/{$this->course->uuid}/discussions?item_id={$this->item->uuid}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'About 4:12');
});

it('refuses a lesson from another course', function (): void {
    // The binding resolves globally, so membership of THIS course is checked
    // explicitly — the Phase 7 trap.
    $elsewhere = courseWithCurriculum(Course::factory()->published()->create(), [1]);

    expect(fn () => app(PostDiscussion::class)->handle(
        $this->student, $this->course, 'Wrong course', '<p>?</p>',
        DiscussionType::Question, $elsewhere->items()->first(),
    ))->toThrow(DiscussionRejected::class);
});

it('sanitises bodies on write', function (): void {
    $discussion = app(PostDiscussion::class)->handle(
        $this->student, $this->course, 'Hmm', '<p>Hi <script>alert(1)</script></p>',
    );

    expect($discussion->body)->not->toContain('<script>')->toContain('Hi');
});
