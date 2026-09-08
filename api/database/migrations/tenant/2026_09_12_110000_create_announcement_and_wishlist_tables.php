<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Announcements and wishlists.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A one-way message from course staff to everybody enrolled.
         *
         * Deliberately NOT a discussion with replies turned off: an
         * announcement is not a thread, and modelling it as one would give it
         * a reply box that has to be disabled everywhere it renders.
         */
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // Central users table — unenforced across the boundary (§16).
            $table->unsignedBigInteger('author_id');

            $table->string('title', 200);
            $table->text('body');

            /*
             * Null means DRAFT. An announcement is written, read back, and
             * then sent — a half-finished one reaching every enrolled learner
             * because somebody hit save is the failure mode this prevents.
             */
            $table->timestamp('published_at')->nullable();

            /*
             * Whether publishing should notify. Stored now and consumed in the
             * notifications slice — recorded here rather than added later so
             * an announcement published before that lands still says what was
             * intended.
             */
            $table->boolean('notify')->default(true);

            $table->timestamps();

            // The learner's list: published, newest first.
            $table->index(['course_id', 'published_at']);
        });

        /*
         * Courses somebody means to come back to.
         *
         * No status, no notes, no ordering: a wishlist that grows features
         * becomes a second enrolment system. It is a set of course ids per
         * learner, and the unique key is the whole model.
         */
        Schema::create('wishlists', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
            // "What have I saved?", newest first.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('announcements');
    }
};
