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
     * Permission required to write into this collection, or null when nothing
     * may upload into it at all.
     *
     * Declared from Phase 4 and never CALLED until Phase 16, so every
     * collection was gated by `media.upload` alone — which a student holds,
     * for their submissions. It is enforced now by `StoreMediaRequest`. Only
     * the two collections below differ from before; tightening the authoring
     * collections to authoring permissions is a separate decision, recorded as
     * debt (docs/ROLES_PERMISSIONS.md footnote ⁴ claims it already happens).
     */
    public function uploadPermission(): ?string
    {
        return match ($this) {
            // Somebody who can upload into this can stock a shop.
            self::Download => 'download.manage',
            // Rendered by the platform, never uploaded. A certificate a user
            // could upload is a certificate a user could forge.
            self::Certificate => null,
            default => 'media.upload',
        };
    }
}
