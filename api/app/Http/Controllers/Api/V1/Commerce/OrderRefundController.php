<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\RefundOrder;
use App\Domain\Commerce\Models\Order;
use App\Http\Requests\Commerce\RefundOrderRequest;
use App\Http\Resources\Commerce\RefundResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Giving money back on an order (`order.refund`). See docs/REFUNDS.md. */
final class OrderRefundController
{
    /**
     * 201 with the refund — `completed`, or `pending` if the provider accepted
     * it without settling. A provider refusal is a 503 `gateway_unavailable`
     * and the refund is kept as `failed`.
     */
    public function store(RefundOrderRequest $request, Order $order, RefundOrder $action): JsonResponse
    {
        Gate::authorize('refund', $order);

        $refund = $action->handle(
            $request->user(),
            $order,
            $request->integer('amount_minor'),
            $request->refundMethod(),
            $request->reason(),
            $request->revokeAccess(),
        );

        return ApiResponse::created(RefundResource::make($refund->refresh()));
    }
}
