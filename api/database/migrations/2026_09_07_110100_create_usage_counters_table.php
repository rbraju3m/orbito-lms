<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan limits are a Phase 16 product, but the counters they read must be
 * maintained from the moment the rows they count start existing — backfilling
 * "how many students did this instructor have last year" is not possible.
 *
 * One row per (owner, metric). Reads are O(1); writes are atomic increments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();

            // 'platform' with owner_id 0 is the whole deployment; otherwise an
            // entity alias from the morph map (e.g. 'user') with its id.
            //
            // owner_id is NOT NULL with a 0 sentinel on purpose: MySQL treats
            // NULLs as DISTINCT in a unique index, so a nullable column here
            // would let platform rows duplicate without ever colliding.
            $table->string('owner_type', 50);
            $table->unsignedBigInteger('owner_id')->default(0);

            $table->string('metric', 50);
            $table->bigInteger('value')->default(0);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'metric'], 'usage_counters_unique');
            $table->index('metric');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
