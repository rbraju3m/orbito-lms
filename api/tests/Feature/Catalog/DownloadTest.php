<?php

declare(strict_types=1);

use App\Domain\Analytics\Actions\BuildDailyRollups;
use App\Domain\Analytics\Models\DailyCourseStat;
use App\Domain\Analytics\Models\DailyPlatformStat;
use App\Domain\Catalog\Actions\ChangeDownloadStatus;
use App\Domain\Catalog\Actions\CreateDownload;
use App\Domain\Catalog\Data\DownloadData;
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
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Domain\Commerce\Models\Product;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Enums\MediaCollection;
use App\Domain\Media\Enums\MediaStatus;
use App\Domain\Media\Models\Media;
use App\Domain\Platform\Enums\SubscriptionStatus;
use App\Domain\Platform\Enums\UsageMetric;
use App\Domain\Platform\Models\Plan;
use App\Domain\Platform\Models\Subscription;
use App\Domain\Platform\Support\UsageCounters;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Digital downloads — see docs/DOWNLOADS.md.
 *
 * A download is a LIVE file an academy sells. Buying one grants the right to
 * fetch it, never an enrolment; fetching mints a fresh 15-minute signed link
 * every time; and a file somebody paid for can be neither deleted from under
 * them nor taken back by archiving.
 */

beforeEach(function (): void {
    seedRegistry();
    // Listeners and uploads write real bytes; fake the disks up front.
    Storage::fake('private');
    Storage::fake('public');

    $this->currency = strtoupper((string) config('orbito.currency.base'));
    $this->admin = userWithRole(RoleKey::Admin);
    $this->learner = User::factory()->withRole(RoleKey::Student)->create();
    $this->instructor = User::factory()->instructor()->create();

    PaymentGatewayAccount::create([
        'gateway' => Gateway::Fake,
        'credentials' => ['key' => 'sk_test_download'],
        'webhook_secret' => 'whsec_download',
        'is_active' => true,
    ]);
});

/** A ready PDF in the download collection, with real bytes behind it. */
function dlFile(User $owner, MediaStatus $status = MediaStatus::Ready): Media
{
    $media = Media::factory()->forCollection(MediaCollection::Download)->ownedBy($owner)->create([
        'path' => 'download/2026/09/'.uniqid('', true).'.pdf',
        'original_name' => 'workbook.pdf',
        'mime' => 'application/pdf',
        'extension' => 'pdf',
        'size_bytes' => 2048,
        'width' => null,
        'height' => null,
        'status' => $status,
    ]);

    Storage::disk('private')->put($media->path, '%PDF-1.4 the workbook');

    return $media;
}

/** A download made the way the studio makes one. Paid at $minor, or free when null. */
function dlDraft(User $admin, string $currency, ?int $minor = 1500, ?Media $file = null): Download
{
    $download = app(CreateDownload::class)->handle(new DownloadData(
        title: 'The complete workbook',
        description: str_repeat('Everything you need, in one file. ', 3),
        mediaId: ($file ?? dlFile($admin))->id,
        pricingModel: $minor === null ? 'free' : 'one_time',
    ));

    if ($minor !== null) {
        app(SetProductPrice::class)->handle(
            app(SyncDownloadProduct::class)->handle($download),
            $currency,
            $minor,
        );
    }

    return $download->fresh() ?? $download;
}

function dlPublished(User $admin, string $currency, ?int $minor = 1500): Download
{
    return app(ChangeDownloadStatus::class)->handle(
        dlDraft($admin, $currency, $minor),
        DownloadStatus::Published,
    );
}

