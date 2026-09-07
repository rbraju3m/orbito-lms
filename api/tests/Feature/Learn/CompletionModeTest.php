<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CompletionMode;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\ResetCourseProgress;
use App\Domain\Progress\Actions\TrackItemProgress;
use App\Domain\Progress\Enums\ItemProgressStatus;
use App\Domain\Progress\Models\ItemProgress;

beforeEach(fn () => seedRegistry());

/*
 * `completion_mode` governs whether a learner may finish EARLY — not whether
 * 100% counts. Both modes complete themselves when everything is done; only
 * flexible offers the button.
 */

describe('finishing everything', function (): void {
    it('completes a strict course', function (): void {
        $course = courseWithCurriculum(
            Course::factory()->published()
                ->create(['completion_mode' => CompletionMode::Strict]),
            [2],
        );
        $enrollment = Enrollment::factory()->create(['course_id' => $course->id]);

        foreach ($course->items as $item) {
            app(TrackItemProgress::class)->complete($enrollment, $item);
        }

        expect(Enrollment::find($enrollment->id)->status)->toBe(EnrollmentStatus::Completed);
    });

    it('completes a flexible course too', function (): void {
        $course = courseWithCurriculum(
            Course::factory()->published()
                ->create(['completion_mode' => CompletionMode::Flexible]),
            [2],
        );
        $enrollment = Enrollment::factory()->create(['course_id' => $course->id]);

        foreach ($course->items as $item) {
            app(TrackItemProgress::class)->complete($enrollment, $item);
        }

        expect(Enrollment::find($enrollment->id)->status)->toBe(EnrollmentStatus::Completed);
    });
});

describe('finishing early', function (): void {
    it('is allowed in a flexible course', function (): void {
        $course = courseWithCurriculum(
            Course::factory()->published()
                ->create(['completion_mode' => CompletionMode::Flexible]),
            [2],
        );
        $student = User::factory()->withRole(RoleKey::Student)->create();
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $student->id]);

        $this->actingAs($student)
            ->postJson("/api/v1/learn/courses/{$course->uuid}/complete")
            ->assertOk();
    });

    it('is refused in a strict course', function (): void {
        $course = courseWithCurriculum(
            Course::factory()->published()
                ->create(['completion_mode' => CompletionMode::Strict]),
            [2],
        );
        $student = User::factory()->withRole(RoleKey::Student)->create();
        Enrollment::factory()->create(['course_id' => $course->id, 'user_id' => $student->id]);

        expect($this->actingAs($student)
            ->postJson("/api/v1/learn/courses/{$course->uuid}/complete")
            ->assertStatus(422))->toBeApiError('course_not_complete');
    });
});

describe('reset and retake', function (): void {
    beforeEach(function (): void {
        $this->course = courseWithCurriculum(
            Course::factory()->published()->create(),
            [2],
        );
        $this->student = User::factory()->withRole(RoleKey::Student)->create();
        $this->enrollment = Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
    });

    it('clears declared progress and reopens the enrolment', function (): void {
        foreach ($this->course->items as $item) {
            app(TrackItemProgress::class)->complete($this->enrollment, $item);
        }

        $progress = app(ResetCourseProgress::class)->handle($this->enrollment->fresh());

        expect($progress->completed_items)->toBe(0)
            ->and((float) $progress->percent)->toBe(0.0)
            ->and($progress->completed_at)->toBeNull()
            ->and(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Active);
    });

    it('gates a retake on retake_allowed once the course is finished', function (): void {
        $this->course->setting->update(['retake_allowed' => false, 'reset_progress_allowed' => true]);

        foreach ($this->course->items as $item) {
            app(TrackItemProgress::class)->complete($this->enrollment, $item);
        }

        expect($this->actingAs($this->student)
            ->postJson("/api/v1/learn/courses/{$this->course->uuid}/reset-progress")
            ->assertStatus(409))->toBeApiError('progress_rejected');
    });

    /*
     * The two settings are independent: a course may forbid restarting midway
     * and still allow a full retake after completion.
     */
    it('allows a retake even when a midway reset is forbidden', function (): void {
        $this->course->setting->update(['retake_allowed' => true, 'reset_progress_allowed' => false]);

        foreach ($this->course->items as $item) {
            app(TrackItemProgress::class)->complete($this->enrollment, $item);
        }

        $this->actingAs($this->student)
            ->postJson("/api/v1/learn/courses/{$this->course->uuid}/reset-progress")
            ->assertOk();
    });

    /*
     * A passed quiz is an earned fact, not something the learner declared.
     * Wiping it would show the item incomplete while a passing attempt sits in
     * the database — and if the quiz's attempts are used up, the course could
     * never be completed again.
     */
    it('keeps an earned quiz completion through a reset', function (): void {
        $scenario = quizScenario(['attempts_allowed' => 1, 'passing_score_percent' => 50]);
        $enrollment = $scenario['enrollment'];
        $quizItem = $scenario['item'];

        app(TrackItemProgress::class)->complete($enrollment, $quizItem);

        app(ResetCourseProgress::class)->handle($enrollment->fresh());

        expect(ItemProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('course_item_id', $quizItem->id)
            ->value('status'))->toBe(ItemProgressStatus::Completed);
    });

    it('clears a lesson but keeps the quiz in the same course', function (): void {
        $scenario = quizScenario();
        $course = $scenario['course'];
        $enrollment = $scenario['enrollment'];
        $quizItem = $scenario['item'];

        $quizItem->update(['position' => 9]);
        $lesson = courseWithCurriculum($course, [1])->items()
            ->where('id', '!=', $quizItem->id)->first();

        app(TrackItemProgress::class)->complete($enrollment, $lesson);
        app(TrackItemProgress::class)->complete($enrollment, $quizItem);

        $progress = app(ResetCourseProgress::class)->handle($enrollment->fresh());

        expect(ItemProgress::where('enrollment_id', $enrollment->id)
            ->where('course_item_id', $lesson->id)->exists())->toBeFalse()
            ->and(ItemProgress::where('enrollment_id', $enrollment->id)
                ->where('course_item_id', $quizItem->id)->exists())->toBeTrue()
            // The percentage reflects what actually survived, not zero.
            ->and($progress->completed_items)->toBe(1);
    });
});
