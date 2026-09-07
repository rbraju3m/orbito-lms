<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Instructor standing is per-academy: approved to teach here says
 * nothing about anywhere else, and the review, the commission rate and the
 * counters all belong to the academy that granted them.
 *
 * `user_id` points at the CENTRAL users table, so no foreign key enforces it —
 * see the note in the users migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();

            $table->string('status', 20)->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->string('application_source', 50)->nullable();
            $table->text('application_message')->nullable();

            // Basis points (1/100th of a percent) so the split is exact integer
            // arithmetic; NULL falls back to the platform default.
            $table->unsignedSmallInteger('commission_rate_bp')->nullable();
            $table->char('payout_currency', 3)->nullable();

            // Denormalised counters, event-maintained and reconciled nightly.
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('course_count')->default(0);
            $table->unsignedInteger('student_count')->default(0);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_profiles');
    }
};
