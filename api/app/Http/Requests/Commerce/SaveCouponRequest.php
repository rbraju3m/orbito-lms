<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The whole coupon, for create and replace alike (`SaveCoupon`).
 *
 * Money needs a currency: a fixed amount and a minimum spend are both
 * refused without one. A percent coupon with no minimum works in any.
 */
final class SaveCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $existing = $this->route('coupon');

        return [
            // Letters, digits, - and _: something a person can read aloud and
            // type on a phone. Case is ignored (Coupon::normalise).
            'code' => [
                'required', 'string', 'min:3', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('coupons', 'code')->ignore($existing instanceof Coupon ? $existing->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', Rule::enum(DiscountType::class)],
            'percent_off' => ['required_if:discount_type,percent', 'nullable', 'integer', 'between:1,100'],
            'amount_off_minor' => ['required_if:discount_type,fixed', 'nullable', 'integer', 'min:1'],
            'currency' => [
                'required_if:discount_type,fixed', 'required_with:min_subtotal_minor',
                'nullable', 'string', 'size:3', 'alpha',
            ],
            'applies_to_all' => ['required', 'boolean'],
            'product_ids' => ['required_if:applies_to_all,false', 'array', 'max:200'],
            'product_ids.*' => ['string', 'distinct'],
            'min_subtotal_minor' => ['nullable', 'integer', 'min:1'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'max_redemptions_per_user' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** Every product id must name a real product: an id that merely looks right is not a target. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('applies_to_all') || $validator->errors()->has('product_ids')) {
                return;
            }

            $ids = $this->productUuids();

            if ($ids === []) {
                $validator->errors()->add('product_ids', 'Pick at least one product, or apply the coupon to everything.');

                return;
            }

            if (Product::query()->whereIn('uuid', $ids)->where('status', ProductStatus::Active)->count() !== count($ids)) {
                $validator->errors()->add('product_ids', 'One of those products is not for sale.');
            }
        });
    }

    /**
     * @return array{code: string, description: string|null, discount_type: DiscountType, percent_off: int|null,
     *     amount_off_minor: int|null, currency: string|null, applies_to_all: bool, min_subtotal_minor: int|null,
     *     max_redemptions: int|null, max_redemptions_per_user: int|null, starts_at: string|null,
     *     ends_at: string|null, is_active: bool}
     */
    public function coupon(): array
    {
        return [
            'code' => (string) $this->string('code'),
            'description' => $this->filled('description') ? (string) $this->string('description') : null,
            'discount_type' => DiscountType::from((string) $this->string('discount_type')),
            'percent_off' => $this->filled('percent_off') ? $this->integer('percent_off') : null,
            'amount_off_minor' => $this->filled('amount_off_minor') ? $this->integer('amount_off_minor') : null,
            'currency' => $this->filled('currency') ? strtoupper((string) $this->string('currency')) : null,
            'applies_to_all' => $this->boolean('applies_to_all'),
            'min_subtotal_minor' => $this->filled('min_subtotal_minor') ? $this->integer('min_subtotal_minor') : null,
            'max_redemptions' => $this->filled('max_redemptions') ? $this->integer('max_redemptions') : null,
            'max_redemptions_per_user' => $this->filled('max_redemptions_per_user') ? $this->integer('max_redemptions_per_user') : null,
            'starts_at' => $this->filled('starts_at') ? (string) $this->string('starts_at') : null,
            'ends_at' => $this->filled('ends_at') ? (string) $this->string('ends_at') : null,
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /** @return list<int> product ids for the uuids sent */
    public function productIds(): array
    {
        if ($this->boolean('applies_to_all')) {
            return [];
        }

        return Product::query()->whereIn('uuid', $this->productUuids())->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    /** @return list<string> */
    private function productUuids(): array
    {
        /** @var list<string> */
        return array_values(array_filter(
            (array) $this->input('product_ids', []),
            static fn (mixed $id): bool => is_string($id),
        ));
    }
}
