<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisites gate ENROLLMENT, not ongoing access.
 *
 * Adding a prerequisite to a course must never retroactively lock out someone
 * already enrolled, so `CourseAccess` does not consult this table — only
 * `EnrollInCourse` and the course detail page do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_prerequisites', function (Blueprint $table): void {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->primary(['course_id', 'prerequisite_course_id']);
            // "What does finishing this course unlock?" — the reverse edge.
            $table->index('prerequisite_course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_prerequisites');
    }
};
