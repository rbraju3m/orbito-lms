<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Notification\NotificationPreferenceController;
use Illuminate\Support\Facades\Route;

/*
 * The inbox and its switches.
 *
 * `subscription` is applied like everywhere else, and costs nothing here on
 * purpose: it gates writes only, and marking a notification read is exempt
 * from nothing — a lapsed academy still lets people read and clear their own
 * inbox, because taking that away punishes the learners for the owner's
 * invoice (§ Multi-tenancy).
 */
Route::middleware(['auth:sanctum', 'tenant', 'subscription'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');

    /*
     * The badge. Polled, so it is deliberately its own route rather than a
     * page of the inbox thrown away for its meta.
     */
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unread-count');

    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');

    /*
     * Declared AFTER the two literal paths above: `{notification}` is a uuid
     * with no pattern constraint, so it would otherwise swallow
     * `unread-count`.
     */
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])
        ->name('notifications.destroy');

    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])
        ->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('notification-preferences.update');
});
