<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL. A person's links belong to the person, not to the academy they
 * happen to teach at — the same account carries them wherever it is used.
 *
 * `instructor_profiles` went the other way, into the tenant schema: being an
 * approved instructor is a fact about a person AT ONE ACADEMY, granted and
 * revoked by that academy's admins.
 */
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
    }

    public function down(): void
    {
        Schema::dropIfExists('user_social_links');
    }
};
