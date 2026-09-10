<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. A day's revenue is NET of the refunds completed that day, and a day
 * with more refunded than sold is a real, negative day. Unsigned columns could
 * not hold it.
 *
 * Refunds come off on the day they happen — never on the day of the sale, so
 * an old report never changes — which keeps every dashboard, series and
 * export net of refunds without any of them learning what a refund is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_daily_course', function (Blueprint $table): void {
            $table->bigInteger('revenue_minor')->default(0)->change();
        });

        Schema::table('analytics_daily_platform', function (Blueprint $table): void {
            $table->bigInteger('revenue_minor')->default(0)->change();
            $table->bigInteger('download_revenue_minor')->default(0)->change();
        });

        Schema::table('analytics_daily_instructor', function (Blueprint $table): void {
            $table->bigInteger('revenue_minor')->default(0)->change();
        });
    }

    /** Fails on any negative day already stored — rebuild the rollups after. */
    public function down(): void
    {
        Schema::table('analytics_daily_course', function (Blueprint $table): void {
            $table->unsignedBigInteger('revenue_minor')->default(0)->change();
        });

        Schema::table('analytics_daily_platform', function (Blueprint $table): void {
            $table->unsignedBigInteger('revenue_minor')->default(0)->change();
            $table->unsignedBigInteger('download_revenue_minor')->default(0)->change();
        });

        Schema::table('analytics_daily_instructor', function (Blueprint $table): void {
            $table->unsignedBigInteger('revenue_minor')->default(0)->change();
        });
    }
};
