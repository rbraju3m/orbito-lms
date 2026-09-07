<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_banks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Null = a personal bank reusable across all of the owner's courses.
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 180);
            $table->string('description', 500)->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();

            $table->index(['owner_id', 'course_id']);
        });

        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('bank_id')->nullable()->constrained('question_banks')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('type', 30);
            $table->text('title');
            $table->longText('body')->nullable();
            $table->longText('explanation')->nullable();

            $table->decimal('points', 8, 2)->default(1);
            $table->decimal('negative_points', 8, 2)->default(0);
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            /*
             * Per-type configuration, validated against a JSON schema on write.
             * NOT a serialized PHP blob — the audited product stores
             * question_settings, answer_settings and attempt_info that way,
             * which makes them unqueryable and a deserialization surface.
             */
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_id', 'type']);
            $table->index(['owner_id', 'type']);
        });

        Schema::create('question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->text('label');
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_correct')->default(false);
            // Matching: the right-hand value this option pairs with.
            $table->string('match_key', 191)->nullable();
            // Ordering: the correct index.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'position']);
        });

        Schema::create('quizzes', function (Blueprint $table): void {
            $table->id();
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();

            $table->unsignedInteger('time_limit_seconds')->nullable();
            $table->string('time_expiry_policy', 20)->default('auto_submit');

            $table->unsignedTinyInteger('attempts_allowed')->nullable(); // null = unlimited
            $table->unsignedTinyInteger('passing_score_percent')->default(70);
            $table->string('grading_policy', 20)->default('highest');

            $table->string('question_order', 20)->default('sorted');
            $table->boolean('shuffle_answers')->default(false);
            // Random subset size; null = every question.
            $table->unsignedInteger('questions_per_attempt')->nullable();
            $table->unsignedTinyInteger('questions_per_page')->default(1);
            $table->boolean('hide_question_numbers')->default(false);

            $table->string('feedback_mode', 20)->default('deferred');
            $table->string('show_correct_answers_after', 20)->default('submission');
            $table->boolean('negative_marking')->default(false);
            $table->boolean('allow_previous_button')->default(true);

            $table->timestamps();
        });

        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->decimal('points_override', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['quiz_id', 'question_id']);
            $table->index(['quiz_id', 'position']);
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->string('status', 20)->default('in_progress');

            $table->timestamp('started_at');
            /*
             * Set by the SERVER at start (ADR-06). Submission is judged against
             * this, never against a clock the client controls.
             */
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('total_points', 9, 2)->default(0);
            $table->decimal('earned_points', 9, 2)->default(0);
            $table->decimal('percent', 5, 2)->default(0);
            $table->string('result', 10)->nullable();

            // The shuffled order actually served, so paging and resume are stable.
            $table->json('question_order')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamps();

            $table->unique(['quiz_id', 'user_id', 'attempt_number']);
            $table->index(['user_id', 'quiz_id', 'status']);
            $table->index(['course_id', 'status']);
            // The expiry sweeper's query.
            $table->index(['status', 'expires_at']);
        });

        Schema::create('quiz_attempt_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('question_type', 30);

            // Typed by question_type; shape documented on QuestionType.
            $table->json('answer')->nullable();

            $table->decimal('points_possible', 8, 2)->default(0);
            $table->decimal('points_earned', 8, 2)->default(0);
            // Null = awaiting manual grading.
            $table->boolean('is_correct')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();

            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
            $table->index('attempt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('question_banks');
    }
};
