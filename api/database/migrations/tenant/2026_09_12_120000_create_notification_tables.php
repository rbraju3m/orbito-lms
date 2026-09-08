<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT. The inbox and the switches that govern it.
 *
 * Notifications are per-ACADEMY, not per-account. The same person teaching in
 * one academy and learning in another has two inboxes and two sets of
 * preferences, because "email me about new questions" is a statement about a
 * role somebody holds somewhere — not about them (§ Multi-tenancy).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Laravel's own notifications shape: uuid PK, a morph to the
         * notifiable, and the frozen payload as JSON.
         *
         * `notifiable_id` points at the CENTRAL users table, so there is no
         * foreign key — the same unenforced boundary as announcements.author_id.
         * `notifiable_type` is the morph alias ('user'), which is why the map
         * in AuthServiceProvider is enforced rather than advisory.
         */
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            /*
             * The notification TYPE KEY ('announcement.published'), not a PHP
             * class name. Laravel stores `get_class($notification)` by
             * default; a stored FQCN turns moving a class between namespaces
             * into a data migration, for exactly the reason the morph map
             * exists.
             */
            $table->string('type', 64);

            $table->string('notifiable_type', 32);
            $table->unsignedBigInteger('notifiable_id');

            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The inbox itself: one person's notifications, newest first.
            $table->index(['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_inbox_index');

            /*
             * The bell badge. A count the SPA polls must never be a scan, and
             * read_at leads with its NULLs so the unread rows are the short
             * side of the index.
             */
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });

        /*
         * OVERRIDES ONLY. A missing row means "whatever this type defaults
         * to", so shipping a new notification type does not need a backfill
         * across every academy, and changing a default actually reaches the
         * people who never touched the setting.
         *
         * `event_key` is a plain string rather than a cast enum: a type
         * retired in a later phase leaves rows behind, and reading somebody's
         * preferences must not become fatal because one of them names a case
         * that no longer exists.
         */
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event_key', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();

            $table->unique(['user_id', 'event_key', 'channel']);
            // Every read is "this person's overrides", loaded in one go.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
