<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_social_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 30);
            $table->string('url', 500);
            $table->timestamps();

            $table->unique(['user_id', 'platform']);
        });

        Schema::create('instructor_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->string('application_source', 50)->nullable();
            $table->text('application_message')->nullable();

            // Basis points (1/100th of a percent) so the split is exact integer
            // arithmetic; NULL falls back to the platform default.
            $table->unsignedSmallInteger('commission_rate_bp')->nullable();
            $table->char('payout_currency', 3)->nullable();

            // Denormalised counters, event-maintained and reconciled nightly
            // once Catalog and Enrollment land.
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
        Schema::dropIfExists('user_social_links');
    }
};
