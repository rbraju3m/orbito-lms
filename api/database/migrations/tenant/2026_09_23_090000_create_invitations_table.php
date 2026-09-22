<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Invitations — an academy asking somebody by email to make an
 * account in it, as a student or an instructor (docs/INVITATIONS.md).
 *
 *  - The TOKEN is never stored, only its SHA-256. The row is read by staff;
 *    a column holding the credential itself would hand every admin a working
 *    link to sign up as somebody else.
 *  - ONE open invitation per address, as a CONSTRAINT (§ Phase 14): while an
 *    invitation is open `pending_email` holds the address and the unique
 *    index refuses a second; accepting or revoking sets it to NULL, which
 *    MySQL allows any number of. Inviting the same address again re-issues
 *    the open row rather than stacking links that each grant a role.
 *  - The status is DERIVED from the timestamps and the clock, never stored
 *    (§ Phase 15): an invitation expires by the clock, with nothing to sweep.
 *  - `invited_by` and `accepted_user_id` are central user ids, so no foreign
 *    key — one cannot cross the schema boundary (§ Multi-tenancy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Lower-cased before it gets here (`Invitation::normaliseEmail()`).
            $table->string('email', 254);
            $table->string('pending_email', 254)->nullable()->unique();

            $table->string('role', 20);
            $table->char('token_hash', 64)->unique();

            $table->unsignedBigInteger('invited_by')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('sent_count')->default(1);
            $table->timestamp('last_sent_at');

            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('accepted_user_id')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // The admin list: newest first, filtered by the derived status,
            // searched by address.
            $table->index(['accepted_at', 'revoked_at', 'expires_at']);
            $table->index('created_at');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
