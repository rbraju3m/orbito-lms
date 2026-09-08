<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Points, badges, streaks and leaderboards.
 *
 * Per-academy, like everything else that is not an account: points earned
 * teaching yourself Bengali poetry in one academy have no meaning in another,
 * and a global balance would let a busy academy's regulars top a quiet one's
 * board (§ Multi-tenancy).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Rules are DATA, not code. An academy retunes points, deactivates a
         * rule or adds its own without a deploy — and `gamification:sync`
         * only creates what is missing, so a re-sync never undoes that.
         */
        Schema::create('gamification_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('event_name', 64);
            $table->string('name', 120);
            $table->integer('points');

            // Checked against the TRIGGER's payload, never against a model the
            // rule re-reads — a rule that re-queries can award on a row that
            // has changed since the thing happened.
            $table->json('conditions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('cooldown_seconds')->default(0);
            $table->unsignedInteger('max_per_day')->nullable();
            $table->timestamps();

            // Every award starts "which active rules watch this trigger?".
            $table->index(['event_name', 'is_active']);
        });

        /*
         * The ledger. Append-only: points are never edited, and taking some
         * back is a negative row so the history says what happened.
         */
        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            // Central users table — unenforced across the boundary
            // (§ Multi-tenancy).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('rule_id')->nullable()->constrained('gamification_rules')->nullOnDelete();

            $table->integer('points');
            // The balance AFTER this row, so a history reads without summing
            // everything above it. Written under the profile's row lock.
            $table->integer('balance_after');

            // What earned it. A morph in shape, not a relation — the ledger
            // must survive the lesson being deleted.
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reason', 180)->nullable();

            /*
             * Which course earned it, denormalised off the trigger.
             *
             * A course leaderboard cannot be derived from the source: an
             * enrolment, a reply and a lesson are three different tables, and
             * two of them would need a join across a morph to answer "which
             * course?". Null for anything the academy earned generally.
             */
            $table->unsignedBigInteger('course_id')->nullable();

            $table->timestamp('awarded_at');
            $table->timestamps();

            /*
             * THE ANTI-FARMING CONSTRAINT.
             *
             * A learner who un-completes and re-completes a lesson fires
             * `ItemCompleted` again. A once-per-source rule computes a dedupe
             * key and the DATABASE refuses the second row — a check-then-
             * insert loses that race, and this is the exact race somebody
             * clicking a checkbox twice creates.
             *
             * NULL for genuinely repeatable rules, and MySQL allows any
             * number of NULLs in a unique index, which is what makes one
             * column serve both kinds.
             */
            $table->string('dedupe_key', 191)->nullable();
            $table->unique(['user_id', 'dedupe_key']);

            $table->index(['user_id', 'awarded_at']);
            // The leaderboard builder sums a window, academy-wide...
            $table->index('awarded_at');
            // ...and per course.
            $table->index(['course_id', 'awarded_at']);
        });

        /*
         * One row per learner: their balance, and their streak.
         *
         * DATABASE.md drew `streaks` as its own table keyed on the same
         * column. Merged, because both are "this learner's standing", both
         * are touched by the same award, and two tables keyed alike means two
         * locks and two upserts in one transaction for no gain.
         */
        Schema::create('gamification_profiles', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();

            $table->integer('points_total')->default(0);

            $table->unsignedInteger('current_streak_days')->default(0);
            $table->unsignedInteger('longest_streak_days')->default(0);
            // A UTC date, like the analytics rollups and for the same reason.
            $table->date('last_active_date')->nullable();

            /*
             * Whether they appear on leaderboards.
             *
             * A board nobody can leave is a hostile feature: not everybody
             * wants their study habits ranked in front of their classmates,
             * and the ones who least want it are the ones a ranking helps
             * least. Opting out stops the publication, never the points —
             * badges, streak and balance are all still theirs.
             */
            $table->boolean('is_ranked')->default(true);

            $table->timestamps();

            // The all-time board, and "where do I stand?".
            $table->index('points_total');
        });

        Schema::create('badges', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->foreignId('icon_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('tier', 16)->default('bronze');

            // A CLOSED set of shapes, not an expression language — see
            // BadgeCriteria. Every shape has to be one indexed query.
            $table->json('criteria');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('user_badges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();

            $table->timestamp('awarded_at');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            // Awarded once. The constraint is the guarantee, not a check.
            $table->unique(['user_id', 'badge_id']);
            $table->index(['user_id', 'awarded_at']);
        });

        /*
         * Leaderboards are SNAPSHOTS.
         *
         * Computing one live is a sum over the whole ledger on every page
         * load, and it would also mean the board reshuffles while somebody is
         * reading it. `entries` is the whole board as JSON: it is written
         * once, read whole, and never queried into.
         */
        Schema::create('leaderboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 16);
            // Null for the academy-wide board.
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('period', 16);
            $table->date('period_start');

            $table->json('entries');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'period', 'period_start'], 'leaderboards_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('badges');
        Schema::dropIfExists('gamification_profiles');
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('gamification_rules');
    }
};
