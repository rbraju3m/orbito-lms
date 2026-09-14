<?php

declare(strict_types=1);

namespace Database\Factories\Content;

use App\Domain\Content\Enums\LeadSource;
use App\Domain\Content\Enums\LeadStatus;
use App\Domain\Content\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A NEW lead from the front page, submitted once. It fires no `LeadCaptured`:
 * capturing through `CaptureLead` is what does, and a test about the event
 * must go through the endpoint.
 *
 * @extends Factory<Lead>
 */
final class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'name' => fake()->name(),
            'status' => LeadStatus::New,
            'source' => LeadSource::Site,
            'source_id' => null,
            'source_title' => null,
            'consent_text' => 'I agree to be contacted by email.',
            'consented_at' => now(),
            'submissions_count' => 1,
            'last_submitted_at' => now(),
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
