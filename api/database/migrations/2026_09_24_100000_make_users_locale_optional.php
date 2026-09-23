<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL. `users.locale` NULL now means "never chosen — the academy decides"
 * (docs/I18N.md §2).
 *
 * It defaulted to 'en', so every account looked like a person who had picked
 * English, and an academy switching its default to Bengali would have changed
 * nothing for anybody already in it. Every stored 'en' is cleared: no screen
 * ever offered a choice — the profile form posted a free-text box prefilled
 * with 'en' on every save — so none of them is somebody's decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable()->default(null)->change();
        });

        DB::table('users')->where('locale', 'en')->update(['locale' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('locale')->update(['locale' => 'en']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->change();
        });
    }
};
