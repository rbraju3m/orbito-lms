<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\AddToCart;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\CartItem;
use App\Domain\Commerce\Models\Product;
use App\Http\Requests\Commerce\AddCartItemRequest;
use App\Http\Resources\Commerce\CartResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The learner's own basket. There is exactly one per learner, so none of these
 * routes take a cart id — taking one would only be an id to get wrong.
 */
final class CartController
{
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::ok(CartResource::make($this->cartFor($request)));
    }

    public function store(AddCartItemRequest $request, AddToCart $action): JsonResponse
    {
        $product = Product::query()
            ->where('uuid', $request->string('product_id'))
            ->firstOrFail();

        return ApiResponse::created(
            CartResource::make($action->handle($request->user(), $product)),
        );
    }

    /**
     * Removing a line.
     *
     * The binding is unscoped, so membership of the CALLER's basket is checked
     * here — otherwise any cart_item id in the academy would be deletable by
     * anyone. Same trap as the quiz-question binding in Phase 7.
     */
    public function destroy(Request $request, CartItem $cartItem): JsonResponse
    {
        $cart = Cart::query()->where('user_id', $request->user()->id)->first();

        if ($cart === null || $cartItem->cart_id !== $cart->id) {
            // 404, not 403: the caller has no business knowing the row exists.
            throw new NotFoundHttpException;
        }

        $cartItem->delete();

        return ApiResponse::ok(CartResource::make($cart->fresh()));
    }

    /** Empties the basket without deleting it. */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request);
        $cart->items()->delete();

        return ApiResponse::ok(CartResource::make($cart->fresh()));
    }

    /**
     * An unmade basket reads as an empty one.
     *
     * Returning 404 until something is added would make the shop's first
     * render an error, and every client would have to special-case it.
     */
    private function cartFor(Request $request): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $request->user()->id],
            ['currency' => strtoupper((string) config('orbito.currency.base'))],
        );
    }
}
