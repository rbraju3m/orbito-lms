<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL, and first: everything else in this database points at it.
 *
 * One row per academy, one MySQL schema behind each. The schema name derives
 * from `id`, so the id must never change — renaming an academy changes `slug`
 * and `name`, never the key.
 *
 * `data` is stancl's virtual-column blob. Anything the platform filters or
 * sorts on is a real column and must also appear in Tenant::getCustomColumns();
 * anything else lives in the JSON, which is what lets an academy carry
 * settings we have not thought of yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary();

            $table->string('slug', 100)->unique();
            $table->string('name', 180);

            $table->string('status', 20)->default('pending');
            // The operator's kill switch, separate from `status`: an academy
            // can be active and still switched off.
            $table->boolean('is_active')->default(true);

            $table->string('logo_path')->nullable();
            $table->string('support_email')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->json('data')->nullable();

            $table->timestamps();

            // The platform admin list: filter by state, newest first.
            $table->index(['status', 'created_at']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
