<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL. One row per academy, mutated in place.
 *
 * `status` is STORED, never recomputed from the dates on read. A subscription
 * degrades in exactly one place — the nightly sweep — so access changes at a
 * moment somebody can point at in a log, rather than silently between two
 * requests while a learner is mid-lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            // One subscription per academy. History belongs to Phase 10's
            // orders and invoices, not to a second row here.
            $table->string('tenant_id')->unique();
            $table->foreignId('plan_id')->constrained('plans');

            $table->string('status', 20)->default('trialing');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();

            /*
             * Copied from the plan at the moment the subscription is written,
             * not read through it. Changing a plan's grace period must not
             * silently re-open or shut academies that were already lapsed.
             */
            $table->unsignedSmallInteger('grace_days')->default(7);

            $table->timestamps();

            // The nightly sweeper's query.
            $table->index(['status', 'current_period_ends_at']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
