<?php

declare(strict_types=1);

namespace App\Http\Resources\Notification;

use App\Domain\Notification\Enums\NotificationGroup;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The settings screen, exactly as NotificationPreferences computes it.
 *
 * The groups and their labels come from the API rather than from a list the
 * SPA keeps in step by hand: adding a notification type must not require a
 * frontend release for its switch to appear.
 *
 * @mixin Collection<int, array<string, mixed>>
 */
final class NotificationPreferenceResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->resource;

        return [
            'groups' => array_map(
                fn (NotificationGroup $group): array => [
                    'key' => $group->value,
                    'label' => $group->label(),
                    'types' => array_values(array_filter(
                        $rows,
                        fn (array $row): bool => $row['group'] === $group->value,
                    )),
                ],
                NotificationGroup::cases(),
            ),
        ];
    }
}
