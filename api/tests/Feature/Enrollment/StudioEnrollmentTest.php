<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\SetCoursePrerequisites;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();

    $this->owner = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->owner)->published()->create(),
        [2],
    );
    $this->student = User::factory()->withRole(RoleKey::Student)
        ->create(['email' => 'ada@example.test', 'name' => 'Ada Lovelace']);
});

describe('the roster', function (): void {
    it('lists students with their progress', function (): void {
        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.student.name'))->toBe('Ada Lovelace')
            ->and($response->json('data.0.enrollment.status'))->toBe('active')
            ->and($response->json('data.0.progress.total_items'))->toBe(2);
    });

    it('is empty for a course with no students', function (): void {
        expect($this->actingAs($this->owner)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students")
            ->assertOk()->json('data'))->toBe([]);
    });

    it('filters by status', function (): void {
        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
        Enrollment::factory()->suspended()->create(['course_id' => $this->course->id]);

        expect($this->actingAs($this->owner)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students?status=suspended")
            ->assertOk()->json('data'))->toHaveCount(1);
    });

    it('searches by name and email', function (): void {
        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
        Enrollment::factory()->create(['course_id' => $this->course->id]);

        expect($this->actingAs($this->owner)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students?search=ada@example")
            ->assertOk()->json('data'))->toHaveCount(1);
    });

    it('paginates', function (): void {
        Enrollment::factory()->count(5)->create(['course_id' => $this->course->id]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students?per_page=2")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('meta.total'))->toBe(5);
    });

    it('denies an instructor with no claim on the course', function (): void {
        $stranger = User::factory()->instructor()->create();

        $this->actingAs($stranger)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students")
            ->assertForbidden();
    });

    it('denies a student', function (): void {
        $this->actingAs($this->student)
            ->getJson("/api/v1/studio/courses/{$this->course->uuid}/students")
            ->assertForbidden();
    });

    it('requires authentication', function (): void {
        $this->getJson("/api/v1/studio/courses/{$this->course->uuid}/students")
            ->assertStatus(401);
    });
});

describe('granting one seat', function (): void {
    it('enrols by email and records who granted it', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
            ])
            ->assertCreated()
            ->assertJsonPath('data.student.email', 'ada@example.test');

        $enrollment = Enrollment::first();

        expect($enrollment->source)->toBe(EnrollmentSource::Manual)
            ->and($enrollment->source_id)->toBe($this->owner->id);
    });

    it('enrols by uuid', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'user_id' => $this->student->uuid,
            ])
            ->assertCreated();
    });

    /* Granting is granting: staff are not blocked by price or prerequisites. */
    it('bypasses the price on a paid course', function (): void {
        $this->course->update(['pricing_model' => PricingModel::OneTime]);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
            ])
            ->assertCreated();
    });

    it('bypasses an unmet prerequisite', function (): void {
        $intro = Course::factory()->published()->create();
        app(SetCoursePrerequisites::class)
            ->handle($this->course, [$intro->id]);

        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
            ])
            ->assertCreated();
    });

    it('accepts a start and an expiry date', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
                'starts_at' => now()->addWeek()->toIso8601String(),
                'expires_at' => now()->addYear()->toIso8601String(),
            ])
            ->assertCreated();

        expect(Enrollment::first()->starts_at)->not->toBeNull()
            ->and(Enrollment::first()->expires_at)->not->toBeNull();
    });

    it('rejects an unknown student', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'nobody@example.test',
            ])
            ->assertStatus(422);
    });

    it('rejects a request naming neither a user nor an email', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [])
            ->assertStatus(422);
    });

    it('rejects an expiry in the past', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
                'expires_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    });

    it('denies an instructor with no claim on the course', function (): void {
        $stranger = User::factory()->instructor()->create();

        $this->actingAs($stranger)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments", [
                'email' => 'ada@example.test',
            ])
            ->assertForbidden();
    });
});

describe('granting many', function (): void {
    it('reports a verdict for every row', function (): void {
        $second = User::factory()->withRole(RoleKey::Student)
            ->create(['email' => 'grace@example.test']);

        Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $second->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => ['ada@example.test', 'grace@example.test', 'ghost@example.test'],
            ])
            ->assertOk();

        expect($response->json('data.summary'))
            ->toBe(['enrolled' => 1, 'skipped' => 1, 'not_found' => 1]);
    });

    /* One typo must not discard the seats that were fine. */
    it('enrols the good rows even when one fails', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => ['ghost@example.test', 'ada@example.test'],
            ])
            ->assertOk();

        expect(Enrollment::where('user_id', $this->student->id)->exists())->toBeTrue();
    });

    it('is case-insensitive and de-duplicates', function (): void {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => ['ADA@example.test', 'ada@example.test'],
            ])
            ->assertOk();

        expect($response->json('data.results'))->toHaveCount(1)
            ->and(Enrollment::count())->toBe(1);
    });

    it('refuses more rows than it will process in a request', function (): void {
        $emails = array_map(fn (int $i): string => "user{$i}@example.test", range(1, 201));

        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => $emails,
            ])
            ->assertStatus(422);
    });

    it('rejects a malformed address', function (): void {
        $this->actingAs($this->owner)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => ['not-an-email'],
            ])
            ->assertStatus(422);
    });

    it('denies a student', function (): void {
        $this->actingAs($this->student)
            ->postJson("/api/v1/studio/courses/{$this->course->uuid}/enrollments/bulk", [
                'emails' => ['ada@example.test'],
            ])
            ->assertForbidden();
    });
});

describe('changing one', function (): void {
    beforeEach(function (): void {
        $this->enrollment = Enrollment::factory()->create([
            'course_id' => $this->course->id,
            'user_id' => $this->student->id,
        ]);
    });

    it('suspends with a reason', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", [
                'action' => 'suspend',
                'reason' => 'Chargeback',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspended_reason', 'Chargeback');
    });

    it('reinstates', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'suspend']);

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'reinstate'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    });

    it('revokes', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'revoke'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    });

    it('extends', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", [
                'action' => 'extend',
                'expires_at' => now()->addYear()->toIso8601String(),
            ])
            ->assertOk();

        expect(Enrollment::find($this->enrollment->id)->expires_at)->not->toBeNull();
    });

    /* Silently clearing the expiry would hand out lifetime access by accident. */
    it('refuses an extension with no date at all', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'extend'])
            ->assertStatus(422);
    });

    it('rejects an unknown action', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'obliterate'])
            ->assertStatus(422);
    });

    it('denies an instructor with no claim on the course', function (): void {
        $stranger = User::factory()->instructor()->create();

        $this->actingAs($stranger)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'suspend'])
            ->assertForbidden();
    });

    it('denies the student themselves', function (): void {
        $this->actingAs($this->student)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'reinstate'])
            ->assertForbidden();
    });

    it('lets an admin act on any course', function (): void {
        $admin = userWithRole(RoleKey::Admin);

        $this->actingAs($admin)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'suspend'])
            ->assertOk();
    });

    it('keeps the status enum honest', function (): void {
        $this->actingAs($this->owner)
            ->patchJson("/api/v1/studio/enrollments/{$this->enrollment->uuid}", ['action' => 'revoke']);

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Cancelled);
    });
});
