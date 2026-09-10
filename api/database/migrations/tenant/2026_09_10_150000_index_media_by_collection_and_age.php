<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `media:sweep-unused` asks for one collection's files older than a cutoff,
 * across every owner. (owner_id, collection) cannot serve that; without this
 * the nightly sweep walks the whole media table in every academy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->index(['collection', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['collection', 'created_at']);
        });
    }
};
