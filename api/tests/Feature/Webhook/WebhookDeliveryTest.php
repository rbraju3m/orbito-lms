<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Events\CourseEnrolled;
use App\Domain\Enrollment\Events\EnrollmentSuspended;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Webhook\Actions\QueueWebhookEvent;
use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Jobs\DeliverWebhook;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Domain\Webhook\Support\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;

/*
 * What an integrator actually receives: the domain event, turned into a
 * signed request, retried, and given up on — and never able to break the
 * request that caused it.
 */

beforeEach(function (): void {
    seedRegistry();

    $this->dns = new FakeHostResolver;
    app()->instance(HostResolver::class, $this->dns);

    $this->student = User::factory()->withRole(RoleKey::Student)->create(['name' => 'Rahima Akter']);
    $this->course = Course::factory()->published()->create(['title' => 'Close Reading']);
});

/** An enrolment whose event is fired by hand — for the listener, not the flow. */
function webhookEnrolment(?User $learner = null): Enrollment
{
    return Enrollment::factory()->create([
        'course_id' => test()->course->id,
        'user_id' => ($learner ?? test()->student)->id,
    ]);
}

/** The signature a receiver would compute, checked the way docs/WEBHOOKS.md says. */
function signatureIsValid(Request $request, string $secret): bool
{
    if (preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $request->header('Orbito-Signature')[0] ?? '', $m) !== 1) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $m[1].'.'.$request->body(), $secret), $m[2]);
}

/** Runs the job again, as a queue worker would after the backoff. */
function retryDelivery(WebhookDelivery $delivery): void
{
    app()->call([new DeliverWebhook($delivery->id), 'handle']);
}

/* ---------------------------------------------------------- the flow */

/* Built the app's way: an instructor enrols somebody through the studio. */
it('sends a signed enrollment.created when somebody is enrolled', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->published()->create();
    $endpoint = WebhookEndpoint::factory()->create();

    $this->actingAs($instructor)
        ->postJson("/api/v1/studio/courses/{$course->getRouteKey()}/enrollments", ['email' => $this->student->email])
        ->assertCreated();

    Http::assertSent(fn (Request $request): bool => $request->url() === $endpoint->url
        && signatureIsValid($request, $endpoint->secret)
        && $request->header('Orbito-Event')[0] === 'enrollment.created'
        && $request['type'] === 'enrollment.created'
        && $request['data']['learner'] === [
            'id' => $this->student->uuid,
            'name' => 'Rahima Akter',
            'email' => $this->student->email,
        ]
        && $request['data']['course']['id'] === $course->uuid);

    $delivery = WebhookDelivery::firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Succeeded)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->response_status)->toBe(200)
        ->and($endpoint->fresh()->last_delivered_at)->not->toBeNull();
});

it('shapes the envelope the same way for every topic', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    WebhookEndpoint::factory()->listeningTo([WebhookTopic::EnrollmentSuspended])->create();

    EnrollmentSuspended::dispatch(webhookEnrolment(), 'Payment disputed');

    $payload = WebhookDelivery::firstOrFail()->payload();

    expect($payload)->toHaveKeys(['id', 'type', 'created_at', 'academy', 'data'])
        ->and($payload['type'])->toBe('enrollment.suspended')
        ->and($payload['academy']['id'])->toBe(tenancy()->tenant->getTenantKey())
        ->and($payload['data']['reason'])->toBe('Payment disputed')
        ->and($payload['data']['course']['title'])->toBe('Close Reading');
});

/* ---------------------------------------------------------- who hears */

it('sends nothing, and writes nothing, when nobody is listening', function (): void {
    Http::fake();
    WebhookEndpoint::factory()->listeningTo([WebhookTopic::PaymentCaptured])->create();
    WebhookEndpoint::factory()->disabled()->create(); // enrolments, but switched off

    CourseEnrolled::dispatch(webhookEnrolment());

    Http::assertNothingSent();
    expect(WebhookDelivery::count())->toBe(0);
});

