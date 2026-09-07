<?php

declare(strict_types=1);

namespace App\Http\Resources\Identity;

use App\Domain\Identity\Models\User;
use App\Support\Http\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @mixin User
 */
final class UserResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'status' => $this->status->value,
            'email_verified' => $this->hasVerifiedEmail(),
            'created_at' => $this->created_at?->toIso8601String(),

            // The email address is only ever shown to the owner or to someone
            // who may view user accounts — a public profile must not leak it.
            'email' => $this->when($this->canSeePrivateFields($request), fn () => $this->email),
            'phone' => $this->when($this->canSeePrivateFields($request), fn () => $this->phone),
            'last_login_at' => $this->when(
                $this->canSeePrivateFields($request),
                fn () => $this->last_login_at?->toIso8601String(),
            ),

            'social_links' => $this->whenLoaded(
                'socialLinks',
                fn () => $this->socialLinks->mapWithKeys(fn ($link) => [$link->platform => $link->url]),
            ),

            'instructor_profile' => $this->whenLoaded(
                'instructorProfile',
                fn () => $this->instructorProfile
                    ? InstructorProfileResource::make($this->instructorProfile)
                    : null,
            ),
        ];
    }

    private function canSeePrivateFields(Request $request): bool
    {
        $viewer = $request->user();

        if ($viewer === null) {
            return false;
        }

        return $viewer->id === $this->id || $viewer->hasPermission('user.view');
    }
}
