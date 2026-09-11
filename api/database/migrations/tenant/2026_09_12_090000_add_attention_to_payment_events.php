<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Refund reports the webhook could not settle, kept for a person
 * (REFUNDS.md §6) — and what that person did about them.
 *
 * `needs_attention`, not "processed_at is null": an event about a payment we
 * never issued is unprocessed too, and that is noise, not work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->boolean('needs_attention')->default(false)->after('processed_at');
            // Why, and what the provider reported — one entry per refund it
            // could not settle.
            $table->json('attention')->nullable()->after('needs_attention');
            $table->timestamp('resolved_at')->nullable()->after('attention');
            // CENTRAL users — unenforced across the schema boundary.
            $table->unsignedBigInteger('resolved_by')->nullable()->after('resolved_at');
            $table->string('resolution_note', 500)->nullable()->after('resolved_by');

            // "What still needs a person?", newest first.
            $table->index(['needs_attention', 'resolved_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_events', function (Blueprint $table): void {
            $table->dropIndex(['needs_attention', 'resolved_at', 'received_at']);
            $table->dropColumn(['needs_attention', 'attention', 'resolved_at', 'resolved_by', 'resolution_note']);
        });
    }
};
