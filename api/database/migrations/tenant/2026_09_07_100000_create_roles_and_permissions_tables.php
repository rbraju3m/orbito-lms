<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 50);
            $table->string('description');
            $table->timestamps();

            $table->index('group');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // 'global' applies platform-wide; 'course' is granted per course
            // through role_assignments.scope_id.
            $table->string('scope_kind', 20)->default('global');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->index('scope_kind');
        });

        Schema::create('permission_role', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();

            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            // CENTRAL users table — no FK can span databases, so this is an
            // unenforced reference. Deleting a user does NOT cascade here;
            // see PurgeUserFromTenants (T3).
            $table->unsignedBigInteger('user_id');
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();

            // Polymorphic scope. NULL/NULL is a global assignment; ('course', 42)
            // grants the role only on course 42. No FK: the scope may point at
            // any future entity type (ADR-07).
            $table->string('scope_type', 50)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->unsignedBigInteger('granted_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // MySQL treats NULLs as distinct in a unique index, so this does not
            // constrain global rows; RoleAssignment guards those in the model.
            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_id'], 'role_assignments_unique');
            $table->index(['scope_type', 'scope_id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
