<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Webhook\WebhookDeliveryController;
use App\Http\Controllers\Api\V1\Webhook\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
 * Outbound webhooks (ADR-12) — an academy telling other systems what happens
 * in it. Inside the subscription gate, like payment gateways: configuring an
 * integration is not what a lapsed academy needs in order to recover.
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])
    ->prefix('admin/webhooks')->name('admin.webhooks.')->group(function (): void {
        Route::get('/', [WebhookEndpointController::class, 'index'])->name('index');
        Route::post('/', [WebhookEndpointController::class, 'store'])->name('store');
        Route::get('{endpoint}', [WebhookEndpointController::class, 'show'])->name('show');
        Route::patch('{endpoint}', [WebhookEndpointController::class, 'update'])->name('update');
        Route::delete('{endpoint}', [WebhookEndpointController::class, 'destroy'])->name('destroy');
        Route::post('{endpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret'])
            ->name('rotate');
        // Each one is an outbound request made on the academy's say-so.
        Route::post('{endpoint}/test', [WebhookEndpointController::class, 'test'])
            ->middleware('throttle:10,1')
            ->name('test');

        Route::get('{endpoint}/deliveries', [WebhookDeliveryController::class, 'index'])
            ->name('deliveries.index');
        Route::post('{endpoint}/deliveries/{delivery}/redeliver', [WebhookDeliveryController::class, 'redeliver'])
            ->middleware('throttle:30,1')
            ->name('deliveries.redeliver');
    });
