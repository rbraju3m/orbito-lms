<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // CENTRAL users table — no FK can span databases, so this is an
            // unenforced reference. Deleting a user does NOT cascade here;
            // see PurgeUserFromTenants (T3).
            $table->unsignedBigInteger('owner_id');

            // Course content lands on the private disk and is only ever served
            // through a short-lived signed URL (ADR-09).
            $table->string('disk', 20)->default('private');
            $table->string('path', 500);
            $table->string('collection', 64);

            $table->string('original_name', 255);
            $table->string('mime', 127);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->char('checksum', 64)->nullable();
            $table->string('status', 20)->default('ready');

            // Loose association back to whatever the file belongs to. No FK: the
            // target may be any entity in any future context.
            $table->string('attachable_type', 50)->nullable();
            $table->unsignedBigInteger('attachable_id')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'collection']);
            $table->index(['attachable_type', 'attachable_id']);
            $table->index('status');
        });

        Schema::create('media_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('path', 500);
            $table->string('mime', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('bitrate')->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_variants');
        Schema::dropIfExists('media');
    }
};
