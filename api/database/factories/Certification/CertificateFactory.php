<?php

declare(strict_types=1);

namespace Database\Factories\Certification;

use App\Domain\Catalog\Models\Course;
use App\Domain\Certification\Enums\CertificateStatus;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
final class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid7(),
            'number' => 'CERT-'.now()->format('Y').'-'.Str::upper(Str::random(10)),
            'template_id' => null,
            'user_id' => User::factory()->withRole(RoleKey::Student),
            'course_id' => Course::factory()->published(),
            'enrollment_id' => Enrollment::factory(),
            'issued_at' => now(),
            'expires_at' => null,
            'status' => CertificateStatus::Issued,
            'snapshot' => [
                'learner_name' => 'Test Learner',
                'course_title' => 'A Course',
                'completed_at' => now()->toIso8601String(),
                'academy_name' => 'Test Academy',
            ],
            'verification_token' => Str::lower(Str::random(32)),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => CertificateStatus::Revoked,
            'revoked_at' => now(),
            'revoked_reason' => 'Issued in error.',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }
}
