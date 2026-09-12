<?php

declare(strict_types=1);

namespace App\Http\Requests\Live;

use App\Domain\Live\Enums\LiveProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLiveSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes against the course.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],

            'provider' => ['required', Rule::enum(LiveProvider::class)],
            /*
             * Required for a host-supplied provider and refused for the
             * others: a link typed in alongside "Zoom" would be silently
             * ignored, and the author would find out at seven o'clock that
             * the class is somewhere else.
             *
             * Required only when SCHEDULING. The stored link is withheld from
             * every reader until a session is joinable, so an edit cannot show
             * it — and one that had to re-paste it would be an edit nobody
             * could make. Left out on an edit, it stays (RescheduleLiveSession).
             */
            'join_url' => [
                Rule::requiredIf(fn (): bool => $this->isMethod('POST')
                    && $this->provider() === LiveProvider::Manual),
                Rule::prohibitedIf(fn (): bool => $this->provider() !== LiveProvider::Manual),
                'nullable',
                'url',
                'max:2000',
            ],

            'starts_at' => ['required', 'date'],
            // Compared in the Action, not here: the same rule has to hold for
            // a reschedule, and one definition beats two.
            'ends_at' => ['required', 'date'],
            // IANA, so the UI can say "7pm Dhaka" as well as the reader's own
            // time. `timezone:all` is Laravel's own list.
            'timezone' => ['sometimes', 'string', 'timezone:all'],

            'cohort_id' => ['sometimes', 'nullable', 'uuid', 'exists:cohorts,uuid'],
        ];
    }

    public function provider(): ?LiveProvider
    {
        return LiveProvider::tryFrom((string) $this->input('provider'));
    }
}
