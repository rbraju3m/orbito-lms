<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Domain\Webhook\Actions\RedeliverWebhook;
use App\Domain\Webhook\Enums\DeliveryStatus;
use App\Domain\Webhook\Models\WebhookDelivery;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Http\Resources\Webhook\WebhookDeliveryResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** One endpoint's delivery log, and sending an event again. */
final class WebhookDeliveryController
{
    public function index(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        Gate::authorize('view', $endpoint);

        $perPage = min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));
        $status = DeliveryStatus::tryFrom((string) $request->string('status'));

        $deliveries = $endpoint->deliveries()
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::ok(WebhookDeliveryResource::collection($deliveries));
    }

    public function redeliver(
        WebhookEndpoint $endpoint,
        WebhookDelivery $delivery,
        RedeliverWebhook $action,
    ): JsonResponse {
        Gate::authorize('update', $endpoint);

        // Both bind globally by uuid, so membership is checked here: without
        // it, any delivery id in the academy is redeliverable through any
        // endpoint's URL (§ Patterns established in Phase 7).
        if ($delivery->endpoint_id !== $endpoint->id) {
            throw new NotFoundHttpException;
        }

        return ApiResponse::accepted(WebhookDeliveryResource::make($action->handle($delivery)->refresh()));
    }
}
