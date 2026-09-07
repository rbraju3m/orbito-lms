<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Domain\Catalog\Enums\PricingModel;
use App\Domain\Catalog\Models\Course;

/**
 * The single definition of "ready to publish".
 *
 * The Studio UI renders this list, and PublishCourse enforces it. Because both
 * read the same rules, the checklist a user sees can never disagree with what
 * the server will accept.
 *
 * Curriculum rules join in Phase 5; commerce rules in Phase 10.
 */
final class PublishChecklist
{
    /**
     * @return list<array{code: string, field: string, message: string, blocking: bool, passed: bool}>
     */
    public function evaluate(Course $course): array
    {
        $course->loadMissing(['detail', 'tags']);

        return [
            $this->check(
                'title_present',
                'title',
                'Give the course a title of at least 5 characters.',
                blocking: true,
                passed: mb_strlen(trim($course->title)) >= 5,
            ),
            $this->check(
                'description_present',
                'description',
                'Write a description of at least 50 characters so learners know what they are getting.',
                blocking: true,
                passed: mb_strlen(trim(strip_tags((string) $course->description))) >= 50,
            ),
            $this->check(
                'category_present',
                'category_id',
                'Choose a category so the course can be found.',
                blocking: true,
                passed: $course->category_id !== null,
            ),
            $this->check(
                'price_configured',
                'pricing_model',
                'A paid course needs a price before it can be published.',
                blocking: true,
                // Pricing lands in Phase 10; until then only free courses can
                // satisfy this, which is honest rather than silently passing.
                passed: $course->pricing_model === PricingModel::Free,
            ),
            $this->check(
                'thumbnail_present',
                'thumbnail_media_id',
                'A thumbnail makes the course far more likely to be opened.',
                blocking: false,
                passed: $course->thumbnail_media_id !== null,
            ),
            $this->check(
                'subtitle_present',
                'subtitle',
                'A one-line subtitle helps learners scan the catalogue.',
                blocking: false,
                passed: $course->subtitle !== null && trim($course->subtitle) !== '',
            ),
            $this->check(
                'objectives_present',
                'objectives',
                'List what learners will be able to do by the end.',
                blocking: false,
                passed: is_array($course->detail?->objectives) && $course->detail->objectives !== [],
            ),
        ];
    }

    /** @return list<array{field: string, code: string, message: string}> */
    public function blockingFailures(Course $course): array
    {
        $failures = [];

        foreach ($this->evaluate($course) as $check) {
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

    public function isPublishable(Course $course): bool
    {
        return $this->blockingFailures($course) === [];
    }

    /** @return array{code: string, field: string, message: string, blocking: bool, passed: bool} */
    private function check(string $code, string $field, string $message, bool $blocking, bool $passed): array
    {
        return compact('code', 'field', 'message', 'blocking', 'passed');
    }
}
