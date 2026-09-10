<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Bundles — a purchasable that owns no content.
 *
 * A bundle points at courses and is sold through the product morph Phase 10
 * built for exactly this. Buying one fans out into an enrolment per course,
 * so nothing here appears in an access check: `CourseAccess` (ADR-03) still
 * answers "may they consume this?" from an enrolment, and a bundle is simply
 * one of the ways an enrolment comes to exist. See docs/BUNDLES.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 200)->unique();

            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('thumbnail_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        Schema::create('bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // The same course twice would be charged for once and granted
            // once, while every price comparison counted it twice.
            $table->unique(['bundle_id', 'course_id']);
            $table->index(['bundle_id', 'position']);
            // "Which bundles contain this course?" — asked on every course
            // status change, to decide whether a bundle is still sellable.
            $table->index('course_id');
        });

        /*
         * What each course on a bundle line was worth, in money.
         *
         * Per-course and per-instructor revenue read `order_items` where the
         * purchasable is a course. A bundle line is not, so without this the
         * money would count in the platform total and vanish from every
         * course figure — an instructor selling through bundles reading zero
         * on their own dashboard.
         *
         * Written at ORDER time, from the prices read there, and never
         * recomputed: an allocation is a snapshot for the same reason
         * `order_items.title_snapshot` is. `amount_minor` sums EXACTLY to the
         * line's `total_minor`, which is what keeps the platform total and the
         * per-course figures the same number.
         */
        Schema::create('order_item_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['order_item_id', 'course_id']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_allocations');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
    }
};
