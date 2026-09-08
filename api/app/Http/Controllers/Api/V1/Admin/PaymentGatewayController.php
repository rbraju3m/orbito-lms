<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Commerce\Actions\ConnectPaymentGateway;
use App\Domain\Commerce\Enums\Gateway;
use App\Domain\Commerce\Models\PaymentGatewayAccount;
use App\Http\Requests\Commerce\ConnectGatewayRequest;
use App\Http\Resources\Commerce\PaymentGatewayAccountResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An academy connecting its OWN payment provider (ADR-13).
 *
 * The platform is not the merchant and never holds these credentials on an
 * academy's behalf, which is why this lives in the tenant schema and why the
 * capability (`gateway.manage`) belongs to an academy admin rather than to a
 * platform operator.
 */
final class PaymentGatewayController
{
    /** Every gateway the platform supports, connected or not. */
    public function index(): JsonResponse
    {
        Gate::authorize('manage-gateways');

        $connected = PaymentGatewayAccount::query()->get()->keyBy(
            fn (PaymentGatewayAccount $account): string => $account->gateway->value,
        );

        $rows = collect(Gateway::cases())
            ->filter(fn (Gateway $gateway): bool => $gateway->isAvailable())
            ->map(fn (Gateway $gateway): array => isset($connected[$gateway->value])
                ? PaymentGatewayAccountResource::make($connected[$gateway->value])->resolve(request())
                : PaymentGatewayAccountResource::unconnected($gateway))
            ->values()
            ->all();

        return ApiResponse::ok($rows);
    }

    public function update(
        ConnectGatewayRequest $request,
        string $gateway,
        ConnectPaymentGateway $action,
    ): JsonResponse {
        Gate::authorize('manage-gateways');

        $resolved = Gateway::tryFrom($gateway);

        if ($resolved === null) {
            throw new NotFoundHttpException;
        }

        $account = $action->handle(
            gateway: $resolved,
            credentials: $request->credentials(),
            webhookSecret: $request->has('webhook_secret')
                ? (string) $request->string('webhook_secret')
                : null,
            isActive: $request->has('is_active') ? $request->boolean('is_active') : null,
            isTestMode: $request->has('is_test_mode') ? $request->boolean('is_test_mode') : null,
        );

        return ApiResponse::ok(PaymentGatewayAccountResource::make($account));
    }

    /**
     * Disconnecting.
     *
     * The row is deleted rather than deactivated: leaving encrypted
     * credentials behind for an account the academy has said it no longer uses
     * is a secret kept for no reason. Orders and payments already taken keep
     * their `gateway` column, which is a string, so history survives.
     */
    public function destroy(Request $request, string $gateway): JsonResponse
    {
        Gate::authorize('manage-gateways');

        $resolved = Gateway::tryFrom($gateway);

        if ($resolved === null) {
            throw new NotFoundHttpException;
        }

        PaymentGatewayAccount::query()->where('gateway', $resolved)->delete();

        return ApiResponse::noContent();
    }
}
