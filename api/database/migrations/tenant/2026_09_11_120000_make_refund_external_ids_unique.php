<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A provider's refund id names one refund, so it is stored once.
 *
 * Stripe describes one refund in several events — refund.created, then
 * refund.updated — delivered in any order and sometimes together. This index
 * is what makes "recorded once" hold under that race; ReconcileProviderRefund
 * catches the violation rather than checking first (§ Patterns established in
 * Phase 14: make idempotency a CONSTRAINT). A refund recorded as made elsewhere
 * has no provider id, and MySQL allows any number of NULLs.
 *
 * Dated after 2026_09_11_090000, which creates the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->unique('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique(['external_id']);
        });
    }
};
