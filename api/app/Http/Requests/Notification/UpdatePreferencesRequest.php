<?php

declare(strict_types=1);

namespace App\Http\Requests\Notification;

use App\Domain\Notification\Enums\NotificationChannel;
use App\Domain\Notification\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Every switch written belongs to the caller — the controller passes
        // their own id and never reads one from the body. There is no other
        // person's preferences to authorize against, the same reasoning as
        // the wishlist.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * A LIST of changes, not the whole matrix. Sending the matrix back
             * makes every save a race: two tabs open, and the older one's copy
             * silently reverts whatever the newer one changed.
             */
            'preferences' => ['required', 'array', 'min:1', 'max:100'],
            'preferences.*.type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.channel' => [
                'required',
                Rule::enum(NotificationChannel::class),
                /*
                 * A locked channel is refused rather than ignored. Silently
                 * accepting a switch that does nothing is how a UI ends up
                 * showing a state the server does not hold.
                 */
                Rule::in(array_map(
                    fn (NotificationChannel $c): string => $c->value,
                    NotificationChannel::switchable(),
                )),
            ],
            'preferences.*.enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preferences.*.channel.in' => 'In-app notifications cannot be switched off.',
        ];
    }
}
