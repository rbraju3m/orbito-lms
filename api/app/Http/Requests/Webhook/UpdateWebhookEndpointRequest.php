<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhook;

use App\Domain\Webhook\Enums\WebhookTopic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A partial update: what is not sent is not changed. */
final class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['sometimes', 'string', 'max:2048', 'url'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(WebhookTopic::subscribable())],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{url?: string, events?: list<string>, description?: string|null, is_active?: bool} */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('url')) {
            $changes['url'] = (string) $this->string('url');
        }

        if ($this->has('events')) {
            /** @var list<string> $events */
            $events = array_values($this->array('events'));
            $changes['events'] = $events;
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $changes['description'] = is_string($description) && $description !== '' ? $description : null;
        }

        if ($this->has('is_active')) {
            $changes['is_active'] = $this->boolean('is_active');
        }

        return $changes;
    }
}
