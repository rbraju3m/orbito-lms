<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Actions\ChangeBundleStatus;
use App\Domain\Catalog\Actions\ChangeDownloadStatus;
use App\Domain\Catalog\Actions\CreateDownload;
use App\Domain\Catalog\Data\DownloadData;
use App\Domain\Catalog\Enums\BundleStatus;
use App\Domain\Catalog\Enums\DownloadSource;
use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;
use App\Domain\Catalog\Models\DownloadGrant;
use App\Domain\Commerce\Actions\CapturePayment;
use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Actions\SetProductPrice;
use App\Domain\Commerce\Actions\SyncBundleProduct;
use App\Domain\Commerce\Actions\SyncCourseProduct;
use App\Domain\Commerce\Actions\SyncDownloadProduct;
use App\Domain\Commerce\Data\WebhookEvent;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItemAllocation;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\RefundLineAllocation;
use App\Domain\Enrollment\Enums\EnrollmentSource;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
 * A bundle that holds downloads as well as courses (docs/BUNDLES.md §9).
 *
 * The fixture is two courses at 20.00 and 40.00 and a workbook at 10.00, in a
 * bundle sold for 50.01 — an amount that divides evenly across nothing, so
 * every split below has a remainder to hand out and a sum to get exactly right.
 */

beforeEach(function (): void {
    seedRegistry();
    Storage::fake('private');
    Storage::fake('public');

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_bundle_downloads'],
        'webhook_secret' => 'whsec_bundle_downloads',
        'is_active' => true,
    ]);

    $this->first = bdlCourse(2000, $this->currency);
    $this->second = bdlCourse(4000, $this->currency);
    $this->workbook = bdlDownload($this->admin, $this->currency, 1000);

    $this->bundle = bdlBundle($this->admin, [$this->first, $this->second], [$this->workbook], 5001, $this->currency);
});

function bdlCourse(int $minor, string $currency): Course
{
    $course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );

    app(SetProductPrice::class)->handle(app(SyncCourseProduct::class)->handle($course), $currency, $minor);

    return $course->fresh(['product.prices']) ?? $course;
}

/** Published, made the way the studio makes one. */
function bdlDownload(User $admin, string $currency, int $minor): Download
{
    $media = Media::factory()->forCollection(MediaCollection::Download)->ownedBy($admin)->create([
        'path' => 'download/2026/09/'.uniqid('', true).'.pdf',
        'mime' => 'application/pdf',
        'extension' => 'pdf',
    ]);
    Storage::disk('private')->put($media->path, '%PDF-1.4 workbook');

    $download = app(CreateDownload::class)->handle(new DownloadData(
        title: 'The companion workbook',
        description: str_repeat('Exercises for every lesson. ', 3),
        mediaId: $media->id,
        pricingModel: 'one_time',
    ));

    app(SetProductPrice::class)->handle(app(SyncDownloadProduct::class)->handle($download), $currency, $minor);

    return app(ChangeDownloadStatus::class)->handle($download->fresh() ?? $download, DownloadStatus::Published);
}

/**
 * Built through the studio API, priced and published through the Actions —
 * the application's own path, not a factory's (§ Phase 16: ask what builds
 * the row your tests assume).
 *
 * @param  list<Course>  $courses
 * @param  list<Download>  $downloads
 */
function bdlBundle(User $admin, array $courses, array $downloads, int $minor, string $currency): Bundle
{
    $uuid = test()->actingAs($admin)
        ->postJson('/api/v1/studio/bundles', [
            'title' => 'The course and its workbook',
            'description' => str_repeat('Both courses, and the workbook that goes with them. ', 2),
            'course_ids' => array_map(fn (Course $c): int => $c->id, $courses),
            'download_ids' => array_map(fn (Download $d): int => $d->id, $downloads),
        ])
        ->assertCreated()
        ->json('data.id');

    $bundle = Bundle::where('uuid', $uuid)->firstOrFail();

    app(SetProductPrice::class)->handle(app(SyncBundleProduct::class)->handle($bundle), $currency, $minor);

    return app(ChangeBundleStatus::class)->handle($bundle->fresh() ?? $bundle, BundleStatus::Published);
}

function bdlProduct(Bundle|Course|Download $purchasable): Product
{
    return Product::query()
        ->where('purchasable_type', $purchasable->getMorphClass())
        ->where('purchasable_id', $purchasable->id)
        ->firstOrFail();
}

function bdlCart(User $user, Product $product, string $currency): Cart
{
    $cart = Cart::create(['user_id' => $user->id, 'currency' => $currency]);
    $cart->items()->create(['product_id' => $product->id]);

    return $cart->load('items.product.prices');
}

