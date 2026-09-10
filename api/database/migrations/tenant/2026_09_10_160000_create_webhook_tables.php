<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Outbound webhooks (ADR-12) — an academy's own endpoints, and every
 * delivery made to them. Per academy, like payment gateways: the academy
 * decides where its data goes, and holds the secret that proves it was us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('url', 2048);
            $table->string('description', 255)->nullable();
            // Encrypted by the model's cast; never returned by any resource.
            $table->text('secret');
            // Topic values (`WebhookTopic`). A handful per endpoint, read
            // whole — a pivot would be a join on every event for nothing.
            $table->json('events');
            $table->boolean('is_active')->default(true);

            // Deliveries that used up every attempt, in a row. Reset by any
            // success; past the threshold the endpoint switches itself off.
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamp('last_delivered_at')->nullable();

            // CENTRAL users — no FK can span databases (see media.owner_id).
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            // Asked on every domain event: "is anybody listening?"
            $table->index('is_active');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Cascades: deleting an endpoint must cancel what is still queued
            // for it. This is operational state, not an analytics fact.
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();

            // Shared by every delivery of one event, redeliveries included.
            $table->uuid('event_id');
            $table->string('topic', 64);
            // The exact bytes signed and sent, frozen when the event fired.
            $table->mediumText('body');

            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            // The receiver's reply, truncated. Theirs, and only for debugging.
            $table->text('response_body')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            // One endpoint's log, newest first.
            $table->index(['endpoint_id', 'id']);
            // Retention: `webhooks:prune` deletes settled rows by age.
            $table->index(['status', 'created_at']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
