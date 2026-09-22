<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\InvitationRole;
use App\Domain\Identity\Enums\InvitationStatus;
use Carbon\CarbonInterface;
use Database\Factories\Identity\InvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An academy asking somebody, by email, to make an account in it
 * (docs/INVITATIONS.md).
 *
 * Tenant data, read by the academy's staff. The link's token is NOT a column:
 * only its hash is stored, and the plain token exists exactly once — in the
 * mail `SendInvitation` queues.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string|null $pending_email
 * @property InvitationRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property CarbonInterface $expires_at
 * @property int $sent_count
 * @property CarbonInterface $last_sent_at
 * @property CarbonInterface|null $accepted_at
 * @property int|null $accepted_user_id
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'email', 'pending_email', 'role', 'token_hash', 'invited_by', 'expires_at',
        'sent_count', 'last_sent_at',
    ];

    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => InvitationRole::class,
            'invited_by' => 'integer',
            'expires_at' => 'datetime',
            'sent_count' => 'integer',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'accepted_user_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $invitation): void {
            $invitation->uuid ??= (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** The same spelling `users.email` and `leads.email` are stored in. */
    public static function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** A fresh credential. The caller mails it and stores only `hashToken()`. */
    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()->where('token_hash', self::hashToken($token))->first();
    }

    /** Derived, never stored: an invitation expires by the clock. */
    public function status(?CarbonInterface $now = null): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->lessThanOrEqualTo($now ?? now()) => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    /**
     * The list filter, written as the SAME rule `status()` applies to one row,
     * so a row cannot be listed under a status its own resource denies.
     *
     * @param  Builder<Invitation>  $query
     */
    public function scopeWithStatus(Builder $query, InvitationStatus $status): void
    {
        match ($status) {
            InvitationStatus::Accepted => $query->whereNotNull('accepted_at'),
            InvitationStatus::Revoked => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
            InvitationStatus::Expired => $query->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '<=', now()),
            InvitationStatus::Pending => $query->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now()),
        };
    }
}
