<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. The academy's blog (O1) — the first half of `Content` that renders
 * on the public site (docs/BLOG.md).
 *
 * Posts only. Categories and tags are drawn in DATABASE.md §12 and deliberately
 * not built: an academy with a handful of posts needs a list, not a taxonomy,
 * and a tag table nobody fills is a filter that always comes back empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // The public address. Stable once the post is published — a link
            // somebody shared must keep landing (`UpdatePostRequest`).
            $table->string('slug', 190)->unique();

            // Central user id, unenforced across the boundary (§ Multi-tenancy).
            $table->unsignedBigInteger('author_id');

            $table->string('title', 200);
            $table->string('excerpt', 500)->nullable();
            // Author HTML, sanitised on WRITE (`RichTextSanitizer`), so what is
            // stored is safe to render anywhere without a second pass.
            $table->mediumText('body')->nullable();

            /*
             * A cover image. Null on delete, like every other cover: it is
             * decoration, and a post without its picture is still a post. A
             * SOFT-deleted file is invisible to this key — the same caveat as a
             * download's thumbnail (docs/DOWNLOADS.md §7).
             */
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('status', 20)->default('draft');
            /*
             * When it goes, or went, live. A published post whose time is in the
             * future is SCHEDULED: `Post::published()` compares against the
             * clock, so it appears on its own with nothing to sweep (§ Patterns
             * established in Phase 15).
             */
            $table->timestamp('published_at')->nullable();

            // What a search result and a shared link show. Null means "use the
            // title and the excerpt".
            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 300)->nullable();

            $table->timestamps();

            // The public list: live posts, newest first.
            $table->index(['status', 'published_at']);
            // The admin list: most recently worked on first.
            $table->index('updated_at');
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
