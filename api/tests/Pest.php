<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assignment;
use App\Domain\Assessment\Models\Quiz;
use App\Domain\Catalog\Models\Course;
use App\Domain\Curriculum\Enums\ItemType;
use App\Domain\Curriculum\Events\CurriculumChanged;
use App\Domain\Curriculum\Models\CourseItem;
use App\Domain\Curriculum\Models\CourseSection;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Identity\Enums\RoleKey;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PermissionRegistry;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// RefreshDatabase is composed inside TestCase, which wraps it for tenancy.
// Applying it here as well would put the un-wrapped version on the subclass
// and silently bypass that wrapper.
pest()->extend(TestCase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Assert the documented error envelope (docs/API.md §2), not just the status.
 * A wrong `code` is a breaking API change even when the status is right.
 */
expect()->extend('toBeApiError', function (string $code) {
    /** @var TestResponse $response */
    $response = $this->value;

    $response->assertJsonStructure([
        'error' => ['code', 'message', 'details', 'request_id'],
    ]);

    expect($response->json('error.code'))->toBe($code);

    return $this;
});

/**
 * Roles and permissions must exist before almost any Feature test. Seeding the
 * registry once per test is cheap (97 rows) and beats every test remembering.
 */
function seedRegistry(): void
{
    app(PermissionRegistry::class)->sync();
}

/**
 * A user holding one global role, with the registry already seeded.
 */
function userWithRole(
    RoleKey $role,
    array $attributes = [],
): User {
    seedRegistry();

    return User::factory()
        ->withRole($role)
        ->create($attributes);
}

/**
 * Sanctum only starts a session when the request looks like it came from the
 * first-party SPA, which it decides from the Origin header. Tests exercising
 * the cookie flow must therefore look like the SPA.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    return ['Origin' => (string) config('app.frontend_url')];
}

/**
 * Builds a course with a real curriculum: `$shape` is items-per-section.
 * Positions are course-global and dense, exactly as the reorder endpoint
 * leaves them.
 *
 * @param  list<int>  $shape
 */
function courseWithCurriculum(
    Course $course,
    array $shape = [2, 2],
): Course {
    $position = 0;

    foreach ($shape as $sectionIndex => $itemCount) {
        $section = CourseSection::factory()->create([
            'course_id' => $course->id,
            'position' => $sectionIndex,
            'title' => 'Section '.($sectionIndex + 1),
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            CourseItem::factory()->inSection($section)->create([
                'position' => $position++,
                'title' => 'Item '.($i + 1),
            ]);
        }
    }

    CurriculumChanged::dispatch($course);

    return $course->fresh(['sections.items', 'items']) ?? $course;
}

/**
 * A published course with an enrolled student and a quiz item, ready to attempt.
 *
 * @return array{course: Course, item: CourseItem, quiz: Quiz, student: User, enrollment: Enrollment, instructor: User}
 */
function quizScenario(array $quizSettings = []): array
{
    seedRegistry();

    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()
        ->ownedBy($instructor)->published()->create();

    $section = CourseSection::factory()
        ->create(['course_id' => $course->id]);

    $quiz = Quiz::create($quizSettings);

    $item = CourseItem::create([
        'course_id' => $course->id,
        'section_id' => $section->id,
        'position' => 0,
        'type' => ItemType::Quiz,
        'itemable_type' => $quiz->getMorphClass(),
        'itemable_id' => $quiz->id,
        'title' => 'Chapter quiz',
        'is_published' => true,
    ]);

    CurriculumChanged::dispatch($course);

    $student = User::factory()
        ->withRole(RoleKey::Student)->create();

    $enrollment = Enrollment::factory()
        ->create(['course_id' => $course->id, 'user_id' => $student->id]);

    return compact('course', 'item', 'quiz', 'student', 'enrollment', 'instructor');
}

/** Attaches questions to a quiz in order. */
function attachQuestions(Quiz $quiz, array $questions): void
{
    foreach (array_values($questions) as $position => $question) {
        $quiz->questions()->attach($question->id, [
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * A published course with an enrolled student and an assignment item.
 *
 * @param  array<string, mixed>  $settings
 * @return array{course: Course, item: CourseItem, assignment: Assignment, student: User, enrollment: Enrollment, instructor: User}
 */
function assignmentScenario(array $settings = []): array
{
    seedRegistry();

    $instructor = User::factory()->instructor()->create();
    $course = Course::factory()->ownedBy($instructor)->published()->create();
    $section = CourseSection::factory()->create(['course_id' => $course->id]);

    $assignment = Assignment::create($settings + [
        'instructions' => '<p>Write a close reading of one poem.</p>',
        'total_points' => 100,
    ]);

    $item = CourseItem::create([
        'course_id' => $course->id,
        'section_id' => $section->id,
        'position' => 0,
        'type' => ItemType::Assignment,
        'itemable_type' => $assignment->getMorphClass(),
        'itemable_id' => $assignment->id,
        'title' => 'Close reading',
        'is_published' => true,
    ]);

    CurriculumChanged::dispatch($course);

    $student = User::factory()->withRole(RoleKey::Student)->create();
    $enrollment = Enrollment::factory()
        ->create(['course_id' => $course->id, 'user_id' => $student->id]);

    return compact('course', 'item', 'assignment', 'student', 'enrollment', 'instructor');
}