/** Pays for one product outright, through the real capture path. */
function dlCheckout(User $user, Product $product, string $currency): Order
{
    $cart = Cart::create(['user_id' => $user->id, 'currency' => $currency]);
    $cart->items()->create(['product_id' => $product->id]);

    $order = app(PlaceOrder::class)->handle($user, $cart->load('items.product.prices'));
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

function dlProduct(Download $download): Product
{
    return Product::query()
        ->where('purchasable_type', 'download')
        ->where('purchasable_id', $download->id)
        ->firstOrFail();
}

/* ------------------------------------------------------------ publishing */

it('refuses to publish a download with no file', function (): void {
    $download = Download::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/downloads/{$download->uuid}/publish")
        ->assertStatus(422);

    expect($response)->toBeApiError('download_not_publishable')
        ->and(collect($response->json('error.details'))->pluck('code'))->toContain('file_attached')
        // Reported ONCE: a missing file is not also a "not ready" file.
        ->and(collect($response->json('error.details'))->pluck('code'))->not->toContain('file_ready');
});

it('refuses to publish while the file is still uploading', function (): void {
    $download = dlDraft($this->admin, $this->currency, 1500, dlFile($this->admin, MediaStatus::Pending));

    $codes = collect(
        $this->actingAs($this->admin)
            ->postJson("/api/v1/studio/downloads/{$download->uuid}/publish")
            ->assertStatus(422)
            ->json('error.details')
    )->pluck('code');

    expect($codes)->toContain('file_ready');
});

it('refuses to publish a paid download with no price', function (): void {
    $download = app(CreateDownload::class)->handle(new DownloadData(
        title: 'An unpriced workbook',
        description: str_repeat('A description long enough to pass. ', 3),
        mediaId: dlFile($this->admin)->id,
        pricingModel: 'one_time',
    ));

    $codes = collect(
        $this->actingAs($this->admin)
            ->postJson("/api/v1/studio/downloads/{$download->uuid}/publish")
            ->assertStatus(422)
            ->json('error.details')
    )->pluck('code');

    expect($codes)->toContain('price_configured');
});

it('publishes a complete download and makes its product sellable', function (): void {
    $download = dlDraft($this->admin, $this->currency, 1500);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/studio/downloads/{$download->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect(dlProduct($download)->status)->toBe(ProductStatus::Active);
});

/* A free download has no product at all — exactly like a free course. */
it('publishes a free download with no product and no price', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);

    expect($download->status)->toBe(DownloadStatus::Published)
        ->and(Product::where('purchasable_type', 'download')->where('purchasable_id', $download->id)->exists())
        ->toBeFalse();
});

/* -------------------------------------------------------- buying, claiming */

/*
 * `CapturePayment` ends in `default => null`. Before this slice a paid
 * download line would have taken the money and granted nothing, silently.
 */
it('grants a paid download once the payment is captured', function (): void {
    $download = dlPublished($this->admin, $this->currency, 1500);

    $order = dlCheckout($this->learner, dlProduct($download), $this->currency);

    $grant = DownloadGrant::where('download_id', $download->id)->where('user_id', $this->learner->id)->first();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($grant)->not->toBeNull()
        ->and($grant->source)->toBe(DownloadSource::Purchase)
        ->and($grant->order_id)->toBe($order->id);
});

it('refuses to sell a download the buyer already owns', function (): void {
    $download = dlPublished($this->admin, $this->currency, 1500);
    dlCheckout($this->learner, dlProduct($download), $this->currency);

    $cart = Cart::create(['user_id' => $this->learner->id, 'currency' => $this->currency]);
    $cart->items()->create(['product_id' => dlProduct($download)->id]);

    expect(fn () => app(PlaceOrder::class)->handle($this->learner, $cart->load('items.product.prices')))
        ->toThrow(CheckoutRejected::class);
});

it('lets a member claim a free download, and a second claim changes nothing', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);

    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")
        ->assertOk()
        ->assertJsonPath('data.can_fetch', true);

    expect(DownloadGrant::where('download_id', $download->id)->count())->toBe(1);
});

it('refuses to hand a paid download over for free', function (): void {
    $download = dlPublished($this->admin, $this->currency, 1500);

    expect($this->actingAs($this->learner)
        ->postJson("/api/v1/downloads/{$download->slug}/claim")
        ->assertStatus(409))
        ->toBeApiError('download_requires_payment');

    expect(DownloadGrant::count())->toBe(0);
});

/* --------------------------------------------------------------- fetching */

it('mints a fresh signed link for an owner', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    $response = $this->actingAs($this->learner)
        ->getJson("/api/v1/downloads/{$download->slug}/file")
        ->assertOk();

    expect($response->json('data.url'))->toContain('signature=')
        ->and($response->json('data.expires_at'))->not->toBeNull();
});

/* 423, not 403: a non-buyer could legitimately get in, and meta says how. */
it('locks a paid download for a non-buyer and says what to buy', function (): void {
    $download = dlPublished($this->admin, $this->currency, 1500);

    $response = $this->actingAs($this->learner)
        ->getJson("/api/v1/downloads/{$download->slug}/file")
        ->assertStatus(423);

    expect($response)->toBeApiError('download_locked')
        ->and($response->json('error.meta.product_id'))->toBe(dlProduct($download)->uuid)
        ->and($response->json('error.meta.pricing_model'))->toBe('one_time');
});

it('refuses a revoked grant', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    DownloadGrant::query()->update(['revoked_at' => now()]);

    $this->actingAs($this->learner)
        ->getJson("/api/v1/downloads/{$download->slug}/file")
        ->assertStatus(423)
        ->assertJsonPath('error.details.0.code', 'grant_revoked');
});

it('streams the file behind a signed link and refuses one that was altered', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    $url = $this->actingAs($this->learner)->getJson("/api/v1/downloads/{$download->slug}/file")->json('data.url');

    $this->get($url)->assertOk()->assertDownload('workbook.pdf');

    // The signature is the whole credential on this route (ADR-09).
    $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))
        ->assertForbidden();
});

