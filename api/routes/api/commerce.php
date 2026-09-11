<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\PaymentGatewayController;
use App\Http\Controllers\Api\V1\Commerce\CartController;
use App\Http\Controllers\Api\V1\Commerce\CartCouponController;
use App\Http\Controllers\Api\V1\Commerce\CheckoutController;
use App\Http\Controllers\Api\V1\Commerce\CouponController;
use App\Http\Controllers\Api\V1\Commerce\OrderController;
use App\Http\Controllers\Api\V1\Commerce\OrderRefundController;
use App\Http\Controllers\Api\V1\Commerce\PaymentWebhookController;
use App\Http\Controllers\Api\V1\Commerce\RefundReportController;
use Illuminate\Support\Facades\Route;

/*
 * THE WEBHOOK. The only unauthenticated write in the system.
 *
 * It sits outside every group below because it must be outside all three of
 * their middlewares, and each omission is load-bearing:
 *
 *  - no `auth:sanctum`: the caller is a payment provider with no account;
 *  - no `tenant`: with no user there is nothing to resolve an academy from,
 *    so the academy is in the PATH and `tenant.path` opens it;
 *  - no `subscription`: the money has already moved. Refusing to record a
 *    capture because the academy's own bill is overdue would take a learner's
 *    payment and grant them nothing.
 *
 * `{tenant}` is attacker-controllable and that is fine: naming another academy
 * only means the signature is checked against a secret the caller does not
 * hold. See InitializeTenancyByPathTenant.
 *
 * Throttled per IP. A provider retrying is normal; a stranger enumerating
 * academy ids against it is not.
 */
Route::post('webhooks/payments/{gateway}/{tenant}', PaymentWebhookController::class)
    ->middleware(['throttle:webhook', 'tenant.path'])
    ->name('webhooks.payments');

/*
 * The learner's buying surface.
 *
 * `subscription` gates the writes here as everywhere else, so a lapsed academy
 * cannot take new money — which is the right way round: reading an order you
 * already placed keeps working, starting a new one does not.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
    Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::delete('cart/items/{cartItem}', [CartController::class, 'destroy'])->name('cart.items.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

    // Throttled: a code is a guessable secret, and this is where it is guessed.
    Route::post('cart/coupon', [CartCouponController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('cart.coupon.apply');
    Route::delete('cart/coupon', [CartCouponController::class, 'destroy'])->name('cart.coupon.remove');

    /*
     * Throttled: checkout writes an order and empties a basket, and a
     * double-submitted button should not be able to produce a row per click.
     * Same reasoning as the enrollment bulk endpoint.
     */
    Route::post('checkout', [CheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('checkout.store');

    Route::post('orders/{order}/pay', [CheckoutController::class, 'pay'])
        ->middleware('throttle:10,1')
        ->name('orders.pay');

    /*
     * There is deliberately NO `POST /orders/{order}/confirm`. A redirect back
     * from a provider proves nothing, so the API offers no way to claim it
     * happened (ADR-05).
     */
    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
});

/*
 * Refunds (`order.refund`). OUTSIDE the subscription gate on purpose: a lapsed
 * academy must still be able to give a learner their money back. Its own bill
 * is no reason to keep somebody else's. Throttled: each one moves money.
 */
Route::middleware(['auth:sanctum', 'tenant'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::post('orders/{order}/refunds', [OrderRefundController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('orders.refunds.store');

        // Refund reports the webhook left for a person (REFUNDS.md §6) — the
        // same gate, for the same reason: money that already moved still has
        // to be reconciled.
        Route::get('refund-reports', [RefundReportController::class, 'index'])
            ->name('refund-reports.index');
        Route::post('refund-reports/{paymentEvent}/resolve', [RefundReportController::class, 'resolve'])
            ->middleware('throttle:30,1')
            ->name('refund-reports.resolve');
    });

/*
 * An academy connecting its own gateway (ADR-13).
 *
 * Inside the subscription gate on purpose, unlike the platform admin surface:
 * this is the academy configuring how IT takes money, not the action that
 * fixes a lapsed subscription, so there is nothing here a lapsed academy needs
 * in order to recover.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])
    ->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('payment-gateways', [PaymentGatewayController::class, 'index'])
            ->name('gateways.index');
        Route::put('payment-gateways/{gateway}', [PaymentGatewayController::class, 'update'])
            ->name('gateways.update');
        Route::delete('payment-gateways/{gateway}', [PaymentGatewayController::class, 'destroy'])
            ->name('gateways.destroy');

        /*
         * Coupons (`coupon.manage`). `products` is declared BEFORE
         * `{coupon}` so it is never read as a coupon id (§ Phase 12).
         */
        Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
        Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
        Route::get('coupons/products', [CouponController::class, 'products'])->name('coupons.products');
        Route::get('coupons/{coupon}', [CouponController::class, 'show'])->name('coupons.show');
        Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
        Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');
    });
