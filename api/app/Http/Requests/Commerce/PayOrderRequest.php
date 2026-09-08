<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use App\Domain\Commerce\Enums\Gateway;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class PayOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the order.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * Validated against the enum, not against what the academy has
             * connected. An unconnected gateway is a 503 from
             * PaymentGatewayFactory — "we do not offer that" and "that is not
             * a payment method" are different answers, and the second one
             * should not depend on configuration.
             */
            'gateway' => ['required', 'string', new Enum(Gateway::class)],
        ];
    }

    public function gateway(): Gateway
    {
        return Gateway::from((string) $this->string('gateway'));
    }
}
