<?php

declare(strict_types=1);

namespace App\Domain\Media\Enums;

/**
 * A collection decides the disk, the size cap and the accepted MIME types.
 * Upload rules live with the collection so no controller invents its own.
 */
enum MediaCollection: string
{
    case Avatar = 'avatar';
    case CourseThumbnail = 'course_thumbnail';
    case CourseIntroVideo = 'course_intro_video';
    case CategoryImage = 'category_image';
    case LessonVideo = 'lesson_video';
    case LessonAttachment = 'lesson_attachment';
    case Submission = 'submission';
    /* Generated, never uploaded — see StoreGeneratedMedia. */
    case Certificate = 'certificate';
    /* A file an academy SELLS (P16). See docs/DOWNLOADS.md. */
    case Download = 'download';

    public function disk(): MediaDisk
    {
        return match ($this) {
            self::Avatar, self::CourseThumbnail, self::CategoryImage => MediaDisk::Public,
            // Private, and served only through a signed URL: a certificate
            // names a person, and a guessable public path would list them.
            default => MediaDisk::Private,
        };
    }

    /** @return list<string> */
    public function allowedMimes(): array
    {
        return match ($this) {
            self::Avatar, self::CourseThumbnail, self::CategoryImage => [
                'image/jpeg', 'image/png', 'image/webp', 'image/avif',
            ],
            self::CourseIntroVideo, self::LessonVideo => [
                'video/mp4', 'video/webm', 'video/quicktime',
            ],
            self::Certificate => ['application/pdf'],
            /*
             * Broad, because a download is "an eBook, a template, an audio
             * pack" — and never an executable. Nothing scans uploads anywhere
             * in this codebase yet, so the allowlist IS the defence for a file
             * handed to people who paid for it. Types are sniffed from the
             * bytes in StoreUploadedMedia, never taken from the client.
             */
            self::Download => [
                'application/pdf', 'application/epub+zip', 'application/zip',
                'image/jpeg', 'image/png', 'image/webp',
                'audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav',
                'video/mp4',
                'text/plain', 'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            self::LessonAttachment, self::Submission => [
                'image/jpeg', 'image/png', 'image/webp',
                'application/pdf', 'application/zip',
                'text/plain', 'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        };
    }

    public function maxBytes(): int
    {
        return match ($this) {
            self::Avatar => 2 * 1024 * 1024,
            self::CourseThumbnail, self::CategoryImage => 5 * 1024 * 1024,
            self::CourseIntroVideo, self::LessonVideo => 2 * 1024 * 1024 * 1024,
            self::LessonAttachment => 50 * 1024 * 1024,
            self::Submission => 25 * 1024 * 1024,
            // We generate it, so the cap is a sanity bound rather than a
            // defence — a certificate that large means the renderer is wrong.
            self::Certificate => 5 * 1024 * 1024,
            /*
             * Every byte goes through PHP — the direct-to-storage flow
             * `MediaStatus::Pending` was declared for was never built. The web
             * server accepts far more than this; the cap is what one request
             * should reasonably carry, and a larger pack waits on that flow.
             */
            self::Download => 500 * 1024 * 1024,
        };
    }

    /**
     * Who may write into this collection: ANY of these, held anywhere (see
     * `HasRoles::holdsPermissionAnywhere()`), or nobody when null.
     *
     * A list, not one key, because admins hold the `.any` permissions and not
     * the `.own` ones — a single `.own` key here would lock them out.
     *
     * This decides who may put BYTES into a collection, which is what it
     * costs to store them. It is not who may attach them: that is checked
     * when the file is referenced, against its owner and its collection
     * (`ValidatesOwnedMedia`), and against the resource's own policy.
     *
     * Declared from Phase 4 and never called until Phase 16, so every
     * collection was open to any `media.upload` holder — a student could put a
     * 2 GB `lesson_video` on the academy's storage bill.
     *
     * @return list<string>|null
     */
    public function uploadPermissions(): ?array
    {
        return match ($this) {
            // Everybody: a profile picture, and the work a learner hands in.
            // `UpdateAssignmentRequest` also accepts submission files as
            // assignment attachments, which this leaves working.
            self::Avatar, self::Submission => ['media.upload'],
            // Course covers — and bundle and download covers, which use this
            // collection too.
            self::CourseThumbnail => ['course.update.own', 'course.update.any', 'bundle.manage', 'download.manage'],
            self::CourseIntroVideo => ['course.update.own', 'course.update.any'],
            self::LessonVideo => ['curriculum.manage.own', 'curriculum.manage.any'],
            // Lesson attachments and assignment attachments.
            self::LessonAttachment => [
                'curriculum.manage.own', 'curriculum.manage.any',
                'assignment.manage.own', 'assignment.manage.any',
            ],
            // Whoever manages categories (CourseCategoryPolicy::manage).
            self::CategoryImage => ['settings.update'],
            // Somebody who can upload into this can stock a shop.
            self::Download => ['download.manage'],
            // Rendered by the platform, never uploaded. A certificate a user
            // could upload is a certificate a user could forge.
            self::Certificate => null,
        };
    }

    /**
     * Whether a person's UNUSED files here count against `UploadQuota`.
     *
     * The collections open to every account — the ones `uploadPermissions()`
     * grants on `media.upload` alone, and a test holds the two together.
     * Everything else is authoring, gated by an authoring permission, and
     * what it stores is the academy's plan figure rather than one person's.
     */
    public function hasPersonalQuota(): bool
    {
        return match ($this) {
            self::Avatar, self::Submission => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function withPersonalQuota(): array
    {
        return array_values(array_map(
            static fn (self $collection): string => $collection->value,
            array_filter(self::cases(), static fn (self $collection): bool => $collection->hasPersonalQuota()),
        ));
    }
}
