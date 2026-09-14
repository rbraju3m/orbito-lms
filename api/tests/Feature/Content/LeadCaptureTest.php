<?php

declare(strict_types=1);

use App\Domain\Catalog\Enums\CourseVisibility;
use App\Domain\Catalog\Models\Course;
use App\Domain\Content\Enums\LeadSource;
use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Events\LeadCaptured;
use App\Domain\Content\Models\Lead;
use App\Domain\Content\Support\LeadFormToken;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\SwitchesTenants;
use Tests\Support\FakeHostResolver;

/*
 * The lead form — the one anonymous WRITE in the product (docs/LEADS.md).
 *
 * Every request here is a stranger's: no user, and no academy open, so the
 * middleware has to find the academy from the URL. That is why the file ends
 * tenancy before each request and switches tenants — `PublicSiteTest` carries
 * the whole argument. Fixtures are created BEFORE the first request, while the
 * harness still has the academy open.
 */

uses(SwitchesTenants::class);

beforeEach(function (): void {
    seedRegistry();
});

/** Fetches the form as a stranger, then waits the way a person would. */
function leadFormToken(): string
{
    tenancy()->end();

    $token = test()->getJson('/api/v1/public/test-academy/lead-form')->assertOk()->json('data.token');

    test()->travel(30)->seconds();

    return (string) $token;
}

/** @param  array<string, mixed>  $overrides */
function submitLead(array $overrides = [], ?string $token = null): TestResponse
{
    $body = $overrides + [
        'email' => 'Ada@Example.test',
        'name' => 'Ada Lovelace',
        'consent' => true,
        'source' => 'site',
        'form_token' => $token ?? leadFormToken(),
    ];

    tenancy()->end();

    return test()->postJson('/api/v1/public/test-academy/leads', $body);
}

/** @return Collection<int, Lead> */
function capturedLeads(): Collection
{
    return Tenant::query()->where('slug', 'test-academy')->firstOrFail()
        ->run(fn () => Lead::query()->orderBy('id')->get());
}

it('serves a stranger a form token and the words they are asked to agree to', function (): void {
    tenancy()->end();

    $response = $this->getJson('/api/v1/public/test-academy/lead-form')->assertOk();

    expect($response->json('data.token'))->toBeString()->not->toBeEmpty()
        // The server's wording, with the academy's name in it — not a label
        // the client wrote.
        ->and($response->json('data.consent_text'))->toContain('Test Academy')
        // A token served from a shared cache is a token somebody else was issued.
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('captures a lead from somebody with no account, keeping the consent they saw', function (): void {
    Event::fake([LeadCaptured::class]);

    submitLead()->assertAccepted()->assertExactJson(['data' => ['received' => true]]);

    $leads = capturedLeads();

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->email)->toBe('ada@example.test')
        ->and($leads[0]->name)->toBe('Ada Lovelace')
        ->and($leads[0]->status)->toBe(LeadStatus::New)
        ->and($leads[0]->source)->toBe(LeadSource::Site)
        ->and($leads[0]->submissions_count)->toBe(1)
        ->and($leads[0]->consent_text)->toContain('Test Academy');

    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('answers an address already on the list exactly as it answers a new one', function (): void {
    Event::fake([LeadCaptured::class]);

    $first = submitLead();
    $again = submitLead(['email' => 'ada@example.test', 'name' => 'Somebody Else']);

    // "Already subscribed" would tell a stranger whose address is on the list.
    expect($again->status())->toBe($first->status())
        ->and($again->json())->toEqual($first->json());

    $leads = capturedLeads();

    expect($leads)->toHaveCount(1)
        ->and($leads[0]->submissions_count)->toBe(2)
        // A repeat can be anybody typing that address: it cannot rename the person.
        ->and($leads[0]->name)->toBe('Ada Lovelace');

    // Nor flood an integration listening for new leads.
    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('attributes a lead to the course page it was left on, by the title the server knows', function (): void {
    $course = Course::factory()->published()->create([
        'title' => 'Watercolour for beginners',
        'slug' => 'watercolour',
        'visibility' => CourseVisibility::Public,
    ]);

    submitLead(['source' => 'course', 'source_slug' => 'watercolour'])->assertAccepted();

    $lead = capturedLeads()->sole();

    expect($lead->source)->toBe(LeadSource::Course)
        ->and($lead->source_id)->toBe($course->id)
        ->and($lead->source_title)->toBe('Watercolour for beginners');
});

it('refuses to attribute a lead to a page a stranger could not have been reading', function (): void {
    Course::factory()->create(['slug' => 'not-finished']);

    $response = submitLead(['source' => 'course', 'source_slug' => 'not-finished'])->assertUnprocessable();

    expect($response)->toBeApiError('validation_failed')
        ->and($response->json('error.details.0.field'))->toBe('source_slug')
        ->and(capturedLeads())->toBeEmpty();
});

it('discards a filled honeypot and answers it like a real lead', function (): void {
    Event::fake([LeadCaptured::class]);

    $trapped = submitLead(['website' => 'https://spam.example']);
    $real = submitLead(['email' => 'grace@example.test']);

    // Anything more specific tells a script which check it tripped.
    expect($trapped->status())->toBe(202)
        ->and($trapped->json())->toEqual($real->json())
        ->and(capturedLeads()->pluck('email')->all())->toBe(['grace@example.test']);

    Event::assertDispatchedTimes(LeadCaptured::class, 1);
});

it('silently discards a form posted faster than a person could fill it in', function (): void {
    $this->freezeTime();

    tenancy()->end();
    $token = (string) $this->getJson('/api/v1/public/test-academy/lead-form')->json('data.token');

    // No wait at all: posted the moment it was served.
    submitLead([], $token)->assertAccepted()->assertExactJson(['data' => ['received' => true]]);

    expect(capturedLeads())->toBeEmpty();
});

it('asks somebody who left the form open for days to reload it', function (): void {
    tenancy()->end();
    $token = (string) $this->getJson('/api/v1/public/test-academy/lead-form')->json('data.token');

    $this->travel(25)->hours();

    $response = submitLead([], $token)->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('form_token')
        ->and(capturedLeads())->toBeEmpty();
});

it('refuses a form token another academy issued', function (): void {
    $borrowed = app(LeadFormToken::class)->mint('another-academy', now()->subMinute());

    $response = submitLead([], $borrowed)->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('form_token');
});

it('refuses a lead that did not agree to be contacted', function (): void {
    $response = submitLead(['consent' => false])->assertUnprocessable();

    expect($response->json('error.details.0.field'))->toBe('consent')
        ->and(capturedLeads())->toBeEmpty();
});

it('stops one address being submitted over and over', function (): void {
    config(['orbito.rate_limits.leads_per_address' => 2]);

    submitLead()->assertAccepted();
    submitLead()->assertAccepted();
    submitLead()->assertTooManyRequests();
});

it('sends lead.captured to an integration that asked for it, once per address', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    app()->instance(HostResolver::class, new FakeHostResolver);
    WebhookEndpoint::factory()->listeningTo([WebhookTopic::LeadCaptured])->create();

    submitLead()->assertAccepted();
    submitLead(['email' => 'ada@example.test'])->assertAccepted();

    // The repeat moved a counter; it is not a second lead to push into a CRM.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"lead.captured"')
        && str_contains($request->body(), 'ada@example.test'));
});

it('gives the lead form of an academy that does not exist a plain 404', function (): void {
    tenancy()->end();

    $this->postJson('/api/v1/public/no-such-academy/leads', [])->assertNotFound();
});
