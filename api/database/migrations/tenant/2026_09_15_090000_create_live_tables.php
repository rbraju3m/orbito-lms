<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Cohorts, live sessions, attendance and webinars.
 *
 * A NOTE ON TIME, because it is the opposite of every other dated thing here.
 * Analytics rollups, streaks and leaderboards all use a UTC day, because an
 * academy has no timezone and a shifting local day cannot be rebuilt. A live
 * session is the reverse: it happens at a real moment somebody has to be
 * awake for, so the instant is stored in UTC and the IANA zone it was
 * SCHEDULED in is stored beside it. "Tuesdays at 7pm Dhaka time" has to
 * survive a daylight-saving change somewhere else in the world.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A scheduled run of a course — the same curriculum, a start date and
         * a group of people moving through it together.
         *
         * Deliberately NOT a copy of the course. A cohort that duplicated the
         * curriculum would fork every lesson, and an edit would have to be
         * applied to each run separately.
         */
        Schema::create('cohorts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // The IANA zone the dates above were entered in, for display.
            $table->string('timezone', 64)->default('UTC');

            // Null means uncapped, which is not the same as zero places left.
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamp('enrollment_deadline')->nullable();

            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['course_id', 'status']);
            // "What is starting soon?" across the academy.
            $table->index('starts_at');
        });

        /*
         * One academy's credentials for one meeting provider.
         *
         * The same shape as `payment_gateway_accounts` and for the same
         * reason: the academy holds its own Zoom account, the platform holds
         * none. `credentials` is an ENCRYPTED cast — ciphertext at rest,
         * absent from every Resource, and never in a query log.
         */
        Schema::create('live_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('live_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // A session belongs to a course, a cohort, both, or neither — the
            // last being a webinar, which hangs off `webinars` instead.
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('cohort_id')->nullable()->constrained()->cascadeOnDelete();

            /*
             * There is NO `course_item_id` here, unlike the original sketch.
             * A session that sits on the spine is reached the way every other
             * itemable is — `course_items.itemable_type/itemable_id` — and a
             * second link pointing back would be a second thing to keep in
             * step, free to disagree.
             */

            $table->string('provider', 30)->default('manual');
            // The provider's own id for the meeting. Null for a manual link.
            $table->string('external_id', 191)->nullable();
            $table->text('join_url')->nullable();
            /*
             * The host's start link, where a provider issues one. NEVER sent
             * to a learner: on Zoom it starts the meeting AS the host.
             */
            $table->text('host_url')->nullable();

            // Central users table — unenforced across the boundary
            // (§ Multi-tenancy).
            $table->unsignedBigInteger('host_id');

            $table->string('title', 200);
            $table->text('description')->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone', 64)->default('UTC');

            $table->string('status', 20)->default('scheduled');
            $table->foreignId('recording_media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * When the reminder went out, so the sweeper cannot send twice.
             * A column rather than a queue-level guard because the scheduler
             * may run on more than one host, and "did we already?" has to be
             * answerable from the row.
             */
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamps();

            // The reminder sweeper and the calendar both read this.
            $table->index(['starts_at', 'status']);
            $table->index(['course_id', 'starts_at']);
            $table->index(['cohort_id', 'starts_at']);
        });

        Schema::create('session_attendance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->default(0);
            /*
             * Whether a human said so or a click did. A host marking a roster
             * and a provider reporting a join are different kinds of evidence,
             * and a report that cannot tell them apart is a report nobody can
             * defend.
             */
            $table->string('source', 20)->default('self');

            $table->timestamps();

            // One row per person per session; joining twice extends it.
            $table->unique(['live_session_id', 'user_id']);
            $table->index(['user_id', 'joined_at']);
        });

        /*
         * A standalone live event — not part of a course.
         *
         * REGISTRATION IS MEMBERS-ONLY, which is a consequence of the tenancy
         * design rather than a product choice: tenancy resolves from the
         * authenticated user, so there is no anonymous surface to register
         * from (§ Multi-tenancy). `email` and `name` are kept on the
         * registration for the public path P16's marketing site will need.
         */
        Schema::create('webinars', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 200)->unique();

            $table->string('title', 200);
            $table->text('description')->nullable();

            $table->foreignId('live_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('capacity')->nullable();

            $table->boolean('is_paid')->default(false);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('webinar_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            // Null for the public path that does not exist yet.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email', 191);
            $table->string('name', 160)->nullable();

            $table->string('status', 20)->default('registered');
            $table->timestamp('registered_at');
            $table->timestamps();

            /*
             * Keyed on EMAIL, not on user id, so the eventual public path and
             * the members-only one cannot produce two registrations for one
             * person.
             */
            $table->unique(['webinar_id', 'email']);
            $table->index(['user_id', 'registered_at']);
        });

        /*
         * Which run of the course somebody joined. Null for a self-paced
         * enrolment, which stays the default — a cohort is an option on a
         * course, never a requirement.
         */
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->foreignId('cohort_id')->nullable()->after('course_id')
                ->constrained()->nullOnDelete();
            $table->index(['cohort_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table): void {
            $table->dropForeign(['cohort_id']);
            $table->dropIndex(['cohort_id', 'status']);
            $table->dropColumn('cohort_id');
        });

        Schema::dropIfExists('webinar_registrations');
        Schema::dropIfExists('webinars');
        Schema::dropIfExists('session_attendance');
        Schema::dropIfExists('live_sessions');
        Schema::dropIfExists('live_provider_accounts');
        Schema::dropIfExists('cohorts');
    }
};
