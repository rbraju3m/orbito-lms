<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\InitiatePayment;
use App\Domain\Commerce\Actions\PlaceOrder;
use App\Domain\Commerce\Exceptions\CheckoutRejected;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Commerce\Models\Order;
use App\Http\Requests\Commerce\PayOrderRequest;
use App\Http\Resources\Commerce\OrderResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Checkout, in two deliberate steps.
 *
 * `store` prices and freezes an order. `pay` hands that order to a provider.
 * They are separate requests because the price the learner agreed to must be
 * settled BEFORE a gateway is involved — and because a failed payment must
 * leave a re-payable order rather than an empty basket.
 *
 * Neither grants anything. There is no third endpoint that does: access
 * arrives only through the webhook (ADR-05).
 */
final class CheckoutController
{
    public function store(Request $request, PlaceOrder $action): JsonResponse
    {
        $cart = Cart::query()
            ->where('user_id', $request->user()->id)
            ->with('items.product.prices')
            ->first();

        if ($cart === null) {
            throw CheckoutRejected::emptyCart();
        }

        $order = $action->handle($request->user(), $cart);

        return ApiResponse::created(OrderResource::make($order->load('items')));
    }

    /**
     * Hands the order to a gateway and returns where to send the learner.
     *
     * The response carries no order status change the client should act on
     * beyond "go here" — and deliberately no confirm URL to come back to.
     */
    public function pay(PayOrderRequest $request, Order $order, InitiatePayment $action): JsonResponse
    {
        Gate::authorize('pay', $order);

        $handoff = $action->handle($order, $request->gateway());

        return ApiResponse::created([
            'order_id' => $order->refresh()->uuid,
            'order_status' => $order->status->value,
            'gateway' => $request->gateway()->value,
            'redirect_url' => $handoff->redirectUrl,
            'client_secret' => $handoff->clientSecret,
        ]);
    }
}
