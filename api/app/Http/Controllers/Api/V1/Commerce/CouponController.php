<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\DeleteCoupon;
use App\Domain\Commerce\Actions\SaveCoupon;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\ProductStatus;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\Product;
use App\Http\Requests\Commerce\SaveCouponRequest;
use App\Http\Resources\Commerce\CouponResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** An academy's coupons (`coupon.manage`). See docs/COUPONS.md. */
final class CouponController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Coupon::class);

        $coupons = $this->withUsage(Coupon::query())
            ->with('products')
            ->when($request->filled('q'), fn (Builder $query) => $query->where(
                'code',
                'like',
                '%'.Coupon::normalise((string) $request->string('q')).'%',
            ))
            ->latest('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(CouponResource::collection($coupons));
    }

    public function store(SaveCouponRequest $request, SaveCoupon $action): JsonResponse
    {
        Gate::authorize('create', Coupon::class);

        $coupon = $action->handle($request->user(), $request->coupon(), $request->productIds());

        return ApiResponse::created(CouponResource::make($this->reload($coupon)));
    }

    public function show(Coupon $coupon): JsonResponse
    {
        Gate::authorize('view', $coupon);

        return ApiResponse::ok(CouponResource::make($this->reload($coupon)));
    }

    /** A whole replacement — the form sends the full coupon. See SaveCoupon. */
    public function update(SaveCouponRequest $request, Coupon $coupon, SaveCoupon $action): JsonResponse
    {
        Gate::authorize('update', $coupon);

        $coupon = $action->handle($request->user(), $request->coupon(), $request->productIds(), $coupon);

        return ApiResponse::ok(CouponResource::make($this->reload($coupon)));
    }

    public function destroy(Coupon $coupon, DeleteCoupon $action): JsonResponse
    {
        Gate::authorize('delete', $coupon);

        $action->handle($coupon);

        return ApiResponse::noContent();
    }

    /**
     * What a coupon can be scoped to: everything currently for sale. Its own
     * small list so the picker needs no knowledge of courses, bundles and
     * downloads as separate catalogues.
     */
    public function products(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Coupon::class);

        $products = Product::query()
            ->where('status', ProductStatus::Active)
            ->when($request->filled('q'), fn (Builder $query) => $query->where(
                'title',
                'like',
                '%'.$request->string('q').'%',
            ))
            ->orderBy('title')
            ->paginate($this->perPage($request));

        return ApiResponse::ok($products->through(fn (Product $product): array => [
            'id' => $product->uuid,
            'title' => $product->title,
            'type' => $product->purchasable_type,
        ]));
    }

    /**
     * PAID redemptions, as a subquery on the same connection — one query for
     * the whole page rather than one per coupon.
     *
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    private function withUsage(Builder $query): Builder
    {
        return $query->withCount(['redemptions as paid_redemptions_count' => fn (Builder $redemptions) => $redemptions
            ->whereHas('order', fn (Builder $order) => $order->whereIn('status', OrderStatus::sales())),
        ]);
    }

    private function reload(Coupon $coupon): Coupon
    {
        return $this->withUsage(Coupon::query())->with('products')->findOrFail($coupon->id);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));
    }
}
