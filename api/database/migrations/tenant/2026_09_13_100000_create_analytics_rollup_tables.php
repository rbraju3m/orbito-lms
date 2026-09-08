<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. The rollups every dashboard actually reads (ADR-08).
 *
 * A DAY HERE IS A UTC DAY. Timestamps are stored in UTC and converted at the
 * edge (CLAUDE.md §2), and an academy has no timezone of its own — so a
 * rollup keyed on a shifting local day could not be rebuilt deterministically,
 * and rebuilding is the property that makes these tables trustworthy. The API
 * says so in its response rather than leaving a reader to assume.
 *
 * Nothing here is a fact. Every row is derived and every builder is
 * idempotent, so any of these tables can be dropped and rebuilt from the log
 * and the ledger. That is the point of keeping them separate from both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_daily_course', function (Blueprint $table): void {
            $table->date('date');
            $table->unsignedBigInteger('course_id');

            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('enrollments')->default(0);
            $table->unsignedInteger('completions')->default(0);

            // Money is integer minor units plus an ISO code, never a float.
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->char('currency', 3);

            // Distinct people who did ANYTHING in this course that day.
            $table->unsignedInteger('active_learners')->default(0);

            $table->timestamps();

            $table->primary(['date', 'course_id']);
            // "This course over the last 90 days" is the commonest read, and
            // the primary key leads with the wrong column for it.
            $table->index(['course_id', 'date']);
        });

        Schema::create('analytics_daily_platform', function (Blueprint $table): void {
            $table->date('date')->primary();

            $table->unsignedInteger('new_users')->default(0);
            $table->unsignedInteger('new_enrollments')->default(0);
            $table->unsignedInteger('completions')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->char('currency', 3);
            $table->unsignedInteger('active_learners')->default(0);

            $table->timestamps();
        });

        Schema::create('analytics_daily_instructor', function (Blueprint $table): void {
            $table->date('date');
            // Central user id, unenforced across the boundary (§ Multi-tenancy).
            $table->unsignedBigInteger('instructor_id');

            $table->unsignedInteger('enrollments')->default(0);
            $table->unsignedBigInteger('revenue_minor')->default(0);
            $table->char('currency', 3);
            // A snapshot of the average across their courses ON THAT DAY, so a
            // trend line survives a course later being deleted.
            $table->decimal('rating_avg', 3, 2)->default(0);

            $table->timestamps();

            $table->primary(['date', 'instructor_id']);
            $table->index(['instructor_id', 'date']);
        });

        /*
         * The stall heatmap.
         *
         * NOT a daily series, and its primary key says so: a funnel answers
         * "of everybody who reached this item, how many got past it?" — a
         * question about the present state of every learner, not about a day.
         * `computed_at` is when it was last answered.
         */
        Schema::create('analytics_item_funnel', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_item_id')->primary();
            $table->unsignedBigInteger('course_id');

            $table->unsignedInteger('started')->default(0);
            $table->unsignedInteger('completed')->default(0);
            // Mean watch seconds among those who started. Null for an item
            // nobody has opened, which is not the same as zero seconds.
            $table->unsignedInteger('avg_seconds')->nullable();
            // Stored rather than derived on read, so ORDER BY finds the worst
            // item in a course without a computed column.
            $table->decimal('drop_off_rate', 5, 4)->default(0);

            $table->timestamp('computed_at');

            // "Which lesson in this course loses people?", worst first.
            $table->index(['course_id', 'drop_off_rate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_item_funnel');
        Schema::dropIfExists('analytics_daily_instructor');
        Schema::dropIfExists('analytics_daily_platform');
        Schema::dropIfExists('analytics_daily_course');
    }
};
