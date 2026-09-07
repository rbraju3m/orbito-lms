<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * The system roles. Custom roles are rows without a case here — nothing in the
 * application may branch on this enum to make an authorization decision; it
 * exists so seeding, assignment and tests can name a role unambiguously.
 */
enum RoleKey: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';
    case Instructor = 'instructor';
    case Student = 'student';
    case CourseManager = 'course_manager';
    case CourseReviewer = 'course_reviewer';
    case TeachingAssistant = 'teaching_assistant';

    public function scope(): RoleScope
    {
        return match ($this) {
            self::CourseManager, self::CourseReviewer, self::TeachingAssistant => RoleScope::Course,
            default => RoleScope::Global,
        };
    }
}
