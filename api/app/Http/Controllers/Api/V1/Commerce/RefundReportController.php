<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\ResolveRefundReport;
use App\Domain\Commerce\Models\PaymentEvent;
use App\Http\Requests\Commerce\ResolveRefundReportRequest;
use App\Http\Resources\Commerce\RefundReportResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Refund reports the webhook left for a person (`order.refund`). See
 * docs/REFUNDS.md §6. Outside the subscription gate, like refunds: a lapsed
 * academy still has to reconcile money that has already moved.
 */
final class RefundReportController
{
    /** Only what is still open, newest first. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PaymentEvent::class);

        $perPage = min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));

        $reports = PaymentEvent::query()
            ->where('needs_attention', true)
            ->whereNull('resolved_at')
            ->with('payment.order')
            ->latest('received_at')
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::ok(RefundReportResource::collection($reports));
    }

    public function resolve(ResolveRefundReportRequest $request, PaymentEvent $paymentEvent, ResolveRefundReport $action): JsonResponse
    {
        Gate::authorize('resolve', $paymentEvent);

        // Only a report is resolvable; any other event is not on this screen.
        abort_unless($paymentEvent->needs_attention, 404);

        $event = $action->handle($request->user(), $paymentEvent, $request->note());

        return ApiResponse::ok(RefundReportResource::make($event->loadMissing('payment.order')));
    }
}
