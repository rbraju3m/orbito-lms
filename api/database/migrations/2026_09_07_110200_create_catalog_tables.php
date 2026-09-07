<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('course_categories')->nullOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->foreignId('image_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'position']);
            $table->index('is_active');
        });

        Schema::create('course_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 180)->unique();

            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->longText('description')->nullable();

            $table->foreignId('thumbnail_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('intro_video_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('intro_video_url', 500)->nullable();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->nullOnDelete();

            $table->string('level', 20)->default('all');
            $table->string('locale', 5)->default('en');

            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('public');
            $table->string('completion_mode', 20)->default('flexible');
            $table->string('pricing_model', 20)->default('free');

            $table->timestamp('published_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('coming_soon_at')->nullable();
            $table->text('review_note')->nullable();

            /*
             * Denormalised, event-maintained, reconciled nightly. These exist so
             * a course card never needs a JOIN + AVG — the single worst pattern
             * in the audited reference product.
             */
            $table->unsignedInteger('section_count')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('total_duration_seconds')->default(0);
            $table->unsignedInteger('enrollment_count')->default(0);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Equality columns first, then the sort column (DATABASE.md §14).
            $table->index(['status', 'visibility', 'published_at']);
            $table->index(['category_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        // MySQL needs FULLTEXT declared separately from the Blueprint column DSL.
        DB::statement('ALTER TABLE courses ADD FULLTEXT courses_search_ft (title, subtitle)');

        Schema::create('course_tag', function (Blueprint $table): void {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_tag_id')->constrained('course_tags')->cascadeOnDelete();

            $table->primary(['course_id', 'course_tag_id']);
            $table->index('course_tag_id');
        });

        Schema::create('course_instructors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('co_instructor');
            // Basis points; null means "use the platform default".
            $table->unsignedSmallInteger('revenue_share_bp')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'user_id']);
            $table->index('user_id');
        });

        /*
         * Split from `courses` so the hot catalogue query never reads cold JSON.
         * `courses` stays narrow and index-friendly.
         */
        Schema::create('course_details', function (Blueprint $table): void {
            $table->foreignId('course_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('objectives')->nullable();
            $table->json('requirements')->nullable();
            $table->json('target_audience')->nullable();
            $table->json('materials')->nullable();
            $table->json('faq')->nullable();
            $table->timestamps();
        });

        Schema::create('course_settings', function (Blueprint $table): void {
            $table->foreignId('course_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('enable_qa')->default(true);
            $table->boolean('enable_reviews')->default(true);
            $table->boolean('enable_notes')->default(true);
            $table->boolean('enable_certificate')->default(false);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedInteger('enrollment_expires_days')->nullable();
            $table->string('drip_mode', 20)->default('none');
            $table->boolean('retake_allowed')->default(true);
            $table->boolean('reset_progress_allowed')->default(true);
            $table->unsignedTinyInteger('video_completion_threshold')->default(90);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_settings');
        Schema::dropIfExists('course_details');
        Schema::dropIfExists('course_instructors');
        Schema::dropIfExists('course_tag');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('course_tags');
        Schema::dropIfExists('course_categories');
    }
};
