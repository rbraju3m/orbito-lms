<?php

declare(strict_types=1);

namespace Database\Factories\Notification;

use App\Domain\Identity\Models\User;
use App\Domain\Notification\Data\NotificationPayload;
use App\Domain\Notification\Enums\NotificationType;
use App\Domain\Notification\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
final class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $payload = new NotificationPayload(
            type: NotificationType::AnnouncementPublished,
            title: fake()->sentence(4),
            body: fake()->sentence(10),
            actionLabel: 'Open',
            actionPath: '/learn/'.Str::uuid7(),
        );

        return [
            'id' => (string) Str::uuid7(),
            'type' => $payload->type->value,
            // The morph alias, not the class name — the map is enforced.
            'notifiable_type' => (new User)->getMorphClass(),
            'notifiable_id' => User::factory(),
            'data' => $payload->toArray(),
            'read_at' => null,
        ];
    }

    public function for_(User $user): static
    {
        return $this->state(fn () => ['notifiable_id' => $user->id]);
    }

    public function ofType(NotificationType $type): static
    {
        return $this->state(function (array $attributes) use ($type): array {
            $data = $attributes['data'];
            $data['type'] = $type->value;

            return ['type' => $type->value, 'data' => $data];
        });
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
