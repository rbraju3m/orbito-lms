<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\CompleteCourse;
use App\Domain\Progress\Actions\TrackItemProgress;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('enrols a student in a free course', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertCreated()
        ->assertJsonPath('data.status', 'active');

    expect(Enrollment::count())->toBe(1)
        ->and($this->course->fresh()->enrollment_count)->toBe(1);
});

it('creates the progress row with the right denominator', function (): void {
    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertCreated();

    expect(Enrollment::first()->progress->total_items)->toBe(2);
});

it('refuses a second enrolment', function (): void {
    $this->actingAs($this->student)->postJson("/api/v1/courses/{$this->course->uuid}/enroll");

    expect($this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertStatus(409))->toBeApiError('enrollment_rejected');
});

it('refuses enrolment in an unpublished course', function (): void {
    $draft = courseWithCurriculum(Course::factory()->create(), [1]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$draft->uuid}/enroll")
        ->assertStatus(409);
});

/* ADR-05: a paid course is only ever entered through a verified payment. */
it('refuses free enrolment in a paid course', function (): void {
    $this->course->update(['pricing_model' => PricingModel::OneTime]);

    $response = $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertStatus(409);

    expect($response->json('error.message'))->toContain('purchased');
    expect(Enrollment::count())->toBe(0);
});

it('honours a seat limit', function (): void {
    $this->course->setting->update(['max_students' => 1]);

    $first = User::factory()->withRole(RoleKey::Student)->create();
    $this->actingAs($first)->postJson("/api/v1/courses/{$this->course->uuid}/enroll")->assertCreated();

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertStatus(409);
});

it('applies the course expiry window', function (): void {
    $this->course->setting->update(['enrollment_expires_days' => 30]);

    $this->actingAs($this->student)
        ->postJson("/api/v1/courses/{$this->course->uuid}/enroll")
        ->assertCreated();

    expect(Enrollment::first()->expires_at)->not->toBeNull();
});

it('requires authentication', function (): void {
    $this->postJson("/api/v1/courses/{$this->course->uuid}/enroll")->assertStatus(401);
});

describe('continue learning', function (): void {
    it('is empty for a learner who has not started', function (): void {
        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        expect($this->actingAs($this->student)->getJson('/api/v1/learn/continue')->assertOk()->json('data'))
            ->toBe([]);
    });

    it('returns the most recent course with a resume point', function (): void {
        $enrollment = Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
        $item = $this->course->items()->first();

        $this->actingAs($this->student)->getJson("/api/v1/learn/items/{$item->uuid}")->assertOk();

        $response = $this->actingAs($this->student)->getJson('/api/v1/learn/continue')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.course.title'))->toBe($this->course->title)
            ->and($response->json('data.0.resume_item_id'))->toBe($item->uuid);
    });

    it('drops a course once it is finished', function (): void {
        $enrollment = Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        foreach ($this->course->items as $item) {
            app(TrackItemProgress::class)->complete($enrollment, $item);
        }
        app(CompleteCourse::class)->handle($enrollment->fresh());

        expect($this->actingAs($this->student)->getJson('/api/v1/learn/continue')->assertOk()->json('data'))
            ->toBe([]);
    });

    /*
     * ADR-02's payoff and the direct answer to the audit's worst finding: the
     * reference product needs O(courses x items) queries for this screen.
     */
    it('answers in a constant number of queries regardless of course count', function (): void {
        foreach (range(1, 6) as $_) {
            $course = courseWithCurriculum(Course::factory()->published()->create(), [3, 3]);
            $enrollment = Enrollment::factory()->create([
                'course_id' => $course->id,
                'user_id' => $this->student->id,
            ]);
            app(TrackItemProgress::class)
                ->view($enrollment, $course->items()->first());
        }

        DB::enableQueryLog();
        $this->actingAs($this->student)->getJson('/api/v1/learn/continue')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBeLessThanOrEqual(8);
    });
});

describe('my courses', function (): void {
    it('lists enrolled courses with their progress', function (): void {
        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        $response = $this->actingAs($this->student)->getJson('/api/v1/learn/courses')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.course.title'))->toBe($this->course->title)
            ->and($response->json('data.0.progress.total_items'))->toBe(2);
    });

    it('filters to completed courses', function (): void {
        $enrollment = Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        expect($this->actingAs($this->student)
            ->getJson('/api/v1/learn/courses?filter=completed')->assertOk()->json('data'))->toBe([]);

        foreach ($this->course->items as $item) {
            app(TrackItemProgress::class)->complete($enrollment, $item);
        }
        app(CompleteCourse::class)->handle($enrollment->fresh());

        expect($this->actingAs($this->student)
            ->getJson('/api/v1/learn/courses?filter=completed')->assertOk()->json('data'))->toHaveCount(1);
    });
});
