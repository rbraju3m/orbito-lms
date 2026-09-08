<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Commerce;

use App\Domain\Commerce\Actions\HandleWebhook;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Exceptions\WebhookRejected;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The one endpoint that can turn an order into access.
 *
 * Four things about it are unlike every other route in the system, and each is
 * deliberate:
 *
 *  1. NO `auth:sanctum`. The caller is a payment provider and has no account.
 *  2. NO `subscription`. A lapsed academy's webhook must still be recorded —
 *     the money has already moved, and refusing it because a bill is overdue
 *     would take payment for a course and grant nothing.
 *  3. Tenancy comes from the PATH, not a user (`tenant.path`).
 *  4. It reports almost nothing. `WebhookRejected` is always a flat 400 with
 *     no detail, because the caller is either a provider that does not need
 *     one or somebody probing the endpoint who must not have one.
 *
 * The response body is for a human reading provider logs. What matters to the
 * provider is the status: 2xx means "stop retrying".
 */
final class PaymentWebhookController
{
    public function __invoke(Request $request, string $gateway, HandleWebhook $action): JsonResponse
    {
        $resolved = Gateway::tryFrom($gateway);

        // Refused before the action, so an unknown provider name never reaches
        // the factory and never reads the account table.
        if ($resolved === null) {
            throw WebhookRejected::unknownGateway();
        }

        $event = $action->handle($request, $resolved);

        /*
         * 200 for everything that got this far, INCLUDING an event we chose
         * not to act on — an unknown payment id, a duplicate delivery, a type
         * we do not handle. Those are all "received and settled" as far as the
         * provider is concerned; answering non-2xx would make it retry
         * something that will never succeed.
         */
        return ApiResponse::ok([
            'received' => true,
            'event_id' => $event->external_event_id,
            'processed' => $event->processed_at !== null,
        ]);
    }
}
