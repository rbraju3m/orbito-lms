<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. Digital downloads — a file an academy sells.
 *
 * Buying one grants the right to FETCH a file, never an enrolment. Delivery is
 * the signed media URL certificates already use (ADR-09): no token, no count,
 * no expiry on the grant. The `docs/DATABASE.md` sketch drew all three, and
 * they would have been a second delivery mechanism that could not even count
 * what it claimed to — `media.download` streams on the signature alone, with
 * no user. See docs/DOWNLOADS.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 200)->unique();

            $table->string('title', 180);
            $table->string('subtitle', 255)->nullable();
            $table->text('description')->nullable();

            /*
             * The file. Nullable only so a draft can exist before its upload
             * finishes — the publish checklist refuses a download without one.
             *
             * No foreign-key RESTRICT here, deliberately, because it would be
             * a false comfort: `DeleteMedia` SOFT-deletes the row, which no
             * foreign key sees, and removes the bytes BEFORE that. The guard
             * that keeps a buyer's file alive lives in `DeleteMedia` itself.
             */
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('thumbnail_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('pricing_model', 20)->default('one_time');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('media_id');
        });

        /*
         * The ENTITLEMENT, not a delivery. One row per person per download;
         * owning it twice is a refund request, so the unique index refuses the
         * second and `GrantDownload` catches the violation rather than
         * checking first (§ Phase 14: make idempotency a constraint).
         */
        Schema::create('download_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('download_id')->constrained()->cascadeOnDelete();
            // Central user id, unenforced across the boundary (§ Multi-tenancy).
            $table->unsignedBigInteger('user_id');

            $table->string('source', 20);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('granted_at');
            // Refunds are declared and not built anywhere. When they land, a
            // refund revokes the grant rather than deleting the record of the
            // sale.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->unique(['download_id', 'user_id']);
            $table->index(['user_id', 'revoked_at']);
        });

        /*
         * Downloads have no course, so their money reaches no course or
         * instructor figure. Without its own line, the platform total simply
         * stops equalling the sum of course revenue the day one sells, and a
         * dashboard shows two different numbers for one fact. With it, the
         * invariant is "courses + downloads = platform" and it is tested.
         *
         * Safe to add: every rollup is rebuildable (`analytics:rollup`).
         */
        Schema::table('analytics_daily_platform', function (Blueprint $table): void {
            $table->unsignedBigInteger('download_revenue_minor')->default(0)->after('revenue_minor');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_daily_platform', function (Blueprint $table): void {
            $table->dropColumn('download_revenue_minor');
        });

        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('downloads');
    }
};
