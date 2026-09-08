<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Course Q&A.
 *
 * `reply_count` and `last_reply_at` are DENORMALISED for the same reason
 * `courses.rating_avg` is (CLAUDE.md §10): a course's question list renders
 * dozens of threads, and "how many replies, and when was the last one?" must
 * not be a subquery per row. They are maintained by event and reconciled
 * nightly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            /*
             * Optional. A question can hang off one lesson — "what does the
             * bit at 4:12 mean?" — or off the course as a whole. Nullable
             * rather than two tables, because everything else about the two
             * is identical and a second table would need every query twice.
             */
            $table->foreignId('course_item_id')->nullable()->constrained()->cascadeOnDelete();

            // Central users table — unenforced across the boundary (§16).
            $table->unsignedBigInteger('user_id');

            $table->string('type', 20)->default('question');
            $table->string('title', 200);
            $table->text('body');

            $table->string('status', 20)->default('open');
            $table->boolean('is_pinned')->default(false);

            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('last_reply_at')->nullable();

            /*
             * Set when the asker (or course staff) marks one reply as the
             * answer. No FK constraint: `discussion_replies` does not exist
             * yet at this point, and adding one later buys nothing a cascade
             * from the parent does not already give.
             */
            $table->unsignedBigInteger('accepted_reply_id')->nullable();

            $table->timestamps();

            // The course Q&A list: pinned first, then most recently active.
            $table->index(['course_id', 'status', 'is_pinned', 'last_reply_at']);
            // The per-lesson panel in the player.
            $table->index(['course_item_id', 'status']);
            // "My questions", and the unanswered queue an instructor works.
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('discussion_replies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('discussion_id')->constrained()->cascadeOnDelete();

            /*
             * ONE level of nesting, enforced in the action rather than here:
             * a reply may point at a top-level reply, and a reply to THAT is
             * re-parented to the same top-level one. Arbitrary depth is a
             * rendering problem with no good answer and a recursive query on
             * a read path — neither is worth the thread it would allow.
             */
            $table->foreignId('parent_id')->nullable()->constrained('discussion_replies')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id');
            $table->text('body');

            /*
             * Denormalised at write time so the UI can badge an official
             * answer without a permission lookup per row — and so it stays
             * true afterwards: somebody who answered as an instructor and
             * later lost the role still answered as one.
             */
            $table->boolean('is_instructor_reply')->default(false);

            $table->string('status', 20)->default('published');

            $table->timestamps();

            $table->index(['discussion_id', 'created_at']);
            $table->index(['discussion_id', 'parent_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_replies');
        Schema::dropIfExists('discussions');
    }
};
