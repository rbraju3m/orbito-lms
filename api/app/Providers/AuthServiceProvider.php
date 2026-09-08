<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\AssignmentSubmission;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Assessment\Models\QuizAttempt;
use App\Domain\Assessment\Policies\AssignmentPolicy;
use App\Domain\Assessment\Policies\QuizPolicy;
use App\Domain\Catalog\Models\Course;
use App\Domain\Catalog\Models\CourseCategory;
use App\Domain\Catalog\Policies\CourseCategoryPolicy;
use App\Domain\Catalog\Policies\CoursePolicy;
use App\Domain\Certification\Models\Certificate;
use App\Domain\Certification\Policies\CertificatePolicy;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Policies\OrderPolicy;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Curriculum\Models\Lesson;
use App\Domain\Curriculum\Models\Resource;
use App\Domain\Curriculum\Policies\CurriculumPolicy;
use App\Domain\Engagement\Models\Announcement;
use App\Domain\Engagement\Models\Discussion;
use App\Domain\Engagement\Models\DiscussionReply;
use App\Domain\Engagement\Models\Review;
use App\Domain\Engagement\Policies\AnnouncementPolicy;
use App\Domain\Engagement\Policies\DiscussionPolicy;
use App\Domain\Engagement\Policies\ReviewPolicy;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Policies\EnrollmentPolicy;
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
        Enrollment::class => EnrollmentPolicy::class,
        Order::class => OrderPolicy::class,
        Certificate::class => CertificatePolicy::class,
        Review::class => ReviewPolicy::class,
        Discussion::class => DiscussionPolicy::class,
        Announcement::class => AnnouncementPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $this->registerMorphMap();
        $this->registerSuperAdminBypass();
        $this->registerCurriculumGates();
        $this->registerCommerceGates();
        $this->registerAnalyticsGates();
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
            'assignment' => Assignment::class,

            /*
             * Analytics subjects (P13). `analytics_events.subject_type` is a
             * morph in shape but not a relation — there are deliberately no
             * foreign keys on that table — and it is read by reports written
             * long after somebody moves a class between namespaces. These are
             * here for the same reason as the aliases above, and adding one is
             * what makes a model loggable at all: `enforceMorphMap` throws on
             * anything absent, which is a better failure than a silent FQCN.
             */
            'quiz_attempt' => QuizAttempt::class,
            'assignment_submission' => AssignmentSubmission::class,
            'certificate' => Certificate::class,
            'order' => Order::class,
        ]);
    }

    /**
     * Analytics has no model of its own — a rollup row is not a thing anybody
     * owns — so these are Gates rather than policies.
     *
     * The course gate is SCOPED-ONLY for the staff half, and that is the trap
     * §9 exists for: every instructor holds `analytics.view.own` globally, so
     * a union check would hand any of them the revenue figures for any course
     * in the academy. `hasAnyScopedPermission` ignores global roles and asks
     * the narrower question — the same shape as DiscussionPolicy.
     */
    private function registerAnalyticsGates(): void
    {
        Gate::define(
            'view-platform-analytics',
            fn (User $user): bool => $user->hasPermission('analytics.view.platform'),
        );

        Gate::define('view-course-analytics', function (User $user, Course $course): bool {
            if ($user->hasPermission('analytics.view.platform')) {
                return true;
            }

            return $user->hasPermission('analytics.view.own')
                && ($course->isStaffedBy($user)
                    || $user->hasAnyScopedPermission(['analytics.view.own'], $course));
        });

        Gate::define(
            'export-analytics',
            fn (User $user): bool => $user->hasPermission('analytics.export'),
        );
    }

    /**
     * Connecting a payment provider is an academy-wide capability, not a
     * question about any one row — so it is a Gate with no model rather than a
     * policy method that would need something to be passed to it.
     *
     * There is deliberately no Gate for the webhook. It authorises nobody: its
     * credential is the gateway's signature over the body, checked inside
     * HandleWebhook against that academy's own secret (ADR-05).
     */
    private function registerCommerceGates(): void
    {
        Gate::define('manage-gateways', fn (User $user) => $user->hasPermission('gateway.manage'));

        /*
         * Academy-wide, so a Gate with no model rather than a policy method
         * that would need something passed to it. There is deliberately no
         * gate for the public verification page: it authorises nobody, and its
         * credential is the token.
         */
        Gate::define(
            'manage-certificate-templates',
            fn (User $user) => $user->hasPermission('certificate.template.manage'),
        );

        // The moderation QUEUE is a list, not a row, so it cannot be a policy
        // method — there is nothing to pass one.
        Gate::define('moderate-reviews', fn (User $user) => $user->hasPermission('review.moderate'));

        /*
         * A Gate rather than registering DiscussionPolicy for DiscussionReply
         * as well. Two models sharing one policy would make `view` on a reply
         * resolve to `view(User, Discussion)` and blow up on the type — a trap
         * waiting for the next ability somebody adds. The same shape as the
         * curriculum gates: an ability over a model the policy map does not own.
         */
        Gate::define(
            'delete-discussion-reply',
            fn (User $user, DiscussionReply $reply) => app(DiscussionPolicy::class)
                ->deleteReply($user, $reply),
        );
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
            'manage-assignment',
            fn (User $user, Course $course) => app(AssignmentPolicy::class)->manage($user, $course),
        );

        Gate::define(
            'grade-assignment',
            fn (User $user, Course $course) => app(AssignmentPolicy::class)->grade($user, $course),
        );

        Gate::define(
            'view-submissions',
            fn (User $user, Course $course) => app(AssignmentPolicy::class)->viewSubmissions($user, $course),
        );

        /*
         * The grading queue is one list of work across quizzes and
         * assignments, so it opens for anyone who can grade either — and then
         * returns only the kinds that particular grader may actually see.
         */
        Gate::define('view-grading-queue', fn (User $user, Course $course) => app(QuizPolicy::class)
            ->viewAttempts($user, $course)
            || app(AssignmentPolicy::class)->viewSubmissions($user, $course));

        /*
         * Enrollment management resolves through the parent course, like the
         * rest of the studio surface. The row-level abilities (update, delete)
         * are the registered policy on the model instead.
         */
        Gate::define(
            'view-course-roster',
            fn (User $user, Course $course) => app(EnrollmentPolicy::class)->viewRoster($user, $course),
        );

        Gate::define(
            'manage-enrollments',
            fn (User $user, Course $course) => app(EnrollmentPolicy::class)->manage($user, $course),
        );

        Gate::define(
            'bulk-enroll',
            fn (User $user, Course $course) => app(EnrollmentPolicy::class)->bulk($user, $course),
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
