<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Actions\AcceptDiscussionAnswer;
use App\Domain\Engagement\Actions\PostDiscussion;
use App\Domain\Engagement\Actions\ReplyToDiscussion;
use App\Domain\Engagement\Actions\SubmitReview;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Gamification\Actions\EvaluateTrigger;
use App\Domain\Gamification\Data\TriggerContext;
use App\Domain\Gamification\Enums\TriggerEvent;
use App\Domain\Gamification\Models\Badge;
use App\Domain\Gamification\Models\GamificationProfile;
use App\Domain\Gamification\Models\GamificationRule;
use App\Domain\Gamification\Models\PointTransaction;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Events\ItemCompleted;

/*
 * The rule engine. Its whole job is paying out once and only once — a scheme
 * somebody can farm is worse than no scheme, because it makes the number a
 * measure of who worked out the trick.
 */

beforeEach(function (): void {
    seedRegistry();

    /*
     * A newly provisioned academy now SHIPS with the default rules and badges
     * (TenantDatabaseSeeder), so an engine test that wants to control its own
     * rules has to start from an empty table. Without this, the seeded
     * `lesson.completed` rule pays out alongside whatever the test created —
     * which is the correct product behaviour and the wrong test fixture.
     */
    GamificationRule::query()->delete();
    Badge::query()->delete();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->item = $this->course->items()->first();

    $this->evaluate = fn (TriggerContext $context) => app(EvaluateTrigger::class)->handle($context);

    $this->lessonContext = fn () => TriggerContext::for(
        TriggerEvent::ItemCompleted,
        $this->student->id,
        $this->item,
        ['item_type' => 'lesson'],
        $this->course->id,
    );
});

it('pays out and moves the balance', function (): void {
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);

    ($this->evaluate)(($this->lessonContext)());

    $transaction = PointTransaction::query()->sole();

    expect($transaction->points)->toBe(10)
        ->and($transaction->balance_after)->toBe(10)
        ->and(GamificationProfile::query()->find($this->student->id)->points_total)->toBe(10);
});

it('refuses to pay twice for the same lesson', function (): void {
    /*
     * THE anti-farming property. Un-ticking and re-ticking a lesson fires
     * ItemCompleted again; the dedupe key makes the second row impossible at
     * the database rather than at a check somebody can race.
     */
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);

    ($this->evaluate)(($this->lessonContext)());
    ($this->evaluate)(($this->lessonContext)());
    ($this->evaluate)(($this->lessonContext)());

    expect(PointTransaction::query()->count())->toBe(1)
        ->and(GamificationProfile::query()->find($this->student->id)->points_total)->toBe(10);
});

it('pays two different rules for one lesson', function (): void {
    // The dedupe key names the RULE as well as the source, so two rules can
    // both reward one thing while neither rewards it twice.
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);
    GamificationRule::factory()->worth(5)->create(['key' => 'lesson.bonus']);

    ($this->evaluate)(($this->lessonContext)());
    ($this->evaluate)(($this->lessonContext)());

    expect(PointTransaction::query()->count())->toBe(2)
        ->and(GamificationProfile::query()->find($this->student->id)->points_total)->toBe(15);
});

it('ignores a rule that is switched off', function (): void {
    GamificationRule::factory()->inactive()->worth(10)->create();

    ($this->evaluate)(($this->lessonContext)());

    expect(PointTransaction::query()->count())->toBe(0);
});

it('checks a condition against the trigger, not against the model', function (): void {
    GamificationRule::factory()
        ->watching(TriggerEvent::QuizPassed)
        ->worth(25)
        ->create(['key' => 'quiz.passed', 'conditions' => ['min_percent' => 60]]);

    $scrape = TriggerContext::for(
        TriggerEvent::QuizPassed,
        $this->student->id,
        $this->item,
        ['percent' => 55.0],
    );

    ($this->evaluate)($scrape);
    expect(PointTransaction::query()->count())->toBe(0);

    $real = TriggerContext::for(
        TriggerEvent::QuizPassed,
        $this->student->id,
        $this->course,
        ['percent' => 80.0],
    );

    ($this->evaluate)($real);
    expect(PointTransaction::query()->count())->toBe(1);
});

