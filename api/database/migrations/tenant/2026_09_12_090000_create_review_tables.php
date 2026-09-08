<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A review is one academy's learner talking about that academy's
 * course; it means nothing outside the schema it lives in.
 *
 * The whole point of this table is that `courses.rating_avg` and
 * `rating_count` are COLUMNS maintained by event, never `AVG()` on a read
 * path (CLAUDE.md §10). Every index here exists to make maintaining them, and
 * reconciling them nightly, cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // Central users table — unenforced across the schema boundary (§16).
            $table->unsignedBigInteger('user_id');

            /*
             * The enrolment that earned the right to review. Kept even if the
             * enrolment is later cancelled: a review written by somebody who
             * genuinely took the course does not stop being theirs because
             * their access lapsed.
             */
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->string('title', 180)->nullable();
            $table->text('body')->nullable();

            $table->string('status', 20)->default('pending');

            $table->text('instructor_reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            /*
             * One review per learner per course. Editing replaces; it does not
             * append. Without this a learner could inflate a course's average
             * by reviewing it repeatedly — the aggregate is the whole reason
             * the table exists.
             */
            $table->unique(['course_id', 'user_id']);

            // The course page: published reviews, newest first.
            $table->index(['course_id', 'status', 'published_at']);
            // The moderation queue, across courses.
            $table->index(['status', 'created_at']);
            // "What has this learner reviewed?" and the own-review lookup.
            $table->index('user_id');
        });

        /*
         * Whether a review waits for a human before it appears.
         *
         * Per COURSE rather than per academy: a marketing course and a
         * safeguarding course have different tolerances for an unmoderated
         * comment, and an academy-wide switch would force the stricter one on
         * everybody.
         *
         * Default false, matching `enable_reviews` defaulting true — an
         * academy that has not thought about it gets reviews that publish
         * immediately, which is the behaviour a learner expects.
         */
        Schema::table('course_settings', function (Blueprint $table): void {
            $table->boolean('moderate_reviews')->default(false)->after('enable_reviews');
        });
    }

    public function down(): void
    {
        Schema::table('course_settings', function (Blueprint $table): void {
            $table->dropColumn('moderate_reviews');
        });

        Schema::dropIfExists('reviews');
    }
};
