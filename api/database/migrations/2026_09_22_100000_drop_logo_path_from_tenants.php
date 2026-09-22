<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL. `tenants.logo_path` was declared with the table in Phase 1 and
 * nothing ever wrote it.
 *
 * The logo is a `Media` reference now, like every other image — a
 * `logo_media_id` in the academy's `data` blob, next to `registration_mode`,
 * resolved against the academy's own `media` table (`Tenant::logoUrl()`). A
 * path column beside it would be a second link to the same file, free to
 * disagree with the first, so it goes rather than being kept in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('is_active');
        });
    }
};
