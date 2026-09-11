<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Refunds (P16) — money given back on an order, all of it or part of
 * it, and exactly which lines (and which bundle courses) gave it back, so
 * revenue reports stay honest after a refund as they were after the sale.
 * See docs/REFUNDS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // RESTRICT: an order money was given back on is a record for good.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            // The captured payment it went back through; null for one made
            // elsewhere and only recorded here.
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('method', 20);
            $table->string('status', 20)->default('pending');
            // Shown to the learner on their order. Write it for them.
            $table->string('reason', 500)->nullable();
            // Only ever true on the refund that takes an order to fully
            // refunded, and only if the admin did not choose otherwise.
            $table->boolean('revokes_access')->default(false);
            $table->string('external_id', 191)->nullable();
            // CENTRAL users — unenforced across the schema boundary.
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            // "What is still refundable on this order?"
            $table->index(['order_id', 'status']);
            // Revenue reports: refunds completed on a day.
            $table->index(['status', 'completed_at']);
        });

        Schema::create('refund_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['refund_id', 'order_item_id']);
            $table->index('order_item_id');
        });

        // Mirrors order_item_allocations: a refunded BUNDLE line, per course.
        Schema::create('refund_line_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['refund_line_id', 'course_id']);
            $table->index('course_id');
        });

        // Denormalised from COMPLETED refunds, maintained by CompleteRefund.
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('refunded_minor')->default(0)->after('total_minor');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('refunded_minor');
        });

        Schema::dropIfExists('refund_line_allocations');
        Schema::dropIfExists('refund_lines');
        Schema::dropIfExists('refunds');
    }
};
