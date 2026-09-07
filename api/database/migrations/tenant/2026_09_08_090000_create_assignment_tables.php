<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table): void {
            $table->id();
            $table->longText('instructions')->nullable();

            $table->decimal('total_points', 8, 2)->default(100);
            $table->decimal('passing_points', 8, 2)->nullable();

            $table->timestamp('due_at')->nullable();
            // reject | accept | penalise — see LatePolicy.
            $table->string('late_policy', 20)->default('accept');
            $table->unsignedTinyInteger('late_penalty_percent')->default(0);

            // Null = unlimited, the same shape as quizzes.attempts_allowed.
            $table->unsignedTinyInteger('max_attempts')->nullable()->default(1);

            $table->boolean('allow_text')->default(true);
            $table->boolean('allow_files')->default(true);
            /*
             * The per-assignment file rules. They NARROW the MediaCollection's
             * rules, never widen them: the collection remains the outer bound
             * on disk, MIME and size, so an author cannot open a hole here.
             */
            $table->unsignedInteger('max_file_size_kb')->default(10 * 1024);
            $table->json('allowed_extensions')->nullable();
            $table->unsignedTinyInteger('max_files')->default(5);

            $table->timestamps();
        });

        Schema::create('assignment_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['assignment_id', 'media_id']);
            $table->index(['assignment_id', 'position']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            // Denormalised so the grading queue and the learner's list are one
            // indexed read each, with no join back through the spine.
            $table->foreignId('course_item_id')->constrained('course_items')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // CENTRAL users table — no FK can span databases, so this is an
            // unenforced reference. Deleting a user does NOT cascade here;
            // see PurgeUserFromTenants (T3).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('enrollment_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('attempt_number')->default(1);
            // submitted | graded | returned — see SubmissionStatus.
            $table->string('status', 20)->default('submitted');

            $table->longText('body')->nullable();
            $table->timestamp('submitted_at');
            /*
             * Decided by the SERVER against the assignment's due_at at the
             * moment of submission, and frozen. Moving the deadline afterwards
             * must not retroactively make somebody late.
             */
            $table->boolean('is_late')->default(false);

            // What the grader awarded, before any late penalty.
            $table->decimal('points_raw', 8, 2)->nullable();
            // What the penalty removed, so the learner can see both numbers.
            $table->decimal('late_penalty_points', 8, 2)->default(0);
            // The final figure. points_raw - late_penalty_points, never below 0.
            $table->decimal('points_earned', 8, 2)->nullable();
            $table->boolean('passed')->nullable();

            $table->longText('feedback')->nullable();
            $table->unsignedBigInteger('graded_by')->nullable();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-character
            // identifier limit.
            $table->unique(['assignment_id', 'user_id', 'attempt_number'], 'submissions_assignment_user_attempt_unique');
            $table->index(['course_id', 'status', 'submitted_at']);
            $table->index(['user_id', 'status']);
            $table->index(['course_item_id', 'user_id']);
        });

        Schema::create('assignment_submission_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')
                ->constrained('assignment_submissions')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            // Copied at submission time: the media row can be renamed or
            // soft-deleted later, and a graded submission must still describe
            // what was actually handed in.
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->unique(['submission_id', 'media_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submission_files');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignment_attachments');
        Schema::dropIfExists('assignments');
    }
};
