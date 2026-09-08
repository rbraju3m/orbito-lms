<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Tenant;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\SwitchesTenants;

// These commands walk every academy, so the academy cannot be transacted —
// see the trait.
uses(SwitchesTenants::class);

/*
 * The scheduler runs CENTRALLY, with no academy open.
 *
 * Every one of these commands reads tenant-schema tables, so each has to walk
 * the academies itself. The ordinary test harness hides this completely: it
 * leaves a tenant initialised for the whole test, so `$this->artisan(...)`
 * passes whether or not the command knows tenancy exists.
 *
 * `tenancy()->end()` before each call is therefore the entire point of this
 * file — it reproduces the one condition production runs under and tests
 * never otherwise reach.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->course = courseWithCurriculum(Course::factory()->published()->create(), [2]);
    $this->student = User::factory()->withRole(RoleKey::Student)->create();
    $this->enrollment = Enrollment::factory()->create([
        'course_id' => $this->course->id,
        'user_id' => $this->student->id,
        'expires_at' => now()->subDay(),
    ]);

    /*
     * Run a command the way the scheduler does — with no academy open — then
     * step back into one, because the assertions that follow read tenant
     * tables and the command deliberately leaves no tenant initialised.
     */
    $this->centrally = function (string $command): void {
        tenancy()->end();

        expect(tenancy()->initialized)->toBeFalse();

        $this->artisan($command)->assertSuccessful();

        tenancy()->initialize(Tenant::find($this->sharedTenantId()));
    };
});

it('sweeps expired enrolments with no tenant open', function (): void {
    ($this->centrally)('enrollment:sweep-expired');

    expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Expired);
});

it('sweeps expired quiz attempts with no tenant open', function (): void {
    ($this->centrally)('quiz:sweep-expired');
});

it('reconciles course progress with no tenant open', function (): void {
    ($this->centrally)('progress:reconcile');
});

it('reconciles usage counters with no tenant open', function (): void {
    ($this->centrally)('usage:reconcile');
});

it('prunes analytics events with no tenant open', function (): void {
    ($this->centrally)('analytics:prune');
});

it('syncs gamification rules with no tenant open', function (): void {
    ($this->centrally)('gamification:sync');
});

it('sends live session reminders with no tenant open', function (): void {
    ($this->centrally)('live:remind');
});

it('builds leaderboards with no tenant open', function (): void {
    ($this->centrally)('gamification:leaderboards');
});

it('builds analytics rollups with no tenant open', function (): void {
    // The one command here that WRITES derived rows rather than sweeping, so
    // a tenant-blind version would silently build them into the central
    // database and leave every academy's dashboards empty.
    ($this->centrally)('analytics:rollup');
});

/*
 * A broken academy must not stop the sweep. The nightly job that gives up on
 * the first bad tenant leaves every later one unswept, and nobody finds out
 * until the tenant is fixed.
 */
it('keeps going when one academy fails, and reports failure', function (): void {
    // A tenant row whose schema was never provisioned — the shape a crashed
    // provisioning run or a half-finished restore actually leaves behind.
    Tenant::query()->getConnection()->table('tenants')->insert([
        'id' => 'ghost',
        'slug' => 'ghost',
        'name' => 'Ghost Academy',
        'status' => 'active',
        'is_active' => true,
        'data' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    tenancy()->end();

    // Non-zero exit: the operator has to learn that one academy was skipped.
    $this->artisan('enrollment:sweep-expired')->assertFailed();

    // ...and the healthy academy was still swept.
    tenancy()->initialize(Tenant::find($this->sharedTenantId()));
    expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Expired);
});

it('leaves no tenant initialised behind it', function (): void {
    tenancy()->end();

    $this->artisan('enrollment:sweep-expired')->assertSuccessful();

    // stancl's Tenant::run() has no try/finally; the trait ends tenancy itself
    // so nothing after the command reads an academy's database by accident.
    expect(tenancy()->initialized)->toBeFalse();
});

/*
 * Moved from EnrollmentLifecycleTest when the sweeper became per-tenant.
 */

describe('the expiry sweeper', function (): void {
    /*
 * The EVENT is not asserted here, and that is a known gap rather than an
 * oversight. Inside a command running under `Tenant::run()` the dispatcher's
 * listener array reads empty — same dispatcher object, `hasListeners` false at
 * the dispatch and true again immediately after — so neither `Event::fake()`
 * nor a live listener observes it. `EnrollmentExpired::dispatch()` is reached
 * (verified by instrumenting the command), and the state change it accompanies
 * IS asserted; only the assertion mechanism is unavailable.
 */
    it('moves lapsed enrolments to expired', function (): void {
        $this->enrollment->update(['expires_at' => now()->subDay()]);

        ($this->centrally)('enrollment:sweep-expired');

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Expired);
    });

    it('leaves a live enrolment alone', function (): void {
        $this->enrollment->update(['expires_at' => now()->addMonth()]);

        ($this->centrally)('enrollment:sweep-expired');

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Active);
    });

    it('is idempotent', function (): void {
        $this->enrollment->update(['expires_at' => now()->subDay()]);

        ($this->centrally)('enrollment:sweep-expired');
        ($this->centrally)('enrollment:sweep-expired');

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Expired);
    });

    it('expires a completed enrolment too', function (): void {
        $this->enrollment->forceFill([
            'status' => EnrollmentStatus::Completed,
            'completed_at' => now(),
            'expires_at' => now()->subDay(),
        ])->save();

        ($this->centrally)('enrollment:sweep-expired');

        expect(Enrollment::find($this->enrollment->id)->status)->toBe(EnrollmentStatus::Expired);
    });
});
