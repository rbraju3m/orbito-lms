<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A webinar becomes a purchasable, and a place can be bought.
 *
 * `webinars.is_paid` and `webinars.product_id` were both declared in P15 and
 * neither was ever written — no authoring path set them, so every webinar in
 * the product was free. This is the slice that makes the flag mean something,
 * and it takes the column away on the way past.
 *
 * WHY THE COLUMN GOES. `products.purchasable_type/purchasable_id` already
 * points at the webinar, exactly as it points at a bundle and a download,
 * neither of which holds a `product_id`. Two links between the same two rows
 * are two things to keep in step and free to disagree — the same argument the
 * live migration itself makes about NOT putting a `course_item_id` on
 * `live_sessions`. The morph is the one that survives, because it is the one
 * every other purchasable already uses and the one `SyncProductForPurchasable`
 * is written against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });

        Schema::table('webinar_registrations', function (Blueprint $table): void {
            /*
             * Which order bought this place, so a refund can take back
             * EXACTLY what that order granted and nothing else — the same
             * column, for the same reason, as `download_grants.order_id`. A
             * place given away at a free webinar has none, and
             * `RevokeOrderAccess` must never touch it.
             */
            $table->foreignId('order_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('webinar_registrations', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropIndex(['order_id']);
            $table->dropColumn('order_id');
        });

        Schema::table('webinars', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('is_paid')
                ->constrained()->nullOnDelete();
        });
    }
};
