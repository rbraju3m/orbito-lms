<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Leads — somebody with no account who asked an academy to keep in
 * touch, from a form on its public site (docs/LEADS.md).
 *
 * The first table a STRANGER writes to, so its shape is part of the abuse
 * story rather than beside it:
 *
 *  - ONE row per address. `email` is unique and `CaptureLead` inserts and
 *    catches the violation (§ Phase 14: make idempotency a constraint), so a
 *    script submitting one address a thousand times moves a counter, not the
 *    row count, and fires `LeadCaptured` once.
 *  - NO IP address, hashed or otherwise. The only use for one is abuse, and
 *    the rate limiter already answers that in cache and forgets it. A column
 *    would be personal data kept for nothing.
 *  - The consent is a SNAPSHOT: the words the person agreed to, as the server
 *    rendered them, and when. Rewording the form later must not rewrite what
 *    somebody said yes to — the `title_snapshot` rule.
 *  - `source_id` has no foreign key. Where somebody came from is a fact about
 *    the past; deleting the course must not delete the lead, and a cascade
 *    would (§ Phase 13: a record of history gets no foreign keys).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Normalised to lower case by `Lead::normaliseEmail()` before it
            // gets here, so the unique index is the dedupe.
            $table->string('email', 254)->unique();
            $table->string('name', 120)->nullable();

            $table->string('status', 20)->default('new');

            // Where they first asked. First touch only: a repeat never moves it.
            $table->string('source', 20);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_title', 200)->nullable();

            $table->string('consent_text', 500);
            $table->timestamp('consented_at');

            $table->unsignedInteger('submissions_count')->default(1);
            $table->timestamp('last_submitted_at');

            $table->timestamps();

            // The admin list: filtered by status, newest activity first.
            $table->index(['status', 'last_submitted_at']);
            $table->index('last_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
