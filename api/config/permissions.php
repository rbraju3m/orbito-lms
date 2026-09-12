<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Permission registry
|--------------------------------------------------------------------------
|
| The single source of truth for what can be permitted and who holds what.
| `php artisan permissions:sync` reconciles the database with this file.
|
| A permission is an atomic verb on a noun. The `.own` / `.any` suffix is part
| of the key, not a runtime flag: `.own` means "resources this user owns or is
| assigned to" and the policy resolves ownership; `.any` means platform-wide.
|
| Nothing in application code may check a ROLE. Code asks
| `$user->hasPermission('course.publish.own', $course)`.
|
| See docs/ROLES_PERMISSIONS.md.
|
*/

$permissions = [
    'users' => [
        'user.view' => 'View user accounts',
        'user.create' => 'Create user accounts',
        'user.update' => 'Edit user accounts',
        'user.delete' => 'Delete user accounts',
        'user.suspend' => 'Suspend or reinstate a user',
        'user.impersonate' => 'Sign in as another user',
        'user.export' => 'Export user data',
    ],

    'roles' => [
        'role.view' => 'View roles and permissions',
        'role.create' => 'Create custom roles',
        'role.update' => 'Edit roles',
        'role.delete' => 'Delete roles',
        'role.assign' => 'Assign a global role to a user',
        'role.assign.course' => 'Assign a course-scoped role to a user',
    ],

    'instructors' => [
        'instructor.view' => 'View instructor applications and profiles',
        'instructor.approve' => 'Approve or reject an instructor application',
        'instructor.block' => 'Block an instructor',
        'instructor.commission.manage' => 'Set instructor commission rates',
    ],

    'courses' => [
        'course.view.any' => 'View any course, published or not',
        'course.view.unpublished' => 'View unpublished courses in scope',
        'course.create' => 'Create a course',
        'course.update.own' => 'Edit courses in scope',
        'course.update.any' => 'Edit any course',
        'course.delete.own' => 'Delete courses in scope',
        'course.delete.any' => 'Delete any course',
        'course.publish.own' => 'Publish courses in scope',
        'course.publish.any' => 'Publish any course',
        'course.review.submit' => 'Submit a course for review',
        'course.review.approve' => 'Approve or reject a submitted course',
        'course.archive' => 'Archive a course',
        'course.duplicate' => 'Duplicate a course',
        'course.instructors.manage' => 'Add or remove course instructors',
        'course.settings.manage' => 'Change course settings',
        'course.price.own' => 'Set the price of courses in scope',
        'course.price.any' => 'Set the price of any course',
    ],

    'curriculum' => [
        'curriculum.view.unpublished' => 'View unpublished curriculum items',
        'curriculum.manage.own' => 'Manage curriculum in scope',
        'curriculum.manage.any' => 'Manage any curriculum',
        'curriculum.reorder' => 'Reorder curriculum',
    ],

    'assessment' => [
        'quiz.manage.own' => 'Manage quizzes in scope',
        'quiz.manage.any' => 'Manage any quiz',
        'quiz.grade.own' => 'Grade quiz attempts in scope',
        'quiz.grade.any' => 'Grade any quiz attempt',
        'quiz.attempt.view.any' => 'View any quiz attempt',
        'quiz.attempt.delete' => 'Delete a quiz attempt',
        'questionbank.manage' => 'Manage question banks',
        'assignment.manage.own' => 'Manage assignments in scope',
        'assignment.manage.any' => 'Manage any assignment',
        'assignment.grade.own' => 'Grade submissions in scope',
        'assignment.grade.any' => 'Grade any submission',
        'assignment.submission.view.any' => 'View any assignment submission',
    ],

    'enrollment' => [
        'enrollment.view.own' => 'View enrollments in scope',
        'enrollment.view.any' => 'View any enrollment',
        'enrollment.create' => 'Enrol a student manually',
        'enrollment.bulk' => 'Bulk enrol students',
        'enrollment.suspend' => 'Suspend or revoke an enrollment',
        'enrollment.delete' => 'Delete an enrollment',
    ],

    'progress' => [
        'progress.view.own' => 'View progress in scope',
        'progress.view.any' => 'View any learner progress',
        'progress.reset' => 'Reset course progress',
    ],

    'commerce' => [
        'order.view.own' => 'View own orders',
        'order.view.any' => 'View any order',
        'order.update' => 'Edit an order',
        'order.refund' => 'Refund an order',
        'coupon.manage' => 'Manage coupons',
        'product.manage' => 'Manage products and pricing',
        /*
         * Bundles are an academy-level merchandising decision, not an
         * instructor's: one can contain another instructor's courses, and
         * pricing it decides what that instructor earns. So this is NOT
         * granted to the instructor role, unlike `course.price.own`.
         */
        'bundle.manage' => 'Create, price and publish course bundles',
        /*
         * Downloads are the academy's stock, not an instructor's (see
         * docs/DOWNLOADS.md §1) — so, like bundles, there is no `.own`
         * variant and the instructor role does not hold this. Uploading into
         * the `download` media collection requires it too.
         */
        'download.manage' => 'Create, price and publish digital downloads',
        'tax.manage' => 'Manage tax rules',
        'payout.request' => 'Request a payout',
        'payout.approve' => 'Approve a payout',
        'earning.view.own' => 'View own earnings',
        'earning.view.any' => 'View any earnings',
        'gateway.manage' => 'Configure payment gateways',
    ],

    'certification' => [
        'certificate.view.own' => 'View own certificates',
        'certificate.view.any' => 'View any certificate',
        'certificate.issue' => 'Issue a certificate',
        'certificate.revoke' => 'Revoke a certificate',
        'certificate.template.manage' => 'Manage certificate templates',
    ],

    'engagement' => [
        'review.create' => 'Write a course review',
        'review.moderate' => 'Approve or reject reviews',
        'review.reply.own' => 'Reply to reviews in scope',
        'review.delete' => 'Delete a review',
        'discussion.create' => 'Start a discussion',
        'discussion.reply' => 'Reply in a discussion',
        'discussion.moderate' => 'Moderate discussions in scope',
        'announcement.manage' => 'Post course announcements',
    ],

    'media' => [
        'media.upload' => 'Upload media',
        'media.delete.own' => 'Delete own media',
        'media.delete.any' => 'Delete any media',
        'media.library.view.any' => 'Browse the whole media library',
    ],

    'live' => [
        // Scheduling a class is a course-authoring act, so `.own` is the
        // scoped key every instructor holds and the gate narrows it to the
        // courses they actually staff.
        'live.manage.own' => 'Schedule live sessions and cohorts in scope',
        'live.manage.any' => 'Schedule live sessions and cohorts anywhere',
        // A webinar belongs to no course, so there is nothing to scope it to —
        // it is an academy-wide capability and its own key.
        'webinar.manage' => 'Create and publish webinars',
        'attendance.mark' => 'Mark a session roster',
        // Connecting the academy's Zoom or Google account is an ADMIN act,
        // not an authoring one: the credentials are the academy's, and every
        // instructor scheduling a class must not hold them. Same reasoning as
        // `gateway.manage`, which the instructor role does not hold either.
        'live.provider.manage' => 'Connect a meeting provider',
    ],

    'analytics' => [
        'analytics.view.own' => 'View analytics in scope',
        'analytics.view.platform' => 'View platform-wide analytics',
        'analytics.export' => 'Export analytics',
    ],

    'settings' => [
        'settings.view' => 'View platform settings',
        'settings.update' => 'Change platform settings',
        'settings.payment' => 'Change payment settings',
        'settings.email' => 'Change email settings',
    ],

    'system' => [
        'audit.view' => 'View the audit log',
        'queue.manage' => 'Manage queues and failed jobs',
        'webhook.manage' => 'Manage outbound webhooks',
        'ai.use' => 'Use AI assistance',
        'ai.configure' => 'Configure AI providers',
    ],
];

