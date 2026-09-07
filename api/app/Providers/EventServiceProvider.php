<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Events\CourseCreated;
use App\Domain\Catalog\Events\CourseDeleted;
use App\Domain\Catalog\Events\CourseStatusChanged;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Listeners\RefreshCourseCurriculumCounters;
use App\Domain\Identity\Events\InstructorReviewed;
use App\Domain\Identity\Events\UserLoggedIn;
use App\Domain\Identity\Events\UserRegistered;
use App\Domain\Identity\Listeners\SendEmailVerification;
use App\Domain\Identity\Listeners\TouchLastSeen;
use App\Domain\Media\Events\MediaDeleted;
use App\Domain\Media\Events\MediaUploaded;
use App\Domain\Platform\Listeners\TrackCourseUsage;
use App\Domain\Platform\Listeners\TrackInstructorUsage;
use App\Domain\Platform\Listeners\TrackStorageUsage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The domain event catalogue. Cross-context reactions are wired here and
 * nowhere else, so the fan-out from any event is readable in one place.
 */
final class EventServiceProvider extends ServiceProvider
{
    /** @var array<class-string, list<class-string|array{0: class-string, 1: string}>> */
    private array $listen = [
        UserRegistered::class => [
            SendEmailVerification::class,
        ],
        UserLoggedIn::class => [
            TouchLastSeen::class,
        ],

        // Catalog and Media fire; Platform listens. Neither knows that billing
        // metrics exist — that is the dependency rule working as intended.
        CourseCreated::class => [
            [TrackCourseUsage::class, 'created'],
        ],
        CourseStatusChanged::class => [
            [TrackCourseUsage::class, 'statusChanged'],
        ],
        CourseDeleted::class => [
            [TrackCourseUsage::class, 'deleted'],
        ],
        MediaUploaded::class => [
            [TrackStorageUsage::class, 'uploaded'],
        ],
        MediaDeleted::class => [
            [TrackStorageUsage::class, 'deleted'],
        ],
        InstructorReviewed::class => [
            TrackInstructorUsage::class,
        ],

        // Curriculum fires; Catalog's denormalised counters follow. Progress
        // will join this list in Phase 6 to recount every enrollment.
        CurriculumChanged::class => [
            RefreshCourseCurriculumCounters::class,
        ],
    ];

    public function boot(): void
    {
        foreach ($this->listen as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }
}
