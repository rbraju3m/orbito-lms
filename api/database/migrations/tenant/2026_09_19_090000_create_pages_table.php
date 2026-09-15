<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Pages built from blocks on the academy's public site (O2,
 * docs/PAGES.md).
 *
 * DIFFERS FROM THE SKETCH: DATABASE.md §12 drew a `page_blocks` table with a
 * `position` column. The blocks are a JSON LIST on the page instead, because:
 *
 *  - the builder saves the WHOLE list every time (§ Patterns established in
 *    Phase 5: bulk moves take the whole collection, never a delta), so a row
 *    per block would be rewritten in full on every save anyway;
 *  - nothing ever queries INTO a block — a page is read whole, by slug;
 *  - a `position` column is a second ordering mechanism to keep consistent,
 *    and the list's own order is the only one there is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // The public address, under `/a/{academy}/p/`. Locked once the page
            // has been published, like a blog post's (docs/PAGES.md §2).
            $table->string('slug', 190)->unique();

            // Central user id, unenforced across the boundary (§ Multi-tenancy).
            $table->unsignedBigInteger('author_id');

            $table->string('title', 200);

            // The ordered list of blocks — see `PageBlocks` for its shape and
            // `BlockType` for the closed set of what a block may be.
            $table->json('blocks');

            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            // Linked from the public site's header.
            $table->boolean('show_in_nav')->default(false);

            /*
             * 'home' on the ONE page that is the academy's front page, null on
             * every other. The UNIQUE index is what makes two front pages
             * impossible — MySQL allows any number of NULLs — and `SetHomePage`
             * catches the violation rather than checking first (§ Patterns
             * established in Phase 14: make it a constraint, not a check).
             */
            $table->string('home_key', 10)->nullable()->unique();

            $table->string('seo_title', 200)->nullable();
            $table->string('seo_description', 300)->nullable();

            $table->timestamps();

            // The header's nav: published, flagged, oldest first.
            $table->index(['status', 'show_in_nav', 'created_at']);
            // The admin list: most recently worked on first.
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
