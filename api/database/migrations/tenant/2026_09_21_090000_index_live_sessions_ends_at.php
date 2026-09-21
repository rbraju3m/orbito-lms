<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. `LiveSession::upcoming()` filters on `ends_at`, and has since P15 —
 * the reminder sweep and the page builder's event block both read it — with
 * no index behind it. The public events list now reads it on every visit to
 * an academy's front page, which made the gap worth closing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table): void {
            $table->dropIndex(['ends_at']);
        });
    }
};
