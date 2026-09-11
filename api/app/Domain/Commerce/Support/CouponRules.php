<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use App\Domain\Commerce\Enums\CouponRefusal;
use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CouponRejected;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * "Does this coupon apply to this basket, for this person, now — and for how
 * much?"
 *
 * One class, RENDERED by the cart (a preview and a reason) and ENFORCED by
 * `PlaceOrder` (inside its transaction, behind a lock on the coupon row), so
 * the basket and the checkout cannot disagree — `PublishChecklist` and
 * `SubmissionRules` again.
 *
 * Lines are passed in as `key => [product_id, amount_minor]`, priced by the
 * caller at the moment of asking; nothing here reads a price.
 *
 * **When a use counts.** A redemption is written when an order is placed. It
 * counts while that order is PAID, or unpaid and younger than the reservation
 * window; a cancelled, failed or abandoned order gives its use back simply by
 * being those things. Derived from the clock, never swept (§ Patterns
 * established in Phase 15). The one overshoot this allows: an order paid
 * AFTER its window, once somebody else has taken the freed use. The money has
 * moved by then, so it is honoured — bounded, and stated here.
 */
final class CouponRules
{
    private ?CouponRefusal $refusal = null;

    private string $message = '';

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<int, int> line key => discount */
    private array $discounts = [];

    /**
     * @param  array<int, array{product_id: int, amount_minor: int}>  $lines
     */
    public static function for(Coupon $coupon, int $userId, string $currency, array $lines): self
    {
        return new self($coupon, $userId, $currency, $lines, app(CouponDiscount::class));
    }

    /**
     * @param  array<int, array{product_id: int, amount_minor: int}>  $lines
     */
    public function __construct(
        private readonly Coupon $coupon,
        private readonly int $userId,
        private readonly string $currency,
        private readonly array $lines,
        private readonly CouponDiscount $calculator,
    ) {
        $this->evaluate();
    }

    public function applies(): bool
    {
        return $this->refusal === null;
    }

    public function refusal(): ?CouponRefusal
    {
        return $this->refusal;
    }

    public function message(): ?string
    {
        return $this->refusal === null ? null : $this->message;
    }

    /** @return array<int, int> line key => discount; empty when it does not apply */
    public function discounts(): array
    {
        return $this->discounts;
    }

    public function total(): int
    {
        return array_sum($this->discounts);
    }

    /** @throws CouponRejected */
    public function assertApplies(): void
    {
        if ($this->refusal !== null) {
            throw CouponRejected::because($this->refusal, $this->message, $this->meta);
        }
    }

    /**
     * Cheapest checks first; the two that query the redemption table last.
     */
    private function evaluate(): void
    {
        $coupon = $this->coupon;
        $now = Date::now();

        if (! $coupon->is_active) {
            $this->refuse(CouponRefusal::Inactive, "{$coupon->code} is not active.");

            return;
        }

        if ($coupon->starts_at !== null && $coupon->starts_at->isAfter($now)) {
            $this->refuse(
                CouponRefusal::NotStarted,
                "{$coupon->code} can be used from {$coupon->starts_at->toDayDateTimeString()}.",
                ['starts_at' => $coupon->starts_at->toIso8601String()],
            );

            return;
        }

        if ($coupon->ends_at !== null && ! $coupon->ends_at->isAfter($now)) {
            $this->refuse(CouponRefusal::Expired, "{$coupon->code} has expired.");

            return;
        }

        if ($coupon->currency !== null && $coupon->currency !== strtoupper($this->currency)) {
            $this->refuse(
                CouponRefusal::WrongCurrency,
                "{$coupon->code} is for baskets in {$coupon->currency}.",
                ['currency' => $coupon->currency],
            );

            return;
        }

        $eligible = $this->eligibleLines();

        if ($eligible === []) {
            $this->refuse(CouponRefusal::NothingEligible, "{$coupon->code} does not apply to anything in your basket.");

            return;
        }

        // On what the coupon applies to — a minimum spend on a scoped coupon
        // means spend on the scoped products, not on the whole basket.
        if ($coupon->min_subtotal_minor !== null && array_sum($eligible) < $coupon->min_subtotal_minor) {
            $this->refuse(
                CouponRefusal::BelowMinimum,
                "{$coupon->code} needs a spend of at least ".Money::format($coupon->min_subtotal_minor, (string) $coupon->currency).'.',
                ['min_subtotal_minor' => $coupon->min_subtotal_minor, 'currency' => $coupon->currency],
            );

            return;
        }

        if ($coupon->max_redemptions !== null && $this->liveRedemptions()->count() >= $coupon->max_redemptions) {
            $this->refuse(CouponRefusal::Exhausted, "{$coupon->code} has been used up.");

            return;
        }

        if (
            $coupon->max_redemptions_per_user !== null
            && $this->liveRedemptions()->where('user_id', $this->userId)->count() >= $coupon->max_redemptions_per_user
        ) {
            $this->refuse(CouponRefusal::AlreadyUsed, "You have already used {$coupon->code}.");

            return;
        }

        $value = $coupon->discount_type === DiscountType::Percent
            ? (int) $coupon->percent_off
            : (int) $coupon->amount_off_minor;

        $this->discounts = $this->calculator->split($coupon->discount_type, $value, $eligible) + array_map(
            static fn (): int => 0,
            $this->lines,
        );
    }

    /** @return array<int, int> line key => amount, for the lines this coupon covers */
    private function eligibleLines(): array
    {
        $scope = $this->coupon->applies_to_all
            ? null
            : $this->coupon->products()->pluck('products.id')->map(static fn (mixed $id): int => (int) $id)->all();

        $eligible = [];

        foreach ($this->lines as $key => $line) {
            if ($line['amount_minor'] > 0 && ($scope === null || in_array($line['product_id'], $scope, true))) {
                $eligible[$key] = $line['amount_minor'];
            }
        }

        return $eligible;
    }

    /** @return Builder<CouponRedemption> */
    private function liveRedemptions(): Builder
    {
        $window = Date::now()->subMinutes((int) config('orbito.coupons.reservation_minutes'));

        return CouponRedemption::query()
            ->where('coupon_id', $this->coupon->id)
            ->whereHas('order', fn (Builder $order) => $order->where(
                fn (Builder $q) => $q
                    // A sale — a fully REFUNDED order gives its use back.
                    ->whereIn('status', OrderStatus::sales())
                    ->orWhere(fn (Builder $pending) => $pending
                        ->whereIn('status', [OrderStatus::Pending, OrderStatus::AwaitingPayment])
                        ->where('placed_at', '>=', $window)),
            ));
    }

    /** @param  array<string, mixed>  $meta */
    private function refuse(CouponRefusal $refusal, string $message, array $meta = []): void
    {
        $this->refusal = $refusal;
        $this->message = $message;
        $this->meta = $meta;
        $this->discounts = [];
    }
}
