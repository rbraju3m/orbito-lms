<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use App\Domain\Live\Enums\LiveProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a webinar, or editing one.
 *
 * A create carries the SESSION as well, because a webinar with no time has
 * nothing to attend and cannot be published — the session rules here are the
 * same ones `StoreLiveSessionRequest` states, for the same reasons.
 *
 * An edit carries only the webinar's own fields. Moving the time goes through
 * `PATCH /live-sessions/{id}`, which already resets the reminder and tells the
 * provider; a second path to those fields would be a second set of rules to
 * keep in step.
 */
final class StoreWebinarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes the capability.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        $rules = [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            // Null is UNCAPPED, which is not the same as no places left.
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            /*
             * Whether a place has to be bought. The PRICE is not here: it
             * hangs off a product, which does not exist until the webinar
             * does, so it is set through `PUT /webinars/{webinar}/price` —
             * the same split as a course and a download.
             */
            'is_paid' => ['sometimes', 'boolean'],
        ];

        if (! $creating) {
            return $rules;
        }

        return [
            ...$rules,
            'provider' => ['required', Rule::enum(LiveProvider::class)],
            // Required for a host-supplied provider and refused for the
            // others: a link typed in beside "Zoom" would be ignored, and the
            // author would find out at seven o'clock.
            'join_url' => [
                Rule::requiredIf(fn (): bool => $this->provider() === LiveProvider::Manual),
                Rule::prohibitedIf(fn (): bool => $this->provider() !== LiveProvider::Manual),
                'nullable',
                'url',
                'max:2000',
            ],
            'starts_at' => ['required', 'date'],
            // Compared in the Action, so a reschedule cannot disagree with a
            // create about what a valid window is.
            'ends_at' => ['required', 'date'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }

    public function provider(): ?LiveProvider
    {
        return LiveProvider::tryFrom((string) $this->input('provider'));
    }
}