/** Placed, paid through the test gateway, delivered. */
function bdlPaid(User $user, Product $product, string $currency): Order
{
    $order = app(PlaceOrder::class)->handle($user, bdlCart($user, $product, $currency));
    app(InitiatePayment::class)->handle($order, Gateway::Fake);

    $payment = $order->payments()->firstOrFail();
    app(CapturePayment::class)->handle($payment, new WebhookEvent(
        id: 'evt_'.$order->id,
        type: 'payment.captured',
        externalPaymentId: $payment->external_id,
        amountMinor: $payment->amount_minor,
        currency: $payment->currency,
        payload: [],
    ));

    return $order->refresh();
}

function bdlGrant(User $user, Download $download): ?DownloadGrant
{
    return DownloadGrant::where('download_id', $download->id)->where('user_id', $user->id)->first();
}

/* -------------------------------------------------------------- delivery */

it('grants each download in a bought bundle with source bundle, beside the enrolments', function (): void {
    $order = bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    $grant = bdlGrant($this->learner, $this->workbook);

    expect($grant)->not->toBeNull()
        ->and($grant->source)->toBe(DownloadSource::Bundle)
        ->and($grant->order_id)->toBe($order->id)
        ->and($grant->revoked_at)->toBeNull()
        ->and(Enrollment::where('user_id', $this->learner->id)->where('source', EnrollmentSource::Bundle)->count())->toBe(2);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/downloads/{$this->workbook->slug}/file")
        ->assertOk();
});

/* What you already had is not the bundle's to give — or to take back. */
it('leaves a download they already owned alone, through the sale and its refund', function (): void {
    $earlier = bdlPaid($this->learner, bdlProduct($this->workbook), $this->currency);

    $order = bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    expect(bdlGrant($this->learner, $this->workbook)->order_id)->toBe($earlier->id);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", ['amount_minor' => 5001, 'method' => 'gateway'])
        ->assertCreated();

    $grant = bdlGrant($this->learner, $this->workbook);

    expect($grant->revoked_at)->toBeNull()
        ->and($grant->source)->toBe(DownloadSource::Purchase);
});

it('revokes the bundle\'s downloads on a full refund', function (): void {
    $order = bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", ['amount_minor' => 5001, 'method' => 'gateway'])
        ->assertCreated();

    expect(bdlGrant($this->learner, $this->workbook)->revoked_at)->not->toBeNull();

    $this->actingAs($this->learner)
        ->getJson("/api/v1/downloads/{$this->workbook->slug}/file")
        ->assertStatus(423);
});

/* ----------------------------------------------------------------- overlap */

it('still sells a bundle whose courses are all owned while a download is not', function (): void {
    bdlPaid($this->learner, bdlProduct($this->first), $this->currency);
    bdlPaid($this->learner, bdlProduct($this->second), $this->currency);

    $order = app(PlaceOrder::class)->handle($this->learner, bdlCart($this->learner, bdlProduct($this->bundle), $this->currency));

    expect($order->total_minor)->toBe(5001);
});

it('refuses a bundle when every course AND every download is already owned', function (): void {
    bdlPaid($this->learner, bdlProduct($this->first), $this->currency);
    bdlPaid($this->learner, bdlProduct($this->second), $this->currency);
    bdlPaid($this->learner, bdlProduct($this->workbook), $this->currency);

    expect(fn () => app(PlaceOrder::class)->handle(
        $this->learner,
        bdlCart($this->learner, bdlProduct($this->bundle), $this->currency),
    ))->toThrow(CheckoutRejected::class);
});

/* ------------------------------------------------------------------ money */

it('allocates the line across courses and downloads, summing to it exactly', function (): void {
    $order = bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    $rows = OrderItemAllocation::query()->where('order_item_id', $order->items->sole()->id)->get();

    $byCourse = $rows->whereNotNull('course_id')->pluck('amount_minor', 'course_id')->all();
    $download = $rows->whereNotNull('download_id')->sole();

    // 5001 × 2000/7000 = 1428.86, × 4000/7000 = 2857.71, × 1000/7000 = 714.43.
    // Floors sum to 4999; the two spare units go to the largest fractions.
    expect($byCourse)->toBe([$this->first->id => 1429, $this->second->id => 2858])
        ->and($download->download_id)->toBe($this->workbook->id)
        ->and($download->course_id)->toBeNull()
        ->and($download->amount_minor)->toBe(714)
        ->and($rows->sum('amount_minor'))->toBe(5001);
});

