<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Models\Course;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use App\Domain\Platform\Enums\RegistrationMode;
use App\Domain\Platform\Models\Tenant;
use Tests\Concerns\SwitchesTenants;

/*
 * The first anonymous surface in the product.
 *
 * EVERY test here calls `tenancy()->end()` before the request, and that is the
 * whole point of the file rather than a detail of it: the harness leaves an
 * academy open all test long, so a public route tested without it would have
 * its `initialize()` short-circuited and would pass while resolving nothing
 * (§ Multi-tenancy, and the reason `ScheduledCommandTest` exists). Ending
 * tenancy means the middleware has to find the academy from the URL, the way
 * a stranger's request does — which in turn means the academy is COMMITTED
 * rather than transacted, hence `SwitchesTenants`.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();

    $this->academy = Tenant::findOrFail($this->sharedTenantId());

    $this->course = Course::factory()->published()->create([
        'title' => 'Watercolour for beginners',
        'slug' => 'watercolour-for-beginners',
        'visibility' => CourseVisibility::Public,
    ]);
});

/**
 * The error envelope minus its correlation id, which differs per request.
 *
 * @param  array<string, mixed>  $body
 * @return array<string, mixed>
 */
function withoutRequestId(array $body): array
{
    unset($body['error']['request_id']);

    return $body;
}

/** Nobody is signed in, and no academy is open — a stranger's request. */
function asStranger(): void
{
    tenancy()->end();
}

it('serves an academy its own header, to somebody with no account', function (): void {
    $this->academy->registration_mode = RegistrationMode::Open->value;
    $this->academy->save();

    asStranger();

    $this->getJson('/api/v1/public/test-academy')
        ->assertOk()
        ->assertJsonPath('data.slug', 'test-academy')
        ->assertJsonPath('data.name', 'Test Academy')
        // So the page can offer a Sign up button, or explain why it cannot.
        ->assertJsonPath('data.registration_open', true);
});

it('says registration is shut without saying anything else about the academy', function (): void {
    $this->academy->registration_mode = RegistrationMode::Closed->value;
    $this->academy->save();

    asStranger();

    $response = $this->getJson('/api/v1/public/test-academy')
        ->assertOk()
        ->assertJsonPath('data.registration_open', false);

    // An operator's view of a customer is not the public's business.
    foreach (['status', 'is_active', 'approved_at', 'approved_by', 'suspended_reason', 'rejected_reason'] as $field) {
        expect($response->json('data'))->not->toHaveKey($field);
    }
});

it('lists the published, public courses', function (): void {
    Course::factory()->create(['title' => 'Still a draft']);
    Course::factory()->published()->create([
        'title' => 'Members only',
        'visibility' => CourseVisibility::Private,
    ]);

    asStranger();

    $this->getJson('/api/v1/public/test-academy/courses')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Watercolour for beginners');
});

it('opens a course sales page by slug, with no account and no academy open', function (): void {
    asStranger();

    $this->getJson('/api/v1/public/test-academy/courses/watercolour-for-beginners')
        ->assertOk()
        ->assertJsonPath('data.slug', 'watercolour-for-beginners')
        ->assertJsonPath('data.title', 'Watercolour for beginners');
});

it('reaches an UNLISTED course by direct link but never a draft or a private one', function (): void {
    $unlisted = Course::factory()->published()->create([
        'slug' => 'sent-to-you-personally',
        'visibility' => CourseVisibility::Unlisted,
    ]);
    $private = Course::factory()->published()->create([
        'slug' => 'staff-only',
        'visibility' => CourseVisibility::Private,
    ]);
    $draft = Course::factory()->create(['slug' => 'not-finished']);

    asStranger();

    // Unlisted means "not in the catalogue", not "secret" — the same answer
    // `Course::live()` gives the members-only page.
    $this->getJson("/api/v1/public/test-academy/courses/{$unlisted->slug}")->assertOk();

    // 404 and not 403: the status must not confirm these exist.
    $this->getJson("/api/v1/public/test-academy/courses/{$private->slug}")->assertNotFound();
    $this->getJson("/api/v1/public/test-academy/courses/{$draft->slug}")->assertNotFound();
});

it('tells a stranger nothing that belongs to a member or to staff', function (): void {
    asStranger();

    $data = $this->getJson('/api/v1/public/test-academy/courses/watercolour-for-beginners')
        ->assertOk()
        ->json('data');

    /*
     * None of these is stripped by this endpoint — they are computed from
     * `$request->user()`, which is null here, so they simply never appear.
     * That is the property worth testing: a new viewer-scoped field added to
     * `CourseResource` cannot leak through the public page by omission.
     */
    foreach (['is_wishlisted', 'settings', 'publish_checklist', 'allowed_transitions'] as $field) {
        expect($data)->not->toHaveKey($field);
    }
});

it('gives the same 404 for an academy that does not exist and one that is closed', function (): void {
    $suspended = Tenant::factory()->suspended()->create(['slug' => 'gone-quiet']);

    asStranger();

    $unknown = $this->getJson('/api/v1/public/no-such-academy/courses');
    $closed = $this->getJson("/api/v1/public/{$suspended->slug}/courses");

    // Anything more specific is an oracle for which academies exist and which
    // were suspended, and both are reachable by anybody on the internet.
    $unknown->assertNotFound();
    $closed->assertNotFound();

    // Byte-identical but for the correlation id, which is per request.
    expect(withoutRequestId($closed->json()))->toEqual(withoutRequestId($unknown->json()));
});

it('lists published webinars and hides the rest', function (): void {
    $session = LiveSession::factory()->create(['course_id' => null, 'cohort_id' => null]);

    Webinar::factory()->published()->create([
        'title' => 'Open evening',
        'slug' => 'open-evening',
        'live_session_id' => $session->id,
    ]);
    Webinar::factory()->create(['title' => 'Not announced yet']);

    asStranger();

    $this->getJson('/api/v1/public/test-academy/webinars')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Open evening');

    $this->getJson('/api/v1/public/test-academy/webinars/open-evening')
        ->assertOk()
        ->assertJsonPath('data.title', 'Open evening');
});

it('never puts a host link on a public webinar page', function (): void {
    $session = LiveSession::factory()->create([
        'course_id' => null,
        'cohort_id' => null,
        'join_url' => 'https://meet.example.test/join-here',
        'host_url' => 'https://meet.example.test/start-as-host',
    ]);

    $webinar = Webinar::factory()->published()->create([
        'slug' => 'open-evening',
        'live_session_id' => $session->id,
    ]);

    asStranger();

    $body = $this->getJson("/api/v1/public/test-academy/webinars/{$webinar->slug}")
        ->assertOk()
        ->getContent();

    // The start link opens the meeting AS the host. It is `$hidden` on the
    // model and absent from every resource; this is the assertion that keeps
    // it that way on the one surface a stranger can read.
    expect($body)->not->toContain('start-as-host')
        ->and($body)->not->toContain('join-here');
});