/* Archiving takes it off sale — never out of an owner's hands. */
it('keeps an archived download fetchable by its owner and hidden from everybody else', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    app(ChangeDownloadStatus::class)->handle($download, DownloadStatus::Archived);

    $this->actingAs($this->learner)->getJson("/api/v1/downloads/{$download->slug}/file")->assertOk();

    $stranger = User::factory()->withRole(RoleKey::Student)->create();
    $this->actingAs($stranger)->getJson("/api/v1/downloads/{$download->slug}")->assertNotFound();
});

/* A lapsed academy loses writes, never what its members already own. */
it('lets owners fetch in a lapsed academy while refusing new claims', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())->firstOrFail()
        ->forceFill(['status' => SubscriptionStatus::Expired, 'current_period_ends_at' => now()->subMonth()])
        ->save();

    $this->actingAs($this->learner)->getJson("/api/v1/downloads/{$download->slug}/file")->assertOk();

    $other = User::factory()->withRole(RoleKey::Student)->create();
    $this->actingAs($other)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertStatus(402);
});

it('hides a draft from members behind a 404, not a 403', function (): void {
    $download = dlDraft($this->admin, $this->currency);

    expect($this->actingAs($this->learner)->getJson("/api/v1/downloads/{$download->slug}")->assertNotFound())
        ->toBeApiError('not_found');
});

it('lists what the reader owns, archived included, revoked and unowned not', function (): void {
    $owned = dlPublished($this->admin, $this->currency, null);
    $archived = dlPublished($this->admin, $this->currency, null);
    $revoked = dlPublished($this->admin, $this->currency, null);
    dlPublished($this->admin, $this->currency, null); // never claimed

    foreach ([$owned, $archived, $revoked] as $download) {
        $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();
    }

    app(ChangeDownloadStatus::class)->handle($archived, DownloadStatus::Archived);
    DownloadGrant::where('download_id', $revoked->id)->update(['revoked_at' => now()]);

    $ids = collect($this->actingAs($this->learner)->getJson('/api/v1/downloads/mine')->assertOk()->json('data'))
        ->pluck('id');

    expect($ids)->toContain($owned->uuid)->toContain($archived->uuid)
        ->not->toContain($revoked->uuid)
        ->toHaveCount(2);
});

/* No list resource may carry a link: access is only checked at mint time. */
it('never puts a file url in a catalogue response', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);

    $body = $this->actingAs($this->learner)->getJson("/api/v1/downloads/{$download->slug}")->getContent()
        .$this->actingAs($this->learner)->getJson('/api/v1/downloads')->getContent();

    expect($body)->not->toContain('signature=')->not->toContain('/download?');
});

/* ------------------------------------------------------- the file itself */

/*
 * `DeleteMedia` SOFT-deletes and removes the bytes first, so no foreign key
 * could have guarded this. The check has to be in the action.
 */
it('refuses to delete a file that a download sells', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $file = $download->file()->firstOrFail();

    expect($this->actingAs($this->admin)->deleteJson("/api/v1/media/{$file->uuid}")->assertStatus(409))
        ->toBeApiError('media_in_use');

    Storage::disk('private')->assertExists($file->path);
});

it('lets only somebody who manages downloads upload into the download collection', function (): void {
    $upload = fn (User $user) => $this->actingAs($user)->postJson('/api/v1/media', [
        'collection' => 'download',
        'file' => UploadedFile::fake()->create('workbook.pdf', 40, 'application/pdf'),
    ]);

    $upload($this->instructor)->assertForbidden();
    $upload($this->learner)->assertForbidden();
    $upload($this->admin)->assertCreated();
});

/* A certificate a user could upload is a certificate a user could forge. */
it('lets nobody upload into the certificate collection', function (): void {
    $this->actingAs($this->admin)->postJson('/api/v1/media', [
        'collection' => 'certificate',
        'file' => UploadedFile::fake()->create('certificate.pdf', 40, 'application/pdf'),
    ])->assertForbidden();
});

