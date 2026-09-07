<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-02: progress is STORED and event-maintained, never recomputed on read.
 *
 * The audited reference product writes one usermeta row per completed lesson
 * and recalculates a course percentage on every request — loading all content,
 * counting metas, and running a query per assignment in a loop. Rendering
 * "My courses" there is O(courses × items) queries. Here it is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // CENTRAL users table — no FK can span databases, so this is an
            // unenforced reference. Deleting a user does NOT cascade here;
            // see PurgeUserFromTenants (T3).
            $table->unsignedBigInteger('user_id');

            $table->string('status', 20)->default('active');
            $table->string('source', 20)->default('free');
            // order id / subscription id / granting admin — no FK, the target
            // varies by source.
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamp('enrolled_at');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 255)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['course_id', 'status', 'enrolled_at']);
            // The expiry sweeper's query.
            $table->index(['status', 'expires_at']);
        });

        /*
         * One row per enrollment. This is what "My courses" and "Continue
         * learning" read — a single indexed query, no aggregation.
         */
        Schema::create('course_progress', function (Blueprint $table): void {
            $table->foreignId('enrollment_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('total_items')->default(0);
            $table->decimal('percent', 5, 2)->default(0);

            $table->unsignedBigInteger('last_item_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('total_watch_seconds')->default(0);

            $table->timestamps();

            // "Continue learning" — the single most-hit learner query.
            $table->index(['user_id', 'last_activity_at']);
            $table->index(['course_id', 'percent']);
        });

        /*
         * One row per enrollment × item, created LAZILY on first view.
         *
         * For 10k students × 100 items, eager creation would mean a million
         * rows on day one; lazily, only what is actually touched exists.
         */
        Schema::create('item_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->string('status', 20)->default('not_started');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('watch_position_seconds')->default(0);
            // The furthest point reached, so scrubbing backwards cannot
            // un-earn a completion.
            $table->unsignedInteger('watch_max_seconds')->default(0);
            $table->unsignedInteger('view_count')->default(0);

            $table->timestamps();

            $table->unique(['enrollment_id', 'course_item_id']);
            // Per-item drop-off, which becomes the Phase 13 stall heatmap.
            $table->index(['course_item_id', 'status']);
            $table->index(['user_id', 'completed_at']);
        });

        Schema::create('lesson_notes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('video_timestamp_seconds')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_item_id']);
            $table->index(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_notes');
        Schema::dropIfExists('item_progress');
        Schema::dropIfExists('course_progress');
        Schema::dropIfExists('enrollments');
    }
};
