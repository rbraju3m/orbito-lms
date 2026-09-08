<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A certificate is a statement one academy makes about one learner,
 * so it lives entirely in that academy's schema.
 *
 * The public verification page is the awkward part. It has no authenticated
 * user, so it cannot resolve an academy the usual way — the URL carries the
 * tenant id, and `verification_token` is the credential. That token is the
 * ONLY thing a stranger ever presents, which is why it is unguessable, unique
 * and indexed, and why the numeric id never appears in a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * How a certificate looks. `layout` is JSON rather than columns
         * because a template is a document design — fields move, are added and
         * are removed — and a migration per design tweak is the wrong shape.
         */
        Schema::create('certificate_templates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 120);
            $table->string('orientation', 20)->default('landscape');

            // Nullable: a template with no artwork still renders, on white.
            $table->unsignedBigInteger('background_media_id')->nullable();

            $table->json('layout');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The issuing path reads "the default, active one" on every issue.
            $table->index(['is_active', 'is_default']);
        });

        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Human-facing and printed on the document. Separate from `uuid`
             * because people read this one aloud and type it into a form —
             * and separate from the id because a sequential integer on a
             * certificate tells every holder how many the academy has issued.
             */
            $table->string('number', 32)->unique();

            $table->foreignId('template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();

            // Central users table — unenforced across the schema boundary (§16).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            /*
             * UNIQUE, and that uniqueness is the idempotency guarantee.
             * `CourseCompleted` can fire more than once — a recalculation, a
             * retake, a replayed queue job — and none of those may mint a
             * second certificate for one enrolment.
             */
            $table->foreignId('enrollment_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();

            $table->string('status', 20)->default('issued');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            // Null until the queued render finishes. A certificate is VALID
            // before its PDF exists; the document is a rendering of the fact,
            // not the fact itself.
            $table->unsignedBigInteger('pdf_media_id')->nullable();

            /*
             * Who it was issued to, for what, and with what score — frozen at
             * issue. A learner who later changes their display name does not
             * retroactively change a certificate somebody has already
             * verified, and a renamed course does not rewrite history.
             */
            $table->json('snapshot');

            // The credential the public page checks. Never the id.
            $table->char('verification_token', 32)->unique();

            $table->timestamps();

            $table->index('user_id');
            $table->index('course_id');
            // "Has this learner got one for this course?" on the course page.
            $table->index(['user_id', 'course_id']);
            // The reconciliation sweep: issued certificates still lacking a PDF.
            $table->index(['status', 'pdf_media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');
    }
};
