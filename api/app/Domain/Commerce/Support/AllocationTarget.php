<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Support;

use InvalidArgumentException;

/**
 * What a share of a bundle line is attributed to: a course or a download.
 *
 * A course and a download can share a numeric id, so an allocation cannot be
 * keyed on the id alone. The key is `course:ID` / `download:ID`, and
 * `columns()` turns one back into the pair of nullable foreign keys the
 * allocation tables carry (docs/BUNDLES.md §9).
 */
final class AllocationTarget
{
    public const COURSE = 'course';

    public const DOWNLOAD = 'download';

    public static function course(int $id): string
    {
        return self::COURSE.':'.$id;
    }

    public static function download(int $id): string
    {
        return self::DOWNLOAD.':'.$id;
    }

    /** The key of a stored allocation row, from whichever column it sets. */
    public static function of(?int $courseId, ?int $downloadId): string
    {
        return $courseId !== null ? self::course($courseId) : self::download((int) $downloadId);
    }

    /** @return array{course_id: int|null, download_id: int|null} */
    public static function columns(string $key): array
    {
        [$type, $id] = self::parse($key);

        return [
            'course_id' => $type === self::COURSE ? $id : null,
            'download_id' => $type === self::DOWNLOAD ? $id : null,
        ];
    }

    /**
     * Courses before downloads, then by id — the order ties are broken in, so
     * a bundle of courses alone splits exactly as it did when the key WAS the
     * course id.
     *
     * @return array{0: int, 1: int}
     */
    public static function sortKey(string $key): array
    {
        [$type, $id] = self::parse($key);

        return [$type === self::COURSE ? 0 : 1, $id];
    }

    /** @return array{0: string, 1: int} */
    private static function parse(string $key): array
    {
        $parts = explode(':', $key, 2);

        if (count($parts) !== 2 || ! in_array($parts[0], [self::COURSE, self::DOWNLOAD], true)) {
            throw new InvalidArgumentException("Not an allocation target: {$key}");
        }

        return [$parts[0], (int) $parts[1]];
    }
}
