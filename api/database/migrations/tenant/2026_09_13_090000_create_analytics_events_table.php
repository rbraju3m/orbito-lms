<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. The append-only event log (ADR-08).
 *
 * Written by queued listeners and by one rate-limited client endpoint. NEVER
 * read by a dashboard: rollups are built from it on a schedule and the
 * dashboards read those. That is what keeps the write path cheap and the read
 * path proportional to the range asked for rather than to the history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();

            // The canonical name, from EventName. A string rather than an enum
            // column: retiring a name must not make old rows unreadable, and
            // adding one must not be a migration.
            $table->string('name', 64);

            /*
             * When it HAPPENED, not when it was written.
             *
             * A queued listener may land minutes later, and a client may post
             * a batch after a spell offline. Bucketing by `created_at` would
             * put those in the wrong day, so every rollup reads this column.
             * Millisecond precision because two events in one request are
             * ordinary and their order is sometimes the interesting part.
             */
            $table->dateTime('occurred_at', 3);

            // Central user id, unenforced across the boundary (§ Multi-tenancy). Null for
            // anything the system did on nobody's behalf.
            $table->unsignedBigInteger('actor_id')->nullable();

            // The browser session, so anonymous-ish behaviour can be strung
            // together without identifying anybody.
            $table->char('session_id', 36)->nullable();

            // What it was about — a morph in shape but NOT a relation.
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Denormalised out of the subject because almost every question
            // asked of this table is "for which course?".
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('course_item_id')->nullable();

            $table->json('properties')->nullable();

            /*
             * The IP, hashed, never stored in the clear. Enough to count
             * distinct visitors; not enough to be a record of where somebody
             * was.
             */
            $table->char('ip_hash', 64)->nullable();

            $table->string('source', 10)->default('api');

            $table->timestamp('created_at')->nullable();

            /*
             * DELIBERATELY NO FOREIGN KEYS.
             *
             * An event is a fact about the past. `ON DELETE CASCADE` from
             * courses would mean deleting a course silently erases the history
             * of everybody who took it — the one thing a log exists to
             * prevent. Rollups resolve names by id and say "deleted course"
             * when they cannot.
             *
             * It also leaves the door open to partitioning this table by
             * month, which MySQL forbids on a table with foreign keys.
             */
            $table->index(['name', 'occurred_at']);
            $table->index(['course_id', 'name', 'occurred_at']);
            $table->index(['actor_id', 'occurred_at']);
            // Retention prunes by age alone, so it needs a leading date.
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
