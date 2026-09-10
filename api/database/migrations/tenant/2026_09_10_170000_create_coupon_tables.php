<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Coupons (P16) — codes an academy hands out, and the orders they
 * were used on. See docs/COUPONS.md.
 *
 * Every reference is the coupon's ID, never its code: Tutor keys its coupon
 * usage on the mutable code (TUTOR_AUDIT §3), so renaming one orphans its
 * history. The code is SNAPSHOTTED onto the order for the receipt instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // Stored uppercase; matched by normalising both sides (Coupon::normalise).
            $table->string('code', 64)->unique();
            $table->string('description', 255)->nullable();

            $table->string('discount_type', 20);
            // Whole percent, 1–100. Rounded DOWN when applied.
            $table->unsignedTinyInteger('percent_off')->nullable();
            // Minor units, in `currency`.
            $table->unsignedBigInteger('amount_off_minor')->nullable();
            // Required for a fixed amount or a minimum spend — both are money,
            // and money without a currency is a number. A percent coupon with
            // no minimum works in any currency.
            $table->char('currency', 3)->nullable();

            // False means "only the products in coupon_products".
            $table->boolean('applies_to_all')->default(true);
            $table->unsignedBigInteger('min_subtotal_minor')->nullable();

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('max_redemptions_per_user')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            // CENTRAL users — unenforced across the schema boundary.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('coupon_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            // RESTRICT: a coupon somebody has used is switched off, never
            // deleted. DeleteCoupon refuses first, with a reason; this is the
            // floor under it.
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            // One coupon per order. Whether a redemption COUNTS is read from
            // its order's status and age — see CouponRules.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            // CENTRAL users — unenforced across the schema boundary.
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('discount_minor');
            $table->char('currency', 3);
            $table->timestamps();

            // "How many times has THIS person used it?"
            $table->index(['coupon_id', 'user_id']);
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('currency')->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('coupon_id')->nullable()->after('currency')->constrained()->nullOnDelete();
            // What the learner typed, frozen: editing the coupon later must
            // not rewrite a receipt.
            $table->string('coupon_code', 64)->nullable()->after('coupon_id');
        });

        /*
         * The order's discount, SPLIT across its lines — `total_minor` is then
         * net of it. Every revenue figure sums line totals, so this is what
         * keeps them equal to the order total (docs/FEATURE_MATRIX.md J9).
         */
        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('discount_minor')->default(0)->after('unit_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('discount_minor');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('coupon_code');
        });

        Schema::table('carts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupon_products');
        Schema::dropIfExists('coupons');
    }
};
