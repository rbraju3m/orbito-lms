<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Policies\QuizPolicy;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Catalog\Policies\CourseCategoryPolicy;
use App\Domain\Catalog\Policies\CoursePolicy;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Curriculum\Models\Resource;
use App\Domain\Curriculum\Policies\CurriculumPolicy;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Models\InstructorProfile;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\InstructorProfilePolicy;
use App\Domain\Identity\Policies\RolePolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Policies\MediaPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    private array $policies = [
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        InstructorProfile::class => InstructorProfilePolicy::class,
        Course::class => CoursePolicy::class,
        CourseCategory::class => CourseCategoryPolicy::class,
        Media::class => MediaPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerMorphMap();
        $this->registerSuperAdminBypass();
        $this->registerCurriculumGates();
    }

    /**
     * Scope types are stored as short aliases, not FQCNs — `role_assignments`
     * rows must survive a class being moved between namespaces.
     */
    private function registerMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'course' => Course::class,
            'media' => Media::class,
            'course_section' => CourseSection::class,
            'course_item' => CourseItem::class,
            'enrollment' => Enrollment::class,
            // Itemable aliases: course_items rows must survive these classes
            // moving between namespaces.
            'lesson' => Lesson::class,
            'resource' => Resource::class,
            'quiz' => Quiz::class,
        ]);
    }

    /**
     * Curriculum authorization always resolves through the parent course, so
     * these are Gates over a Course rather than policies on section/item —
     * which keeps course-scoped roles working without duplicating the logic on
     * three models.
     */
    private function registerCurriculumGates(): void
    {
        Gate::define(
            'view-curriculum',
            fn (User $user, Course $course) => app(CurriculumPolicy::class)->view($user, $course),
        );

        Gate::define(
            'manage-curriculum',
            fn (User $user, Course $course) => app(CurriculumPolicy::class)->manage($user, $course),
        );

        Gate::define(
            'manage-quiz',
            fn (User $user, Course $course) => app(QuizPolicy::class)->manage($user, $course),
        );

        Gate::define(
            'grade-quiz',
            fn (User $user, Course $course) => app(QuizPolicy::class)->grade($user, $course),
        );

        Gate::define(
            'view-quiz-attempts',
            fn (User $user, Course $course) => app(QuizPolicy::class)->viewAttempts($user, $course),
        );

        Gate::define(
            'reorder-curriculum',
            fn (User $user, Course $course) => app(CurriculumPolicy::class)->reorder($user, $course),
        );
    }

    /**
     * The ONLY blanket authorization bypass in the system.
     *
     * Returning null (not false) for everyone else lets the normal policy chain
     * run — returning false here would deny everything.
     */
    private function registerSuperAdminBypass(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }
}
