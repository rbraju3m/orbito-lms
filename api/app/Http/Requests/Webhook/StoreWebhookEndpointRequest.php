<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Domain\Webhook\Enums\WebhookTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whether the URL is SAFE is not a validation rule: it depends on what the
 * host resolves to, so `WebhookTarget` answers it in the Action and says why.
 * This checks only that it is a URL at all.
 */
final class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', 'url'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            // `ping` is not subscribable: it is sent on request, never on an event.
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookTopic::subscribable())],
        ];
    }

    /** @return list<string> */
    public function events(): array
    {
        /** @var list<string> */
        return array_values($this->array('events'));
    }
}
