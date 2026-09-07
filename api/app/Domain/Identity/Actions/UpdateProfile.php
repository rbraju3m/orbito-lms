<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

final class UpdateProfile
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>|null  $socialLinks  platform => url; null leaves them untouched
     */
    public function handle(User $user, array $attributes, ?array $socialLinks = null): User
    {
        return DB::transaction(function () use ($user, $attributes, $socialLinks): User {
            $user->fill($attributes)->save();

            if ($socialLinks !== null) {
                $user->socialLinks()->delete();

                foreach (array_filter($socialLinks) as $platform => $url) {
                    $user->socialLinks()->create(['platform' => $platform, 'url' => $url]);
                }
            }

            return $user->fresh(['socialLinks', 'instructorProfile']) ?? $user;
        });
    }
}
