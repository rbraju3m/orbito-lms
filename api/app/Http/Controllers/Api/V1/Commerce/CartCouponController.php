<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\ApplyCoupon;
use App\Domain\Commerce\Actions\RemoveCoupon;
use App\Domain\Commerce\Models\Cart;
use App\Http\Requests\Commerce\ApplyCouponRequest;
use App\Http\Resources\Commerce\CartResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A coupon on the learner's own basket. Like the cart routes, none of these
 * take a cart id — there is only one basket per learner to address.
 */
final class CartCouponController
{
    /** 422 `coupon_rejected` with `meta.reason` when it does not apply now. */
    public function store(ApplyCouponRequest $request, ApplyCoupon $action): JsonResponse
    {
        return ApiResponse::ok(CartResource::make($action->handle($request->user(), $this->cartFor($request), $request->code())));
    }

    public function destroy(Request $request, RemoveCoupon $action): JsonResponse
    {
        return ApiResponse::ok(CartResource::make($action->handle($this->cartFor($request))));
    }

    /** An unmade basket reads as an empty one, as on the cart routes. */
    private function cartFor(Request $request): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['currency' => strtoupper((string) config('orbito.currency.base'))],
        );
    }
}