it('refuses a file that belongs to somebody else', function (): void {
    $theirs = dlFile(userWithRole(RoleKey::Admin));

    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/downloads', ['title' => 'Borrowed file', 'media_id' => $theirs->id])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

/* --------------------------------------------------------------- deleting */

it('refuses to delete a download somebody owns', function (): void {
    $download = dlPublished($this->admin, $this->currency, null);
    $this->actingAs($this->learner)->postJson("/api/v1/downloads/{$download->slug}/claim")->assertOk();

    expect($this->actingAs($this->admin)->deleteJson("/api/v1/studio/downloads/{$download->uuid}")->assertStatus(409))
        ->toBeApiError('download_has_owners');
});

it('retires the product when an unowned download is deleted', function (): void {
    $download = dlPublished($this->admin, $this->currency, 1500);
    $product = dlProduct($download);

    $this->actingAs($this->admin)->deleteJson("/api/v1/studio/downloads/{$download->uuid}")->assertNoContent();

    expect($product->fresh()->status)->toBe(ProductStatus::Inactive);
});

/*
 * Regression for the bundles slice: deleting a published bundle left its
 * product ACTIVE, so a basket still holding it could be paid for and grant
 * nothing.
 */
it('retires the product when a published bundle is deleted', function (): void {
    $courses = [Course::factory()->published()->create(), Course::factory()->published()->create()];
    $bundle = Bundle::factory()->published()->containing($courses)->create();
    $product = app(SyncBundleProduct::class)->handle($bundle);

    expect($product->status)->toBe(ProductStatus::Active);

    $this->actingAs($this->admin)->deleteJson("/api/v1/studio/bundles/{$bundle->uuid}")->assertNoContent();

    expect($product->fresh()->status)->toBe(ProductStatus::Inactive);
});

/* Regression: the bundle requests shipped checking `exists` alone (§10). */
it('refuses a bundle cover that belongs to somebody else', function (): void {
    $theirs = Media::factory()->create(); // another user's course_thumbnail

    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/bundles', ['title' => 'Borrowed cover', 'thumbnail_media_id' => $theirs->id])
        ->assertStatus(422);
});

/* ------------------------------------------------------------- plan limit */

it('refuses the download that would exceed the plan', function (): void {
    $counters = app(UsageCounters::class);
    $plan = Plan::query()
        ->whereKey(Subscription::where('tenant_id', tenancy()->tenant->getTenantKey())->value('plan_id'))
        ->firstOrFail();

    $plan->forceFill(['limits' => ['max_downloads' => $counters->get(UsageMetric::Downloads)]])->save();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/downloads', ['title' => 'One too many'])
        ->assertStatus(402);

    expect($response)->toBeApiError('plan_limit_reached')
        ->and($response->json('error.meta.metric'))->toBe('downloads');
});

/* -------------------------------------------------------------- reporting */

/*
 * A download has no course, so without its own line the platform total
 * silently stops equalling the sum of its parts. With it, it adds up.
 */
it('reports download revenue so courses plus downloads equal the platform total', function (): void {
    $course = courseWithCurriculum(
        Course::factory()->published()->create(['pricing_model' => PricingModel::OneTime]),
        [1],
    );
    app(SetProductPrice::class)->handle(app(SyncCourseProduct::class)->handle($course), $this->currency, 4000);

    $download = dlPublished($this->admin, $this->currency, 1500);

    dlCheckout($this->learner, $course->fresh('product')->product, $this->currency);
    dlCheckout($this->learner, dlProduct($download), $this->currency);

    app(BuildDailyRollups::class)->handle(CarbonImmutable::now()->startOfDay());

    $platform = DailyPlatformStat::query()->firstOrFail();
    $courses = (int) DailyCourseStat::query()->sum('revenue_minor');

    expect($platform->download_revenue_minor)->toBe(1500)
        ->and($platform->revenue_minor)->toBe(5500)
        ->and($courses + $platform->download_revenue_minor)->toBe($platform->revenue_minor);
});

/* ---------------------------------------------------------- authorization */

it('denies the whole authoring surface to an instructor', function (): void {
    $download = dlDraft($this->admin, $this->currency);

    $this->actingAs($this->instructor)->postJson('/api/v1/studio/downloads', ['title' => 'Mine now'])->assertForbidden();
    $this->actingAs($this->instructor)->getJson("/api/v1/studio/downloads/{$download->uuid}")->assertForbidden();
    $this->actingAs($this->instructor)
        ->patchJson("/api/v1/studio/downloads/{$download->uuid}", ['title' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->instructor)
        ->postJson("/api/v1/studio/downloads/{$download->uuid}/publish")->assertForbidden();
    $this->actingAs($this->instructor)
        ->putJson("/api/v1/studio/downloads/{$download->uuid}/price", [
            'currency' => $this->currency, 'amount_minor' => 100,
        ])->assertForbidden();
    $this->actingAs($this->instructor)
        ->deleteJson("/api/v1/studio/downloads/{$download->uuid}")->assertForbidden();
});

it('refuses to price a free download', function (): void {
    $download = dlDraft($this->admin, $this->currency, null);

    expect($this->actingAs($this->admin)
        ->putJson("/api/v1/studio/downloads/{$download->uuid}/price", [
            'currency' => $this->currency, 'amount_minor' => 900,
        ])
        ->assertStatus(422))
        ->toBeApiError('pricing_rejected');
});

it('rejects a title that is too short', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/v1/studio/downloads', ['title' => 'Hm'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
