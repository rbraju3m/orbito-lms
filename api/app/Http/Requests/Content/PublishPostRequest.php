<?php

declare(strict_types=1);

namespace App\Http\Requests\Content;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/** Publishing now, or at a given moment — a future one schedules the post. */
final class PublishPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller authorizes through the policy.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // An instant with its offset, stored in UTC. A time in the past is
            // allowed on purpose: a post moved from an old blog keeps its date.
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function publishAt(): ?CarbonInterface
    {
        $at = $this->input('published_at');

        return is_string($at) && $at !== '' ? CarbonImmutable::parse($at)->utc() : null;
    }
}
