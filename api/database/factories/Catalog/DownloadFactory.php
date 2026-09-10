<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\DownloadPricing;
use App\Domain\Catalog\Enums\DownloadStatus;
use App\Domain\Catalog\Models\Download;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A DRAFT, with no file, by default — like every lifecycle factory here.
 * Publishing runs the checklist and fires the event; a test that wants a live
 * download should be exercising exactly that.
 *
 * @extends Factory<Download>
 */
final class DownloadFactory extends Factory
{
    protected $model = Download::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->words(3, true));

        return [
            'uuid' => (string) Str::uuid7(),
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'subtitle' => fake()->sentence(6),
            'description' => fake()->paragraph(4),
            'pricing_model' => DownloadPricing::OneTime,
            'status' => DownloadStatus::Draft,
            'published_at' => null,
        ];
    }

    public function free(): static
    {
        return $this->state(fn (): array => ['pricing_model' => DownloadPricing::Free]);
    }

    /**
     * Published WITHOUT running the checklist — for tests that need a live
     * download as a fixture rather than as the thing under test.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => DownloadStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }
}
