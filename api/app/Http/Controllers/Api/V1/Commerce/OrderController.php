<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Http\Resources\Commerce\OrderResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class OrderController
{
    /**
     * The caller's own orders — or every order, for staff who hold
     * `order.view.any`.
     *
     * Filtered by what the reader may open rather than by a query parameter:
     * a row that 403s when clicked is a bug, not a permission check (Phase 8).
     */
    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('items')
            ->unless(
                $request->user()->hasPermission('order.view.any'),
                fn ($query) => $query->where('user_id', $request->user()->id),
            )
            // placed_at has second precision, so the id is the tiebreak that
            // stops a row appearing on two pages or on none (Phase 8).
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return ApiResponse::ok(OrderResource::collection($orders));
    }

    public function show(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);

        return ApiResponse::ok(OrderResource::make($order->load(['items', 'refunds' => fn ($query) => $query->latest('id')])));
    }

    private function perPage(Request $request): int
    {
        return min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );
    }
}