/* One event is one id — a receiver with two endpoints can deduplicate. */
it('gives every endpoint the same event id and the same bytes', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    WebhookEndpoint::factory()->count(2)->create();

    CourseEnrolled::dispatch(webhookEnrolment());

    $deliveries = WebhookDelivery::all();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(1)
        ->and($deliveries->pluck('body')->unique())->toHaveCount(1);
});

/* ------------------------------------------------------------ failing */

it('retries on the backoff schedule, then gives up', function (): void {
    Http::fake(['*' => Http::response('down for maintenance', 503)]);
    WebhookEndpoint::factory()->create();

    CourseEnrolled::dispatch(webhookEnrolment());

    $delivery = WebhookDelivery::firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('The receiver answered 503.')
        ->and($delivery->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(55)->toBeLessThan(65);

    foreach (range(2, 8) as $_) {
        retryDelivery($delivery);
    }

    $delivery->refresh();

    expect($delivery->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->attempts)->toBe(8)
        ->and($delivery->endpoint->consecutive_failures)->toBe(1)
        // One dead delivery is not a dead endpoint.
        ->and($delivery->endpoint->is_active)->toBeTrue();

    Http::assertSentCount(8);
});

it('switches an endpoint off after enough deliveries in a row fail completely', function (): void {
    config(['orbito.webhooks.max_attempts' => 1, 'orbito.webhooks.disable_after_failures' => 2]);
    Http::fake(['*' => Http::response('nope', 500)]);
    $endpoint = WebhookEndpoint::factory()->create();

    CourseEnrolled::dispatch(webhookEnrolment());
    expect($endpoint->fresh()->is_active)->toBeTrue();

    // A second learner: one person cannot hold two enrolments on one course.
    CourseEnrolled::dispatch(webhookEnrolment(User::factory()->create()));

    $endpoint->refresh();

    expect($endpoint->is_active)->toBeFalse()
        ->and($endpoint->disabled_reason)->toContain('2 deliveries in a row');
});

it('forgives past failures once a delivery gets through', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $endpoint = WebhookEndpoint::factory()->create(['consecutive_failures' => 4]);

    CourseEnrolled::dispatch(webhookEnrolment());

    expect($endpoint->fresh()->consecutive_failures)->toBe(0);
});

/* An administrator who switches an endpoint off means "stop sending there". */
it('abandons queued retries once the endpoint is switched off', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);
    $endpoint = WebhookEndpoint::factory()->create();

    CourseEnrolled::dispatch(webhookEnrolment());
    $endpoint->update(['is_active' => false]);

    $delivery = WebhookDelivery::firstOrFail();
    retryDelivery($delivery);

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
        ->and($delivery->fresh()->error)->toContain('switched off')
        ->and($endpoint->fresh()->consecutive_failures)->toBe(0);
    Http::assertSentCount(1);
});

/* ------------------------------------------------------------ the guard */

/* A redirect is how a public URL bounces a request onto an internal one. */
it('does not follow a redirect', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
    WebhookEndpoint::factory()->create();

    CourseEnrolled::dispatch(webhookEnrolment());

    expect(WebhookDelivery::firstOrFail()->error)->toContain('redirects are not followed');
    Http::assertSentCount(1);
});

/* Approved when saved; re-pointed at the database later. */
it('checks the address again at send time', function (): void {
    Http::fake();
    WebhookEndpoint::factory()->create();
    $this->dns->point('hooks.example.com', '10.0.0.7');

    CourseEnrolled::dispatch(webhookEnrolment());

    Http::assertNothingSent();
    expect(WebhookDelivery::firstOrFail()->error)->toContain('private or internal');
});

/* ------------------------------------------------ never into the request */

it('never throws into the request that fired the event', function (): void {
    WebhookEndpoint::factory()->create();

    $queued = app(QueueWebhookEvent::class)->handle(
        WebhookTopic::EnrollmentCreated,
        fn (): array => throw new RuntimeException('a payload that cannot be built'),
    );

    expect($queued)->toBe(0)
        ->and(WebhookDelivery::count())->toBe(0);
});
