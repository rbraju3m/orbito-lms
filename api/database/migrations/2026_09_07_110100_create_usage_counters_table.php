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
 * One row per (tenant, owner, metric). Reads are O(1); writes are atomic
 * increments.
 *
 * CENTRAL, deliberately, even though every counter describes something inside
 * one tenant schema. Billing and the plan-limit screen ask "what is every
 * academy using?", and answering that from per-tenant tables means opening one
 * connection per academy on a page that renders a list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();

            /*
             * The academy this counter belongs to, '' for the platform-wide
             * total across every tenant — a real, separate figure from any one
             * academy's, so it gets its own rows rather than being summed.
             *
             * NOT NULL with an empty-string sentinel, for the same reason
             * owner_id uses 0 below: this column leads the unique index, and
             * MySQL treats NULLs as distinct there — a nullable tenant_id
             * would let every platform-wide row duplicate without ever
             * colliding, which is the precise bug that sentinel exists to
             * prevent.
             */
            $table->string('tenant_id')->default('');

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

            $table->unique(['tenant_id', 'owner_type', 'owner_id', 'metric'], 'usage_counters_unique');
            $table->index('metric');
            // The platform billing screen: every counter for one academy.
            $table->index(['tenant_id', 'metric']);

            /*
             * No FK to `tenants`, because the sentinel above is not a tenant id
             * and no foreign key can accept it. Counters are derived data: a
             * row left behind by a deleted academy is stale, not corrupt, and
             * ReconcileUsageCounters is what clears it.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_counters');
    }
};
