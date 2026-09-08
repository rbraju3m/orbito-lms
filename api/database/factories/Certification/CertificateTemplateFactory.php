<?php

declare(strict_types=1);

namespace Database\Factories\Certification;

use App\Domain\Certification\Enums\TemplateOrientation;
use App\Domain\Certification\Models\CertificateTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificateTemplate>
 */
final class CertificateTemplateFactory extends Factory
{
    protected $model = CertificateTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'name' => 'Default template',
            'orientation' => TemplateOrientation::Landscape,
            'background_media_id' => null,
            'layout' => CertificateTemplate::defaultLayout(),
            'is_default' => true,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'is_default' => false]);
    }
}
