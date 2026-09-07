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

    public function disk(): MediaDisk
    {
        return match ($this) {
            self::Avatar, self::CourseThumbnail, self::CategoryImage => MediaDisk::Public,
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
        };
    }

    /** Permission required to write into this collection. */
    public function uploadPermission(): string
    {
        return 'media.upload';
    }
}
