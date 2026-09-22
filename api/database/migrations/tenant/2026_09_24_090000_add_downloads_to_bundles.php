<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A bundle may hold downloads as well as courses.
 *
 * Two nullable foreign keys and a CHECK that exactly one is set, on each of
 * the three tables that name a bundle's contents — not a morph. A morph drops
 * the foreign keys and buys no polymorphism: the grant, the allocation and the
 * report all still switch on the type. See docs/BUNDLES.md §9.
 *
 * Every existing row is a course row, so nothing is backfilled; the CHECK
 * holds for them the moment it is added.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the column that says whose it is */
    private const TABLES = [
        'bundle_items' => 'bundle_id',
        'order_item_allocations' => 'order_item_id',
        'refund_line_allocations' => 'refund_line_id',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $parent) {
            Schema::table($table, function (Blueprint $blueprint) use ($parent): void {
                $blueprint->foreignId('course_id')->nullable()->change();
                $blueprint->foreignId('download_id')->nullable()->after('course_id')
                    ->constrained()->cascadeOnDelete();

                // The same download twice would be charged once and counted twice.
                $blueprint->unique([$parent, 'download_id']);
                $blueprint->index('download_id');
            });

            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s_one_target CHECK ((course_id IS NULL) <> (download_id IS NULL))',
                $table,
                $table,
            ));
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $parent) {
            DB::statement(sprintf('ALTER TABLE %s DROP CHECK %s_one_target', $table, $table));

            // A download row has no course to fall back to; it cannot survive
            // the column going NOT NULL again.
            DB::table($table)->whereNotNull('download_id')->delete();

            Schema::table($table, function (Blueprint $blueprint) use ($parent): void {
                $blueprint->dropForeign(['download_id']);
                $blueprint->dropUnique([$parent, 'download_id']);
                $blueprint->dropIndex(['download_id']);
                $blueprint->dropColumn('download_id');
                $blueprint->foreignId('course_id')->nullable(false)->change();
            });
        }
    }
};
