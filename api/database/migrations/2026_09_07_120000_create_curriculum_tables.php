<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['course_id', 'position']);
        });

        /*
         * ADR-01: the single ordered spine of a course.
         *
         * `position` is COURSE-GLOBAL, not section-local. That is what makes
         * "what comes next?" one indexed query instead of a join across
         * heterogeneous content tables — the thing the audited reference
         * product cannot do. Section grouping comes from section_id.
         *
         * No UNIQUE on (course_id, position): a reorder rewrites the whole
         * course in one transaction and passes through intermediate states.
         */
        Schema::create('course_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('section_id')->constrained('course_sections')->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->string('type', 20);

            // Polymorphic pointer to the type-specific row.
            $table->string('itemable_type', 50);
            $table->unsignedBigInteger('itemable_id');

            // Denormalised so a curriculum listing needs no join to the
            // itemable just to render a title.
            $table->string('title', 180);

            $table->boolean('is_preview')->default(false);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('duration_seconds')->default(0);

            // Drip. Columns land now so the spine never changes shape;
            // enforcement arrives with access control in Phase 9.
            $table->dateTime('drip_available_at')->nullable();
            $table->unsignedInteger('drip_after_days')->nullable();
            $table->unsignedBigInteger('drip_after_item_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['itemable_type', 'itemable_id']);
            $table->index(['course_id', 'position']);
            $table->index(['section_id', 'position']);
            $table->index(['course_id', 'is_published', 'position']);
        });

        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->longText('content')->nullable();
            $table->string('content_format', 12)->default('html');

            $table->string('video_provider', 20)->default('none');
            $table->foreignId('video_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('video_url', 500)->nullable();
            $table->unsignedInteger('video_duration_seconds')->default(0);
            $table->foreignId('video_poster_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->foreignId('audio_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('document_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table): void {
            $table->id();
            $table->string('description', 500)->nullable();
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->string('external_url', 500)->nullable();
            $table->boolean('download_allowed')->default(true);
            $table->timestamps();
        });

        Schema::create('course_item_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('course_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['course_item_id', 'media_id']);
            $table->index(['course_item_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_item_attachments');
        Schema::dropIfExists('resources');
        Schema::dropIfExists('lessons');
        Schema::dropIfExists('course_items');
        Schema::dropIfExists('course_sections');
    }
};