/** Flattened list of every key, used by the sync command and by role definitions. */
/** @var list<string> $all */
$all = array_merge(...array_values(array_map(
    static fn (array $group): array => array_keys($group),
    $permissions,
)));

return [
    'groups' => $permissions,

    'all' => $all,

    /*
    |----------------------------------------------------------------------
    | Roles
    |----------------------------------------------------------------------
    |
    | `scope` is `global` (the role applies platform-wide) or `course` (the
    | role is granted per course through role_assignments.scope_id).
    |
    | `system` roles cannot be deleted through the API.
    |
    */
    'roles' => [
        'super_admin' => [
            'name' => 'Super Admin',
            'description' => 'Unrestricted access, including role and permission management.',
            'scope' => 'global',
            'system' => true,
            // Holds every permission. Gate::before short-circuits anyway; this
            // keeps the stored matrix honest rather than mysteriously empty.
            'permissions' => $all,
        ],

        'admin' => [
            'name' => 'Admin',
            'description' => 'Runs the platform. Cannot edit the permission registry or impersonate.',
            'scope' => 'global',
            'system' => true,
            'permissions' => array_values(array_diff($all, [
                'user.impersonate',
                'role.create', 'role.update', 'role.delete',
                'payout.request',
                'audit.view', 'queue.manage', 'webhook.manage',
                // `.own` variants are meaningless for an admin who holds `.any`.
                'course.update.own', 'course.delete.own', 'course.publish.own',
                'course.price.own',
                'curriculum.manage.own', 'quiz.manage.own', 'quiz.grade.own',
                'assignment.manage.own', 'assignment.grade.own',
                'order.view.own', 'earning.view.own',
                'media.delete.own', 'review.reply.own',
            ])),
        ],

        'staff' => [
            'name' => 'Staff',
            'description' => 'Operational support: users, orders and discussions. No money movement, no publishing.',
            'scope' => 'global',
            'system' => true,
            'permissions' => [
                'user.view',
                'instructor.view',
                'course.view.any', 'course.view.unpublished',
                'enrollment.view.any', 'enrollment.create', 'enrollment.suspend',
                'progress.view.any',
                'order.view.any',
                'certificate.view.any',
                'review.moderate', 'review.delete',
                'discussion.create', 'discussion.reply', 'discussion.moderate',
                'media.upload',
            ],
        ],

        'instructor' => [
            'name' => 'Instructor',
            'description' => 'Creates and owns courses; manages their own students, grading and earnings.',
            'scope' => 'global',
            'system' => true,
            'permissions' => [
                'course.create', 'course.view.unpublished',
                'course.update.own', 'course.delete.own', 'course.publish.own',
                'course.review.submit', 'course.archive', 'course.duplicate',
                'course.instructors.manage', 'course.settings.manage',
                'course.price.own',
                'curriculum.view.unpublished', 'curriculum.manage.own', 'curriculum.reorder',
                'quiz.manage.own', 'quiz.grade.own', 'questionbank.manage',
                'assignment.manage.own', 'assignment.grade.own',
                'enrollment.view.own', 'enrollment.create', 'enrollment.bulk', 'enrollment.suspend',
                'progress.view.own', 'progress.reset',
                'certificate.issue', 'certificate.view.own',
                'earning.view.own', 'payout.request',
                'review.reply.own',
                'discussion.create', 'discussion.reply', 'discussion.moderate',
                'announcement.manage',
                'live.manage.own', 'attendance.mark',
                'media.upload', 'media.delete.own',
                // Export sits beside view, not above it. Somebody who can see
                // a figure and not save it will copy it out by hand, and the
                // rows are scoped to their own courses either way.
                'analytics.view.own', 'analytics.export',
                'ai.use',
                'role.assign.course',
            ],
        ],

        'student' => [
            'name' => 'Student',
            'description' => 'The default role every registered user holds.',
            'scope' => 'global',
            'system' => true,
            'permissions' => [
                'enrollment.view.own',
                'progress.view.own', 'progress.reset',
                'order.view.own',
                'certificate.view.own',
                'review.create',
                'discussion.create', 'discussion.reply',
                'media.upload', 'media.delete.own',
            ],
        ],

        'course_manager' => [
            'name' => 'Course Manager',
            'description' => 'Full authoring and student management on assigned courses. No access to money.',
            'scope' => 'course',
            'system' => true,
            'permissions' => [
                'course.view.unpublished', 'course.update.own', 'course.publish.own',
                'course.review.submit', 'course.instructors.manage', 'course.settings.manage',
                'course.duplicate',
                'curriculum.view.unpublished', 'curriculum.manage.own', 'curriculum.reorder',
                'quiz.manage.own', 'quiz.grade.own', 'questionbank.manage',
                'assignment.manage.own', 'assignment.grade.own',
                'enrollment.view.own', 'enrollment.create', 'enrollment.bulk', 'enrollment.suspend',
                'progress.view.own', 'progress.reset',
                'certificate.issue',
                'review.reply.own',
                'discussion.create', 'discussion.reply', 'discussion.moderate',
                'announcement.manage',
                'live.manage.own', 'attendance.mark',
                'media.upload',
                'analytics.view.own', 'analytics.export',
                'ai.use',
                'role.assign.course',
            ],
        ],

        'course_reviewer' => [
            'name' => 'Course Reviewer',
            'description' => 'Reads the full course including unpublished content and approves or rejects it. No editing.',
            'scope' => 'course',
            'system' => true,
            'permissions' => [
                'course.view.unpublished', 'course.review.approve',
                'curriculum.view.unpublished',
                'enrollment.view.own',
                'progress.view.own',
                'discussion.create', 'discussion.reply',
            ],
        ],

        'teaching_assistant' => [
            'name' => 'Teaching Assistant',
            'description' => 'Grades and answers questions on assigned courses. No authoring, no publishing, no money.',
            'scope' => 'course',
            'system' => true,
            'permissions' => [
                'course.view.unpublished',
                'curriculum.view.unpublished',
                'quiz.grade.own',
                'assignment.grade.own',
                'enrollment.view.own',
                'progress.view.own',
                'discussion.create', 'discussion.reply', 'discussion.moderate',
                'media.upload',
            ],
        ],
    ],
];
