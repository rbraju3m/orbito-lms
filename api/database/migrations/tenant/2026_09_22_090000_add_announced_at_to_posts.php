<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. When `post.published` was told about a post — once, ever
 * (`AnnouncePost`).
 *
 * A scheduled post goes live by the clock with nothing written, so until now
 * nothing ever announced it (docs/BLOG.md §6). `blog:announce` sweeps for a
 * live post with no `announced_at`; the column is the "did we already?" flag,
 * on the row rather than in a queue, for the reason `reminder_sent_at` is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->timestamp('announced_at')->nullable()->after('published_at');

            // The sweep: published, unannounced, time come.
            $table->index(['status', 'announced_at', 'published_at']);
        });

        /*
         * Every post already live counts as announced. Those published by hand
         * were; a scheduled one that went live before this column existed was
         * not, and announcing it now — days late, on deploy, in a burst — is
         * worse than never. Its integrations missed it either way.
         */
        DB::table('posts')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->update(['announced_at' => DB::raw('published_at')]);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropIndex(['status', 'announced_at', 'published_at']);
            $table->dropColumn('announced_at');
        });
    }
};
