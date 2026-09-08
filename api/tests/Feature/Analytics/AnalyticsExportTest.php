<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Models\Subscription;
use Carbon\CarbonImmutable;

/*
 * CSV. The only responses in the API that are not `{data:…}` — and the
 * easiest place in it to leak a whole academy's revenue, because nobody reads
 * a spreadsheet expecting a permission error.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->admin = userWithRole(RoleKey::Admin);
    $this->instructor = User::factory()->instructor()->create();
    $this->course = courseWithCurriculum(
        Course::factory()->ownedBy($this->instructor)->published()->create(['title' => 'আধুনিক বাংলা কবিতা']),
        [1],
    );

    $this->other = User::factory()->instructor()->create();
    $this->otherCourse = courseWithCurriculum(
        Course::factory()->ownedBy($this->other)->published()->create(['title' => 'Someone Else']),
        [1],
    );

    $today = CarbonImmutable::now('UTC')->startOfDay()->toDateString();

    DailyPlatformStat::create([
        'date' => $today, 'new_users' => 4, 'new_enrollments' => 7, 'completions' => 2,
        'revenue_minor' => 500000, 'currency' => 'BDT', 'active_learners' => 11,
    ]);

    foreach ([$this->course, $this->otherCourse] as $course) {
        DailyCourseStat::create([
            'date' => $today, 'course_id' => $course->id,
            'views' => 40, 'enrollments' => 7, 'completions' => 2,
            'revenue_minor' => 500000, 'currency' => 'BDT', 'active_learners' => 11,
        ]);
    }
});

it('streams the platform series as a downloadable file', function (): void {
    $response = $this->actingAs($this->admin)
        ->get('/api/v1/analytics/export/platform')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->headers->get('content-disposition'))->toContain('attachment');

    $csv = $response->streamedContent();

    // A BOM, or Excel on Windows reads UTF-8 as the local codepage and turns
    // every Bengali title into mojibake.
    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('new_enrollments')
        // Minor units, with the code beside them — never a float.
        ->and($csv)->toContain('500000')
        ->and($csv)->toContain('BDT');
});

it('gives an instructor only their own courses', function (): void {
    $csv = $this->actingAs($this->instructor)
        ->get('/api/v1/analytics/export/courses')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('আধুনিক বাংলা কবিতা')
        // The whole reason the row set is scoped rather than the query
        // parameter: an export nobody reads carefully is the easiest leak in
        // an API.
        ->and($csv)->not->toContain('Someone Else');
});

it('gives a platform reader every course', function (): void {
    $csv = $this->actingAs($this->admin)
        ->get('/api/v1/analytics/export/courses')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('আধুনিক বাংলা কবিতা')
        ->and($csv)->toContain('Someone Else');
});

it('refuses the platform export to an instructor', function (): void {
    // They hold `analytics.export`, but not `analytics.view.platform` — the
    // two are checked separately on purpose.
    $this->actingAs($this->instructor)
        ->get('/api/v1/analytics/export/platform')
        ->assertForbidden();
});

it('refuses a funnel export for somebody else course', function (): void {
    $this->actingAs($this->instructor)
        ->get("/api/v1/analytics/courses/{$this->otherCourse->uuid}/export")
        ->assertForbidden();
});

it('refuses a learner everything', function (): void {
    $student = User::factory()->withRole(RoleKey::Student)->create();

    $this->actingAs($student)->get('/api/v1/analytics/export/courses')->assertForbidden();
    $this->actingAs($student)->get('/api/v1/analytics/export/platform')->assertForbidden();
});

it('still reads and exports for a LAPSED academy', function (): void {
    /*
     * `subscription` gates writes only. An academy that has not paid must
     * still be able to see — and export — its own data: taking it away is
     * what turns a lapsed customer into a support case about export (§ Multi-tenancy).
     */
    Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())
        ->firstOrFail()
        ->forceFill([
            'status' => SubscriptionStatus::Expired,
            'current_period_ends_at' => now()->subMonth(),
        ])->save();

    $this->actingAs($this->admin)
        ->getJson('/api/v1/analytics/overview')
        ->assertOk();

    $this->actingAs($this->admin)
        ->get('/api/v1/analytics/export/platform')
        ->assertOk();
});

it('still accepts a view beacon from a lapsed academy', function (): void {
    /*
     * The track route sits OUTSIDE `subscription` deliberately. It is a write
     * in HTTP terms and an observation in domain terms, and a lapsed
     * academy's pages firing beacons into a 402 would be a retry loop in
     * somebody's browser.
     */
    Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())
        ->firstOrFail()
        ->forceFill([
            'status' => SubscriptionStatus::Expired,
            'current_period_ends_at' => now()->subMonth(),
        ])->save();

    $this->actingAs($this->instructor)
        ->postJson('/api/v1/analytics/track', ['events' => [['name' => 'course_viewed']]])
        ->assertAccepted();
});
