<?php

declare(strict_types=1);

namespace Database\Factories\Live;

use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductPrice;
use App\Domain\Live\Enums\WebinarStatus;
use App\Domain\Live\Models\LiveSession;
use App\Domain\Live\Models\Webinar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webinar>
 */
final class WebinarFactory extends Factory
{
    protected $model = Webinar::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = 'Open evening: '.fake()->words(2, true);

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::random(6),
            'title' => $title,
            'description' => 'An hour on how the course works.',
            'live_session_id' => null,
            'capacity' => null,
            'is_paid' => false,
            'status' => WebinarStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => WebinarStatus::Published]);
    }

    public function withCapacity(int $capacity): static
    {
        return $this->state(fn () => ['capacity' => $capacity]);
    }

    /**
     * A ticketed event, with the product and price the application would have
     * created for it.
     *
     * The flag ALONE is not a paid webinar — it is one that cannot be
     * published and cannot be bought — so this state mints what
     * `SyncWebinarProduct` and `SetProductPrice` mint, rather than leaving a
     * test to discover the difference. Sellable only once published, exactly
     * as the sync decides it.
     */
    public function paid(int $amountMinor = 2500, ?string $currency = null): static
    {
        return $this->afterCreating(function (Webinar $webinar) use ($amountMinor, $currency): void {
            $webinar->forceFill(['is_paid' => true])->save();

            $product = Product::query()->create([
                'purchasable_type' => $webinar->getMorphClass(),
                'purchasable_id' => $webinar->id,
                'title' => $webinar->title,
                'status' => $webinar->status->isOpen() ? ProductStatus::Active : ProductStatus::Inactive,
            ]);

            ProductPrice::query()->create([
                'product_id' => $product->id,
                'currency' => strtoupper($currency ?? (string) config('orbito.currency.base')),
                'amount_minor' => $amountMinor,
            ]);
        });
    }

    /**
     * The session it happens at — a live session with no course and no
     * cohort, which is what makes a webinar standalone.
     *
     * The default leaves it null, and that is the factory lying on purpose
     * (§ Traps that are still live): a webinar with no time is exactly the
     * thing `ChangeWebinarStatus` refuses to publish, and a test for that
     * needs to be able to build one. `CreateWebinar` always makes both.
     */
    public function withSession(): static
    {
        return $this->state(fn (): array => [
            'live_session_id' => LiveSession::factory()->create([
                'course_id' => null,
                'cohort_id' => null,
            ])->id,
        ]);
    }
}
