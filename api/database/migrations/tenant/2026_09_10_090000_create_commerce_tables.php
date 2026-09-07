<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Commerce belongs to the academy that sells the course.
 *
 * ADR-13 decided the academy is the merchant of record: it connects its own
 * gateway credentials and the money never reaches the platform. So orders,
 * payments and the gateway accounts themselves all live in the academy's
 * schema, and there is no central ledger of learner money to keep.
 *
 * ADR-05 decides the flow: the client never reports success. A price is only
 * ever what the SERVER reads from these tables at the moment an order is
 * placed, and access is granted by a verified webhook — never by a redirect.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A gateway account per academy. Credentials are encrypted at rest by
         * the model's `encrypted` casts, never logged, and never returned by
         * any Resource — the API only ever says whether a gateway is connected.
         */
        Schema::create('payment_gateway_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 30);

            $table->text('credentials')->nullable();
            /*
             * Separate from `credentials` because it is used at a different
             * moment by different code: the secret verifies an INBOUND webhook
             * before anything is trusted, while credentials authenticate our
             * OUTBOUND calls. Rotating one must not disturb the other.
             */
            $table->text('webhook_secret')->nullable();

            $table->boolean('is_active')->default(false);
            $table->boolean('is_test_mode')->default(true);
            $table->timestamps();

            // One account per gateway per academy.
            $table->unique('gateway');
        });

        /*
         * What can be sold. Polymorphic from the start so a bundle (P16) or a
         * download needs no second checkout — the same reasoning as
         * `course_items` being one spine (ADR-01).
         */
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('purchasable_type', 50);
            $table->unsignedBigInteger('purchasable_id');

            $table->string('title', 180);
            $table->string('status', 20)->default('draft');

            $table->timestamps();

            // One product per sellable thing.
            $table->unique(['purchasable_type', 'purchasable_id']);
            $table->index('status');
        });

        /*
         * Integer minor units + ISO currency, never a float (ADR-04).
         *
         * A sale price is a separate column rather than an edit to `amount`,
         * so the original is still there when the sale ends — and so "was
         * £99" is a fact rather than something reconstructed from history.
         */
        Schema::create('product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('sale_amount_minor')->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();

            $table->timestamps();

            $table->unique(['product_id', 'currency']);
        });

        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Central users table — unenforced across the schema boundary.
            $table->unsignedBigInteger('user_id');
            $table->char('currency', 3);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One open cart per learner. Guest checkout is not in this phase,
            // and a session-keyed cart would need a second uniqueness rule.
            $table->unique('user_id');
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            /*
             * No quantity and no stored price. A course is bought once, and a
             * price captured when the item went into the cart is exactly the
             * stale figure ADR-05 exists to refuse — the order re-reads it.
             */
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Human-facing, sequential per academy, never the id.
            $table->string('number', 32)->unique();

            $table->unsignedBigInteger('user_id');
            $table->string('status', 30)->default('pending');

            $table->char('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            $table->timestamp('placed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            // The reconciliation sweep: orders stuck awaiting a webhook.
            $table->index(['status', 'placed_at']);
        });

        /*
         * The line snapshots title and price so a later edit to the product —
         * or its deletion — cannot rewrite what somebody was charged.
         */
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('purchasable_type', 50);
            $table->unsignedBigInteger('purchasable_id');
            $table->string('title_snapshot', 180);

            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('total_minor');

            $table->timestamps();

            $table->index('order_id');
            // "What did this learner buy?" and the access grant both read this.
            $table->index(['purchasable_type', 'purchasable_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('gateway', 30);
            // The gateway's own id. Nullable only between creating the row and
            // the gateway answering.
            $table->string('external_id', 191)->nullable();

            $table->string('status', 20)->default('initiated');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();

            $table->timestamps();

            // Two webhooks for one charge must not become two payments.
            $table->unique(['gateway', 'external_id']);
            $table->index(['order_id', 'status']);
        });

        /*
         * Webhook idempotency, and the audit trail for anything that touched
         * money. The unique key is what makes a replayed delivery a no-op
         * rather than a second enrolment (ADR-05).
         */
        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 30);
            $table->string('external_event_id', 191);
            $table->string('type', 100);

            $table->json('payload');
            $table->boolean('signature_verified')->default(false);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->unique(['gateway', 'external_event_id']);
            $table->index(['gateway', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('product_prices');
        Schema::dropIfExists('products');
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
