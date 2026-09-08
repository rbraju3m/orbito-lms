<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Domain\Notification\Models\Notification;
use App\Http\Resources\Notification\NotificationResource;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The inbox.
 *
 * No policy, and no `authorize()` call anywhere in this file — deliberately.
 * Every query starts from `mine()`, which is keyed on the caller's own id, so
 * there is no other person's notification for a policy to have an opinion
 * about. What that DOES require is that no query in here ever starts anywhere
 * else: an id from the request is used to narrow a set that is already the
 * caller's, never to look one up.
 *
 * A notification belonging to somebody else 404s rather than 403s. "This
 * exists but is not yours" is a fact about a stranger's inbox.
 */
final class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->mine($request);

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $notifications = $query
            ->orderByDesc('created_at')
            // created_at has second precision, so without a tiebreak a row
            // can appear on two pages or on none (§14).
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(
            NotificationResource::collection($notifications)
                // The badge, free, on a request the bell already made.
                ->additional(['meta' => ['unread_count' => $this->unread($request)]]),
        );
    }

    /**
     * The badge on its own.
     *
     * A bell that polls must not page the whole inbox to learn there is
     * nothing new — this is one indexed count on
     * `notifications_unread_index`.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok(['unread_count' => $this->unread($request)]);
    }

    /** Idempotent: marking a read notification read again is not an error. */
    public function markRead(Request $request, string $notification): JsonResponse
    {
        $row = $this->mine($request)->findOrFail($notification);

        if ($row->read_at === null) {
            $row->forceFill(['read_at' => now()])->save();
        }

        return ApiResponse::ok(NotificationResource::make($row));
    }

    /** The "clear the badge" button. */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->mine($request)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::ok(['unread_count' => 0]);
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->mine($request)->findOrFail($notification)->delete();

        return ApiResponse::noContent();
    }

    /**
     * The only place a query into this table begins.
     *
     * @return Builder<Notification>
     */
    private function mine(Request $request): Builder
    {
        $user = $request->user();

        return Notification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id);
    }

    private function unread(Request $request): int
    {
        return $this->mine($request)->whereNull('read_at')->count();
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
