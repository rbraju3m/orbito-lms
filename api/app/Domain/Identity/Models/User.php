<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Catalog\Models\Course;
use App\Domain\Identity\Concerns\HasRoles;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Notifications\ResetPasswordNotification;
use App\Domain\Identity\Notifications\VerifyEmailNotification;
use App\Domain\Notification\Models\Notification;
use App\Domain\Platform\Actions\PurgeUserFromTenant;
use App\Domain\Platform\Models\Tenant;
use Carbon\CarbonInterface;
use Database\Factories\Identity\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
 * @property string|null $tenant_id
 * @property bool $is_super_admin
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /*
     * CENTRAL. Pinned so this model can never be read through a tenant
     * connection: when tenancy is initialised the default connection is
     * swapped, and an unpinned central model would silently query a table of
     * the same name inside the academy's schema — or fail because there is
     * none. CentralModelConnectionTest enforces this.
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'headline', 'bio', 'timezone', 'locale',
    ];

    /*
     * Deliberately NOT fillable. Which academy an account belongs to, and
     * whether it runs the platform, are decided by provisioning and by a
     * super-admin — never by anything a request body can reach.
     */

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
            'is_super_admin' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid7();
        });

        /*
         * `users` is central and the rows referencing it are not, so no foreign
         * key can cascade across the boundary any more. This does it in code.
         *
         * forceDeleted, not deleted: this model soft-deletes, and a soft delete
         * never cascaded either.
         */
        self::forceDeleted(function (self $user): void {
            app(PurgeUserFromTenant::class)->handle($user);
        });
    }

    /** Public identifier in URLs — never the auto-increment id. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The academy this account belongs to — NULL only for a platform
     * super-admin.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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

    /**
     * Courses this user owns. Co-instructed courses come through
     * course_instructors, not this relation.
     *
     * @return HasMany<Course, $this>
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'owner_id');
    }

    /**
     * The academy inbox.
     *
     * Overrides Laravel's `notifications()` for one reason: it returns
     * `DatabaseNotification`, which has no connection of its own, so Eloquent
     * copies THIS model's central pin onto it and goes looking for a
     * `notifications` table in the central database. Our Notification lives in
     * the academy schema and says so (§ Multi-tenancy).
     *
     * Both the read path and the WRITE path come through here — Laravel's
     * database channel routes to `notifications()` too — so replacing this one
     * relation is what puts a person's inbox in the right database.
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
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
