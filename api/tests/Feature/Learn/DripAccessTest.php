<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\DripMode;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\Resource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Progress\Actions\TrackItemProgress;

beforeEach(function (): void {
    seedRegistry();

    $this->owner = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->owner)->published()->create(),
        [3],
    );

    [$this->first, $this->second, $this->third] = $this->course->items()
        ->orderBy('position')->get()->all();

    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);

    $this->setDrip = function (DripMode $mode): void {
        $this->course->setting->update(['drip_mode' => $mode]);
    };
});

/** Helper: the outline entry for one item, as the player receives it. */
function outlineFor(object $test, string $itemUuid): array
{
    $response = $test->getJson("/api/v1/learn/courses/{$test->course->uuid}")->assertOk();

    foreach ($response->json('data.curriculum') as $section) {
        foreach ($section['items'] as $item) {
            if ($item['id'] === $itemUuid) {
                return $item;
            }
        }
    }

    return [];
}

describe('no drip', function (): void {
    it('leaves every item open', function (): void {
        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->third->uuid}")
            ->assertOk();
    });

    it('reports nothing locked in the outline', function (): void {
        $item = outlineFor($this->actingAs($this->student), $this->third->uuid);

        expect($item['is_locked'])->toBeFalse()
            ->and($item['unlocks_at'])->toBeNull()
            ->and($item['blocked_by'])->toBeNull();
    });
});

describe('by date', function (): void {
    beforeEach(fn () => ($this->setDrip)(DripMode::ByDate));

    it('locks an item until its date, and says when', function (): void {
        $this->second->update(['drip_available_at' => now()->addWeek()]);

        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertStatus(423);

        expect($response)->toBeApiError('content_locked')
            ->and($response->json('error.details.0.code'))->toBe('drip_locked')
            ->and($response->json('error.meta.unlocks_at'))->not->toBeNull();
    });

    it('opens it once the date has passed', function (): void {
        $this->second->update(['drip_available_at' => now()->subMinute()]);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });

    it('leaves an item with no date open', function (): void {
        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });
});

describe('by days', function (): void {
    beforeEach(fn () => ($this->setDrip)(DripMode::ByDays));

    it('counts from the enrolment date', function (): void {
        $this->second->update(['drip_after_days' => 7]);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertStatus(423);

        $this->travel(8)->days();

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });

    /*
     * A seat granted in advance must not burn its first week unopened — the
     * clock starts when access does, not when the row was written.
     */
    it('counts from starts_at when the seat was dated forward', function (): void {
        $this->second->update(['drip_after_days' => 1]);
        $this->enrollment->update(['starts_at' => now()->subDays(3)]);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });
});

describe('sequential', function (): void {
    beforeEach(fn () => ($this->setDrip)(DripMode::Sequential));

    it('always opens the first item', function (): void {
        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->first->uuid}")
            ->assertOk();
    });

    it('locks an item until the one before it is completed, and names it', function (): void {
        $response = $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertStatus(423);

        expect($response->json('error.details.0.code'))->toBe('drip_locked')
            ->and($response->json('error.meta.blocked_by_title'))->toBe($this->first->title)
            ->and($response->json('error.message'))->toContain($this->first->title);
    });

    it('opens it once the predecessor is complete', function (): void {
        app(TrackItemProgress::class)->complete($this->enrollment, $this->first);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });

    it('keeps the third locked while the second is unfinished', function (): void {
        app(TrackItemProgress::class)->complete($this->enrollment, $this->first);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->third->uuid}")
            ->assertStatus(423);
    });

    /*
     * The batch path can only see published items, so the single path must
     * agree — otherwise the outline shows open and the item endpoint 423s.
     */
    it('ignores an explicit dependency on an unpublished item', function (): void {
        $this->second->update(['is_published' => false]);
        $this->third->update(['drip_after_item_id' => $this->second->id]);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->third->uuid}")
            ->assertOk();

        expect(outlineFor($this->actingAs($this->student), $this->third->uuid)['is_locked'])
            ->toBeFalse();
    });

    it('honours an explicit dependency over the preceding item', function (): void {
        $this->third->update(['drip_after_item_id' => $this->first->id]);

        app(TrackItemProgress::class)->complete($this->enrollment, $this->first);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->third->uuid}")
            ->assertOk();
    });
});

describe('sequential with an item nobody can complete', function (): void {
    /*
     * A downloadable resource is not something a learner finishes, so making
     * one the blocker would deadlock the rest of the course. Sequential drip
     * looks past it to the last completable item.
     */
    it('steps over a resource rather than stalling on it', function (): void {
        ($this->setDrip)(DripMode::Sequential);

        $resource = App\Domain\Curriculum\Models\Resource::create([
            'description' => 'Reading list',
            'external_url' => 'https://example.test/list.pdf',
        ]);

        CourseItem::create([
            'course_id' => $this->course->id,
            'section_id' => $this->first->section_id,
            'position' => 1,
            'type' => ItemType::Resource,
            'itemable_type' => $resource->getMorphClass(),
            'itemable_id' => $resource->id,
            'title' => 'Reading list',
            'is_published' => true,
        ]);

        $this->second->update(['position' => 2]);

        app(TrackItemProgress::class)->complete($this->enrollment, $this->first);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });
});

describe('bypasses', function (): void {
    it('never drips a preview item', function (): void {
        ($this->setDrip)(DripMode::Sequential);
        $this->second->update(['is_preview' => true]);

        $this->actingAs($this->student)
            ->getJson("/api/v1/learn/items/{$this->second->uuid}")
            ->assertOk();
    });

    /* An instructor who cannot open week 3 cannot edit week 3. */
    it('never drips course staff', function (): void {
        ($this->setDrip)(DripMode::Sequential);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/learn/items/{$this->third->uuid}")
            ->assertOk();
    });

    it('shows staff an unlocked outline', function (): void {
        ($this->setDrip)(DripMode::Sequential);

        $item = outlineFor($this->actingAs($this->owner), $this->third->uuid);

        expect($item['is_locked'])->toBeFalse();
    });
});

describe('the outline', function (): void {
    beforeEach(fn () => ($this->setDrip)(DripMode::Sequential));

    /*
     * Hiding a locked item would make the course look shorter than it is and
     * turn "10 lessons" on the sales page into a lie.
     */
    it('still lists a locked item, marked and explained', function (): void {
        $item = outlineFor($this->actingAs($this->student), $this->second->uuid);

        expect($item)->not->toBe([])
            ->and($item['is_locked'])->toBeTrue()
            ->and($item['blocked_by'])->toBe($this->first->title);
    });

    /*
     * The batch path and the single-item path are different code. An item the
     * outline shows as open must not 423 when opened, and vice versa.
     */
    it('agrees with the item endpoint on every item', function (): void {
        $this->second->update(['drip_after_item_id' => $this->first->id]);

        foreach ([$this->first, $this->second, $this->third] as $item) {
            $outline = outlineFor($this->actingAs($this->student), $item->uuid);
            $status = $this->actingAs($this->student)
                ->getJson("/api/v1/learn/items/{$item->uuid}")->status();

            expect($outline['is_locked'])->toBe(
                $status === 423,
                "outline and item endpoint disagree on {$item->title}",
            );
        }
    });
});
