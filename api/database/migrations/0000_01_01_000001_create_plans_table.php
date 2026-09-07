<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL. What an academy can be sold.
 *
 * `limits` and `features` are JSON rather than columns because the set grows
 * with the product and every addition would otherwise be a migration plus a
 * backfill. They are read through PlanLimits, never indexed on — the platform
 * asks "what is THIS academy capped at", one row at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // Integer minor units + ISO currency, never a float.
            $table->unsignedBigInteger('price_minor')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('billing_period', 20)->default('monthly');

            $table->unsignedSmallInteger('trial_days')->default(0);
            /*
             * Days a lapsed subscription keeps WRITING after its period ends.
             * Taking the platform away the day an invoice slips is how you
             * lose the customer, not how you get paid.
             */
            $table->unsignedSmallInteger('grace_days')->default(7);

            // {"max_courses": 50, "max_students": 500, ...}; null means no cap.
            $table->json('limits')->nullable();
            $table->json('features')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
