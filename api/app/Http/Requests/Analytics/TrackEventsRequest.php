<?php

declare(strict_types=1);

namespace App\Http\Requests\Analytics;

use App\Domain\Analytics\Enums\EventName;
use App\Domain\Analytics\Enums\EventSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TrackEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /*
             * Always a batch, even of one. Two accepted shapes would be two
             * code paths for the same thing, and the batch is the one that
             * matters: a beacon fired after a spell offline carries several
             * events with their own timestamps.
             */
            'events' => ['required', 'array', 'min:1', 'max:'.config('orbito.analytics.max_batch')],

            /*
             * THE SECURITY BOUNDARY. A browser may only raise events the
             * server cannot see for itself — a view, an open, a search, an
             * abandoned basket. Everything else is a fact the server
             * established: a client that could post `payment_completed` would
             * be writing revenue into the dashboards without paying anybody.
             */
            'events.*.name' => ['required', Rule::in(EventName::clientRaisable())],

            /*
             * The client's own clock, and therefore not to be trusted with
             * anything. It is accepted so an offline batch lands in the right
             * day, and clamped in the controller: a device with a wrong year
             * must not be able to write into next month's report.
             */
            'events.*.occurred_at' => ['sometimes', 'date'],

            'events.*.session_id' => ['sometimes', 'nullable', 'uuid'],
            'events.*.course_id' => ['sometimes', 'nullable', 'uuid', 'exists:courses,uuid'],
            'events.*.course_item_id' => ['sometimes', 'nullable', 'uuid', 'exists:course_items,uuid'],

            // Free-form, but bounded: a JSON column is not a place to put a
            // document, and an unbounded one is a way to fill a disk.
            'events.*.properties' => ['sometimes', 'array', 'max:20'],

            'events.*.source' => ['sometimes', Rule::in([EventSource::Web->value, EventSource::Mobile->value])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'events.*.name.in' => 'That event cannot be raised by a client.',
        ];
    }
}
