<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Actions\ChangeEnrollmentStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Events\EnrollmentReinstated;
use App\Domain\Enrollment\Events\EnrollmentRevoked;
use App\Domain\Enrollment\Events\EnrollmentSuspended;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    seedRegistry();
    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
    ]);
    $this->item = $this->course->items()->orderBy('position')->first();
    $this->actor = fn () => $this->actingAs($this->student);
});

describe('suspension', function (): void {
    it('closes the player and says so', function (): void {
        app(ChangeEnrollmentStatus::class)->suspend($this->enrollment, 'Payment reversed');

        $response = ($this->actor)()
            ->getJson("/api/v1/learn/items/{$this->item->uuid}")
            ->assertStatus(423);

        expect($response)->toBeApiError('content_locked')
            ->and($response->json('error.details.0.code'))->toBe('enrollment_suspended');
    });

    it('records the reason and fires the event', function (): void {
        Event::fake([EnrollmentSuspended::class]);

        $updated = app(ChangeEnrollmentStatus::class)->suspend($this->enrollment, 'Payment reversed');

        expect($updated->status)->toBe(EnrollmentStatus::Suspended)
            ->and($updated->suspended_reason)->toBe('Payment reversed')
            ->and($updated->suspended_at)->not->toBeNull();

        Event::assertDispatched(EnrollmentSuspended::class);
    });

    it('reopens the player on reinstatement', function (): void {
        Event::fake([EnrollmentReinstated::class]);

        app(ChangeEnrollmentStatus::class)->suspend($this->enrollment);
        $updated = app(ChangeEnrollmentStatus::class)->reinstate($this->enrollment->fresh());

        expect($updated->status)->toBe(EnrollmentStatus::Active)
            ->and($updated->suspended_reason)->toBeNull();

        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertOk();

        Event::assertDispatched(EnrollmentReinstated::class);
    });

    /* Somebody suspended after finishing must not be handed an unfinished course. */
    it('reinstates a finished learner back to completed', function (): void {
        $this->enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
        ])->save();

        app(ChangeEnrollmentStatus::class)->suspend($this->enrollment);
        $updated = app(ChangeEnrollmentStatus::class)->reinstate($this->enrollment->fresh());

        expect($updated->status)->toBe(EnrollmentStatus::Completed);
    });
});

describe('revocation', function (): void {
    it('closes access but keeps the record', function (): void {
        Event::fake([EnrollmentRevoked::class]);

        app(ChangeEnrollmentStatus::class)->revoke($this->enrollment);

        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertStatus(423);

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Cancelled);
        Event::assertDispatched(EnrollmentRevoked::class);
    });

    it('refuses to suspend an enrolment already cancelled', function (): void {
        app(ChangeEnrollmentStatus::class)->revoke($this->enrollment);

        expect(fn () => app(ChangeEnrollmentStatus::class)->suspend($this->enrollment->fresh()))
            ->toThrow(EnrollmentRejected::class);
    });
});

describe('expiry', function (): void {
    /* Access is evaluated live, so it must not wait on the sweeper. */
    it('closes access the moment the date passes, before any sweep', function (): void {
        $this->enrollment->update(['expires_at' => now()->addHour()]);

        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertOk();

        $this->travel(2)->hours();

        $response = ($this->actor)()
            ->getJson("/api/v1/learn/items/{$this->item->uuid}")
            ->assertStatus(423);

        expect($response->json('error.details.0.code'))->toBe('enrollment_expired')
            ->and(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Active);
    });

    it('reopens access when the date is pushed out', function (): void {
        $this->enrollment->update([
            'expires_at' => now()->subDay(),
            'status' => EnrollmentStatus::Expired,
        ]);

        app(ChangeEnrollmentStatus::class)->extend($this->enrollment, now()->addMonth());

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Active);

        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertOk();
    });

    it('grants indefinite access when the expiry is cleared', function (): void {
        $this->enrollment->update([
            'expires_at' => now()->subDay(),
            'status' => EnrollmentStatus::Expired,
        ]);

        app(ChangeEnrollmentStatus::class)->extend($this->enrollment, null);

        expect(Enrollment::find($this->enrollment->id)->expires_at)->toBeNull();
        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertOk();
    });
});

describe('a seat dated forward', function (): void {
    it('opens nothing until it starts', function (): void {
        $this->enrollment->update(['starts_at' => now()->addWeek()]);

        $response = ($this->actor)()
            ->getJson("/api/v1/learn/items/{$this->item->uuid}")
            ->assertStatus(423);

        expect($response->json('error.details.0.code'))->toBe('enrollment_not_started')
            ->and($response->json('error.meta.unlocks_at'))->not->toBeNull();
    });

    it('opens once the start date arrives', function (): void {
        $this->enrollment->update(['starts_at' => now()->addWeek()]);

        $this->travel(8)->days();

        ($this->actor)()->getJson("/api/v1/learn/items/{$this->item->uuid}")->assertOk();
    });
});

/*
 * The sweeper's own tests moved to Feature/Tenancy/ScheduledCommandTest.php.
 *
 * It walks every academy now, and switching tenants purges the connection this
 * suite's transaction lives on — so it needs the non-transacted harness those
 * tests use. Expiry's LIVE behaviour, which is what actually gates access, is
 * still covered above and does not involve the command at all.
 */