it('reports the download\'s share as download revenue, so courses plus downloads equal the platform', function (): void {
    bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());

    $platform = DailyPlatformStat::query()->sole();
    $courses = DailyCourseStat::query()->pluck('revenue_minor', 'course_id')->map(fn ($v): int => (int) $v)->all();

    expect($platform->download_revenue_minor)->toBe(714)
        ->and($courses)->toBe([$this->first->id => 1429, $this->second->id => 2858])
        // No NULL-course row sneaking in as course 0.
        ->and(array_key_exists(0, $courses))->toBeFalse()
        ->and(array_sum($courses) + $platform->download_revenue_minor)->toBe($platform->revenue_minor);
});

it('splits a partial refund across courses and downloads, and a run of them lands on zero', function (): void {
    $order = bdlPaid($this->learner, bdlProduct($this->bundle), $this->currency);

    foreach ([1000, 2001, 2000] as $amount) {
        $this->actingAs($this->admin)
            ->postJson("/api/v1/admin/orders/{$order->uuid}/refunds", [
                'amount_minor' => $amount,
                'method' => 'external',
                'revoke_access' => false,
            ])
            ->assertCreated();
    }

    $given = RefundLineAllocation::query()->get();

    expect($given->whereNotNull('download_id')->sum('amount_minor'))->toBe(714)
        ->and($given->where('course_id', $this->first->id)->sum('amount_minor'))->toBe(1429)
        ->and($given->where('course_id', $this->second->id)->sum('amount_minor'))->toBe(2858);

    // The refund day nets to zero on every figure, and they still agree.
    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());
    $platform = DailyPlatformStat::query()->sole();

    expect($platform->revenue_minor)->toBe(0)
        ->and($platform->download_revenue_minor)->toBe(0)
        ->and((int) DailyCourseStat::query()->sum('revenue_minor'))->toBe(0);
});

/* ------------------------------------------------------------- authoring */

it('counts a course and a download as the two things a bundle needs', function (): void {
    $bundle = bdlBundle($this->admin, [$this->first], [$this->workbook], 2500, $this->currency);

    expect($bundle->status)->toBe(BundleStatus::Published);
});

it('refuses to publish a bundle holding an unpublished download, and names it', function (): void {
    $draft = app(CreateDownload::class)->handle(new DownloadData(
        title: 'Unfinished answers',
        description: str_repeat('Not ready yet. ', 5),
        pricingModel: 'free',
    ));

    $uuid = $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', [
            'title' => 'Not ready',
            'description' => str_repeat('A course and a file that is not out yet. ', 2),
            'course_ids' => [$this->first->id],
            'download_ids' => [$draft->id],
        ])
        ->json('data.id');
    $bundle = Bundle::where('uuid', $uuid)->firstOrFail();
    app(SetProductPrice::class)->handle(app(SyncBundleProduct::class)->handle($bundle), $this->currency, 1500);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/bundles/{$uuid}/publish")
        ->assertStatus(422);

    expect(json_encode($response->json()))->toContain('downloads_published')->toContain('Unfinished answers');
});

it('takes a published bundle back to draft when one of its downloads is unpublished', function (): void {
    app(ChangeDownloadStatus::class)->handle($this->workbook, DownloadStatus::Draft);

    expect($this->bundle->fresh()->status)->toBe(BundleStatus::Draft)
        ->and(bdlProduct($this->bundle)->status->isSellable())->toBeFalse();
});

it('refuses to delete a download that a bundle holds', function (): void {
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/studio/downloads/{$this->workbook->uuid}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'download_in_bundle');

    expect(Download::find($this->workbook->id))->not->toBeNull();
});

it('keeps the downloads when a patch sends only the courses', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/studio/bundles/{$this->bundle->uuid}", ['course_ids' => [$this->second->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data.courses')
        ->assertJsonPath('data.downloads.0.ref', $this->workbook->id);
});

it('rejects a download id that does not exist', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/studio/bundles/{$this->bundle->uuid}", ['download_ids' => [999_999]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    expect($this->bundle->fresh('downloads')->downloads->modelKeys())->toBe([$this->workbook->id]);
});

/* ------------------------------------------------------------- the reader */

it('shows a buyer the downloads, which they own, and what the parts cost', function (): void {
    bdlPaid($this->learner, bdlProduct($this->workbook), $this->currency);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/bundles/{$this->bundle->slug}")
        ->assertOk()
        ->assertJsonPath('data.downloads.0.ref', $this->workbook->id)
        ->assertJsonPath('data.owned_download_ids', [$this->workbook->id])
        ->assertJsonPath('data.owned_course_ids', [])
        ->assertJsonPath('data.parts_total_minor', 7000);
});

it('counts downloads on the catalogue card', function (): void {
    $this->actingAs($this->learner)
        ->getJson('/api/v1/bundles')
        ->assertOk()
        ->assertJsonPath('data.0.course_count', 2)
        ->assertJsonPath('data.0.download_count', 1);
});
