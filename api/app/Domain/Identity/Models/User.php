<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Concerns\HasRoles;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use Carbon\CarbonInterface;
use Database\Factories\Identity\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $phone
 * @property string|null $headline
 * @property string|null $bio
 * @property string $timezone
 * @property string $locale
 * @property UserStatus $status
 * @property CarbonInterface|null $last_login_at
 * @property CarbonInterface|null $last_seen_at
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'headline', 'bio', 'timezone', 'locale',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid7();
        });
    }

    /** Public identifier in URLs — never the auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return HasOne<InstructorProfile, $this> */
    public function instructorProfile(): HasOne
    {
        return $this->hasOne(InstructorProfile::class);
    }

    /** @return HasMany<UserSocialLink, $this> */
    public function socialLinks(): HasMany
    {
        return $this->hasMany(UserSocialLink::class);
    }

    public function isActive(): bool
    {
        return $this->status->canAuthenticate();
    }

    public function isApprovedInstructor(): bool
    {
        // loadMissing, not property access: strict mode forbids implicit lazy
        // loading, and this is called from places that may not have eager-loaded.
        $this->loadMissing('instructorProfile');

        return $this->instructorProfile?->status->isActive() === true;
    }

    /**
     * Both verification and reset links point at the SPA, which then calls the
     * API. The API never renders HTML, so it cannot own these pages.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
