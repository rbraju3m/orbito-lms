<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Engagement\Models\WishlistItem;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [1]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
});

it('saves a course and returns it as a full card', function (): void {
    // A saved course and a browsed one should look identical, price included.
    $this->actingAs($this->student)
        ->postJson("/api/v1/wishlist/{$this->course->uuid}")
        ->assertCreated()
        ->assertJsonPath('data.course.id', $this->course->uuid)
        ->assertJsonStructure(['data' => ['saved_at', 'course' => ['id', 'title', 'price']]]);
});

it('treats saving twice as one entry', function (): void {
    foreach (range(1, 3) as $_) {
        $this->actingAs($this->student)
            ->postJson("/api/v1/wishlist/{$this->course->uuid}")
            ->assertCreated();
    }

    expect(WishlistItem::count())->toBe(1);
});

it('removes idempotently, whether or not it was saved', function (): void {
    $this->actingAs($this->student)
        ->deleteJson("/api/v1/wishlist/{$this->course->uuid}")
        ->assertNoContent();

    $this->actingAs($this->student)->postJson("/api/v1/wishlist/{$this->course->uuid}");

    $this->actingAs($this->student)
        ->deleteJson("/api/v1/wishlist/{$this->course->uuid}")
        ->assertNoContent();

    expect(WishlistItem::count())->toBe(0);
});

it('shows a learner only their own saved courses', function (): void {
    $other = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($this->student)->postJson("/api/v1/wishlist/{$this->course->uuid}");

    $this->actingAs($other)->getJson('/api/v1/wishlist')
        ->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($this->student)->getJson('/api/v1/wishlist')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('drops the saved entry when the wish is granted', function (): void {
    // A wishlist of things you already have is noise.
    $this->actingAs($this->student)->postJson("/api/v1/wishlist/{$this->course->uuid}");

    expect(WishlistItem::count())->toBe(1);

    app(EnrollInCourse::class)->handle($this->student, $this->course);

    expect(WishlistItem::count())->toBe(0);
});

it('does not put a course back on the list when access ends', function (): void {
    /*
     * Losing access does not mean you wanted the course back on a list you
     * last touched a year ago.
     */
    $enrollment = app(EnrollInCourse::class)->handle($this->student, $this->course);
    $enrollment->forceFill(['status' => 'cancelled'])->save();

    expect(WishlistItem::count())->toBe(0);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/wishlist')->assertUnauthorized();
    $this->postJson("/api/v1/wishlist/{$this->course->uuid}")->assertUnauthorized();
});