it('refuses a condition it has never heard of', function (): void {
    // A typo in an academy's rule must not silently pay everybody.
    GamificationRule::factory()->worth(10)->create(['conditions' => ['min_vibes' => 3]]);

    ($this->evaluate)(($this->lessonContext)());

    expect(PointTransaction::query()->count())->toBe(0);
});

it('stops at the daily cap', function (): void {
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed', 'max_per_day' => 2]);

    foreach ($this->course->items()->get() as $index => $item) {
        ($this->evaluate)(TriggerContext::for(
            TriggerEvent::ItemCompleted,
            $this->student->id,
            $item,
            [],
            $this->course->id,
        ));
    }

    // Two items exist, so add a third source to push past the cap.
    ($this->evaluate)(TriggerContext::for(
        TriggerEvent::ItemCompleted,
        $this->student->id,
        $this->course,
        [],
        $this->course->id,
    ));

    expect(PointTransaction::query()->count())->toBe(2);
});

it('holds a repeatable rule to its cooldown', function (): void {
    /*
     * Re-grading is real: an assignment handed back and marked again happens.
     * A grader fixing a typo an hour later is not a second achievement.
     */
    GamificationRule::factory()
        ->watching(TriggerEvent::AssignmentGraded)
        ->worth(40)
        ->create(['key' => 'assignment.passed', 'cooldown_seconds' => 3600]);

    $context = fn () => TriggerContext::for(
        TriggerEvent::AssignmentGraded,
        $this->student->id,
        $this->item,
        ['passed' => true],
    );

    ($this->evaluate)($context());
    ($this->evaluate)($context());

    expect(PointTransaction::query()->count())->toBe(1);

    $this->travel(2)->hours();

    ($this->evaluate)($context());

    expect(PointTransaction::query()->count())->toBe(2);
});

it('runs off a real domain event, through the listener', function (): void {
    GamificationRule::factory()->worth(10)->create(['key' => 'lesson.completed']);

    $enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    ItemCompleted::dispatch($enrollment, $this->item);

    // Progress knows nothing about points; the event map connects them.
    expect(PointTransaction::query()->count())->toBe(1);
});

it('rewards a review and an accepted answer, not just progress', function (): void {
    /*
     * The half of gamification that is not a second progress bar. It is also
     * the regression this file exists for: both trigger sources are models
     * `analytics_events` never touched, so neither was in the enforced morph
     * map — and `TriggerContext::for()` calls `getMorphClass()`, which turned
     * every review in the product into a 500.
     */
    $this->course->setting->update(['enable_reviews' => true, 'moderate_reviews' => false, 'enable_qa' => true]);

    Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    GamificationRule::factory()
        ->watching(TriggerEvent::ReviewPublished)
        ->worth(15)
        ->create(['key' => 'review.published']);

    app(SubmitReview::class)
        ->handle($this->student, $this->course, 5, 'Good', '<p>Clear.</p>');

    expect(PointTransaction::query()->count())->toBe(1);

    // Editing it must not pay a second time for one opinion.
    app(SubmitReview::class)
        ->handle($this->student, $this->course, 4, 'Still good', '<p>Clear.</p>');

    expect(PointTransaction::query()->count())->toBe(1);
});

it('pays the person who WROTE the accepted answer, not the asker', function (): void {
    // Otherwise the cheapest way to earn is to ask yourself a question.
    $this->course->setting->update(['enable_qa' => true]);

    $helper = User::factory()->withRole(RoleKey::Student)->create();

    foreach ([$this->student, $helper] as $user) {
        Enrollment::factory()->create(['course_id' => $this->course->id, 'user_id' => $user->id]);
    }

    GamificationRule::factory()
        ->watching(TriggerEvent::DiscussionAnswerAccepted)
        ->worth(30)
        ->create(['key' => 'answer.accepted']);

    $discussion = app(PostDiscussion::class)
        ->handle($this->student, $this->course->fresh(), 'Why?', '<p>Stuck.</p>');

    $reply = app(ReplyToDiscussion::class)
        ->handle($helper, $discussion, '<p>Because of the metre.</p>');

    app(AcceptDiscussionAnswer::class)->handle($discussion, $reply);

    expect(PointTransaction::query()->where('user_id', $helper->id)->count())->toBe(1)
        ->and(PointTransaction::query()->where('user_id', $this->student->id)->count())->toBe(0);
});
