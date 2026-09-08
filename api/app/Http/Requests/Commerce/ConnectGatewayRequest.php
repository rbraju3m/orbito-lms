<?php

declare(strict_types=1);

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connecting an academy's own gateway (ADR-13).
 *
 * Everything is `sometimes`: this is a partial update, and an admin toggling
 * test mode must not have to re-type the secret key. `ConnectPaymentGateway`
 * keeps what it is not given.
 */
final class ConnectGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes the capability.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credentials' => ['sometimes', 'array', 'min:1'],
            // String values only. An array here would be serialised into the
            // encrypted blob and come back a shape the gateway cannot read.
            'credentials.*' => ['required', 'string', 'max:500'],
            'webhook_secret' => ['sometimes', 'string', 'min:8', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_test_mode' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string>|null */
    public function credentials(): ?array
    {
        if (! $this->has('credentials')) {
            return null;
        }

        /** @var array<string, string> $credentials */
        $credentials = $this->array('credentials');

        return $credentials;
    }
}
