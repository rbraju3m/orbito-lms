<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhook;

use App\Domain\Webhook\Actions\CreateWebhookEndpoint;
use App\Domain\Webhook\Actions\RotateWebhookSecret;
use App\Domain\Webhook\Actions\SendTestWebhook;
use App\Domain\Webhook\Actions\UpdateWebhookEndpoint;
use App\Domain\Webhook\Enums\WebhookTopic;
use App\Domain\Webhook\Models\WebhookEndpoint;
use App\Http\Requests\Webhook\StoreWebhookEndpointRequest;
use App\Http\Requests\Webhook\UpdateWebhookEndpointRequest;
use App\Http\Resources\Webhook\WebhookDeliveryResource;
use App\Http\Resources\Webhook\WebhookEndpointResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An academy's outbound webhook endpoints (ADR-12). See docs/WEBHOOKS.md.
 */
final class WebhookEndpointController
{
    /** The endpoints, with every subscribable topic in `meta` for the picker. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', WebhookEndpoint::class);

        $perPage = min(max($request->integer('per_page', 20), 1), (int) config('orbito.pagination.max_per_page'));

        $endpoints = WebhookEndpoint::query()->latest('id')->paginate($perPage);

        return ApiResponse::ok(
            WebhookEndpointResource::collection($endpoints)->additional(['meta' => ['topics' => $this->topics()]]),
        );
    }

    /** The secret is in THIS response and no other. */
    public function store(StoreWebhookEndpointRequest $request, CreateWebhookEndpoint $action): JsonResponse
    {
        Gate::authorize('create', WebhookEndpoint::class);

        $created = $action->handle(
            $request->user(),
            (string) $request->string('url'),
            $request->events(),
            $request->filled('description') ? (string) $request->string('description') : null,
        );

        return ApiResponse::created([
            ...WebhookEndpointResource::make($created['endpoint'])->resolve($request),
            'secret' => $created['secret'],
        ]);
    }

    public function show(WebhookEndpoint $endpoint): JsonResponse
    {
        Gate::authorize('view', $endpoint);

        return ApiResponse::ok(WebhookEndpointResource::make($endpoint));
    }

    public function update(
        UpdateWebhookEndpointRequest $request,
        WebhookEndpoint $endpoint,
        UpdateWebhookEndpoint $action,
    ): JsonResponse {
        Gate::authorize('update', $endpoint);

        return ApiResponse::ok(WebhookEndpointResource::make($action->handle($endpoint, $request->changes())));
    }

    /** Its queued deliveries go with it — nothing more will be sent there. */
    public function destroy(WebhookEndpoint $endpoint): JsonResponse
    {
        Gate::authorize('delete', $endpoint);

        $endpoint->delete();

        return ApiResponse::noContent();
    }

    public function rotateSecret(Request $request, WebhookEndpoint $endpoint, RotateWebhookSecret $action): JsonResponse
    {
        Gate::authorize('update', $endpoint);

        $secret = $action->handle($endpoint);

        return ApiResponse::ok([
            ...WebhookEndpointResource::make($endpoint->refresh())->resolve($request),
            'secret' => $secret,
        ]);
    }

    /** 202: queued. The delivery log shows how the receiver answered. */
    public function test(WebhookEndpoint $endpoint, SendTestWebhook $action): JsonResponse
    {
        Gate::authorize('update', $endpoint);

        return ApiResponse::accepted(WebhookDeliveryResource::make($action->handle($endpoint)->refresh()));
    }

    /** @return list<array{value: string, label: string, group: string}> */
    private function topics(): array
    {
        return array_map(
            static fn (string $value): array => [
                'value' => $value,
                'label' => WebhookTopic::from($value)->label(),
                'group' => WebhookTopic::from($value)->group(),
            ],
            WebhookTopic::subscribable(),
        );
    }
}
