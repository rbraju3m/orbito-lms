<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Models\Bundle;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\Download;

/**
 * The single definition of "this bundle is ready to sell".
 *
 * Rendered by the studio and enforced by `ChangeBundleStatus`, so the list an
 * author sees cannot disagree with what the server will accept — the same
 * shape as `PublishChecklist` (§10) and `SubmissionRules` (§14).
 */
final class BundlePublishChecklist
{
    /**
     * @return list<array{code: string, field: string, message: string, blocking: bool, passed: bool}>
     */
    public function evaluate(Bundle $bundle): array
    {
        $bundle->loadMissing(['courses', 'downloads', 'product.prices']);

        $unpublished = $this->unpublishedCourses($bundle);
        $unpublishedDownloads = $this->unpublishedDownloads($bundle);

        return [
            $this->check(
                'title_present',
                'title',
                'Give the bundle a title of at least 5 characters.',
                blocking: true,
                passed: mb_strlen(trim($bundle->title)) >= 5,
            ),
            $this->check(
                'description_present',
                'description',
                'Write a description of at least 50 characters so buyers know what is in it.',
                blocking: true,
                passed: mb_strlen(trim(strip_tags((string) $bundle->description))) >= 50,
            ),
            /*
             * Two things, not one — and two THINGS, not two courses: a course
             * and its workbook is a real bundle. A "bundle" of a single course
             * is that course with a second price and a second place to keep it
             * in step — every question about which one a buyer got has two
             * answers.
             */
            $this->check(
                'has_two_items',
                'items',
                'A bundle needs at least two things in it. One course is just that course.',
                blocking: true,
                passed: $bundle->courses->count() + $bundle->downloads->count() >= 2,
            ),
            $this->check(
                'courses_published',
                'courses',
                $this->unpublishedMessage($unpublished),
                blocking: true,
                passed: $unpublished === [],
            ),
            $this->check(
                'downloads_published',
                'downloads',
                $this->unpublishedDownloadsMessage($unpublishedDownloads),
                blocking: true,
                passed: $unpublishedDownloads === [],
            ),
            $this->check(
                'price_configured',
                'price',
                'Set a price before publishing. A bundle with no price cannot be bought.',
                blocking: true,
                passed: $this->hasPrice($bundle),
            ),
            $this->check(
                'thumbnail_present',
                'thumbnail_media_id',
                'A thumbnail makes the bundle far more likely to be opened.',
                blocking: false,
                passed: $bundle->thumbnail_media_id !== null,
            ),
            $this->check(
                'cheaper_than_parts',
                'price',
                'A bundle priced at or above the sum of its parts gives nobody a reason to buy it.',
                blocking: false,
                passed: $this->isCheaperThanParts($bundle),
            ),
        ];
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function blockingFailures(Bundle $bundle): array
    {
        $failures = [];

        foreach ($this->evaluate($bundle) as $check) {
            if ($check['blocking'] && ! $check['passed']) {
                $failures[] = [
                    'field' => $check['field'],
                    'code' => $check['code'],
                    'message' => $check['message'],
                ];
            }
        }

        return $failures;
    }

    public function isPublishable(Bundle $bundle): bool
    {
        return $this->blockingFailures($bundle) === [];
    }

    /**
     * Named, not counted. "3 courses are not published" sends the author
     * hunting; naming them is the difference between a checklist and a
     * complaint.
     *
     * @return list<string>
     */
    private function unpublishedCourses(Bundle $bundle): array
    {
        return $bundle->courses
            ->reject(fn (Course $course): bool => $course->status->isLive())
            ->pluck('title')
            ->values()
            ->all();
    }

    /**
     * Named, for the same reason — a draft download would be granted and
     * then refused to its buyer at the fetch.
     *
     * @return list<string>
     */
    private function unpublishedDownloads(Bundle $bundle): array
    {
        return $bundle->downloads
            ->reject(fn (Download $download): bool => $download->status->isLive())
            ->pluck('title')
            ->values()
            ->all();
    }

    /** @param  list<string>  $titles */
    private function unpublishedDownloadsMessage(array $titles): string
    {
        if ($titles === []) {
            return 'Every download in the bundle is published.';
        }

        return 'These downloads are not published, so a buyer could not fetch them: "'
            .implode('", "', $titles).'".';
    }

    /** @param  list<string>  $titles */
    private function unpublishedMessage(array $titles): string
    {
        if ($titles === []) {
            return 'Every course in the bundle is published.';
        }

        return 'These courses are not published, so a buyer could not open them: "'
            .implode('", "', $titles).'".';
    }

    private function hasPrice(Bundle $bundle): bool
    {
        return $bundle->product?->prices->isNotEmpty() ?? false;
    }

    /**
     * Advisory only, and per currency: a bundle is allowed to cost what the
     * academy says it costs. This just says out loud that nobody will buy it.
     */
    private function isCheaperThanParts(Bundle $bundle): bool
    {
        $product = $bundle->product;

        if ($product === null || $product->prices->isEmpty()) {
            // Nothing to compare. The blocking price check already covers it.
            return true;
        }

        foreach ($product->prices as $price) {
            $parts = $this->partsTotal($bundle, $price->currency);

            // No priced parts at all (every course free) means there is
            // nothing to undercut, so the advice does not apply.
            if ($parts > 0 && $price->effectiveMinor() >= $parts) {
                return false;
            }
        }

        return true;
    }

    private function partsTotal(Bundle $bundle, string $currency): int
    {
        $total = 0;

        foreach ($bundle->courses as $course) {
            $course->loadMissing('product.prices');
            $total += $course->product?->priceIn($currency)?->effectiveMinor() ?? 0;
        }

        foreach ($bundle->downloads as $download) {
            $download->loadMissing('product.prices');
            $total += $download->product?->priceIn($currency)?->effectiveMinor() ?? 0;
        }

        return $total;
    }

    /** @return array{code: string, field: string, message: string, blocking: bool, passed: bool} */
    private function check(
        string $code,
        string $field,
        string $message,
        bool $blocking,
        bool $passed,
    ): array {
        return compact('code', 'field', 'message', 'blocking', 'passed');
    }
}
