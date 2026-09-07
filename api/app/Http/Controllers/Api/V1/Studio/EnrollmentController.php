<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Actions\BulkEnrollStudents;
use App\Domain\Enrollment\Actions\ChangeEnrollmentStatus;
use App\Domain\Enrollment\Actions\EnrollInCourse;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Enums\EnrollmentAction;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\CourseStudentsQuery;
use App\Http\Requests\Enrollment\BulkEnrollRequest;
use App\Http\Requests\Enrollment\StoreEnrollmentRequest;
use App\Http\Requests\Enrollment\UpdateEnrollmentRequest;
use App\Http\Resources\Enrollment\CourseStudentResource;
use App\Http\Resources\Enrollment\EnrollmentResource;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class EnrollmentController
{
    /** The instructor's student roster for one course. */
    public function index(Request $request, Course $course, CourseStudentsQuery $query): JsonResponse
    {
        Gate::authorize('view-course-roster', $course);

        $perPage = min(
            (int) $request->integer('per_page', (int) config('orbito.pagination.default_per_page')),
            (int) config('orbito.pagination.max_per_page'),
        );

        $rows = $query->forCourse(
            $course,
            $request->string('status')->value() ?: null,
            $request->string('search')->value() ?: null,
            $request->string('sort', 'recent')->value(),
            $perPage,
        );

        return ApiResponse::ok($rows->through(
            fn (Enrollment $enrollment) => CourseStudentResource::make($enrollment)->resolve($request)
        ));
    }

    /** Grant one seat by hand. Bypasses payment and prerequisites; not the cap. */
    public function store(StoreEnrollmentRequest $request, Course $course, EnrollInCourse $action): JsonResponse
    {
        Gate::authorize('manage-enrollments', $course);

        $enrollment = $action->handle(
            $request->student(),
            $course,
            EnrollmentIntent::manual(
                $request->user()->id,
                $this->date($request, 'starts_at'),
                $this->date($request, 'expires_at'),
            ),
        );

        return ApiResponse::created(
            CourseStudentResource::make($enrollment->load(['user', 'progress']))->resolve($request)
        );
    }

    /**
     * Grant many at once. Returns a verdict per row rather than one status for
     * the batch — the useful answer is which three addresses were typos.
     */
    public function bulk(BulkEnrollRequest $request, Course $course, BulkEnrollStudents $action): JsonResponse
    {
        Gate::authorize('bulk-enroll', $course);

        /** @var list<string> $emails */
        $emails = $request->validated('emails');

        $results = $action->handle(
            $course,
            $emails,
            $request->user(),
            $this->date($request, 'starts_at'),
            $this->date($request, 'expires_at'),
        );

        return ApiResponse::ok([
            'results' => $results,
            'summary' => [
                'enrolled' => count(array_filter($results, fn (array $r) => $r['status'] === 'enrolled')),
                'skipped' => count(array_filter($results, fn (array $r) => $r['status'] === 'skipped')),
                'not_found' => count(array_filter($results, fn (array $r) => $r['status'] === 'not_found')),
            ],
        ]);
    }

    /** Suspend, reinstate, extend or revoke. One transition per request. */
    public function update(
        UpdateEnrollmentRequest $request,
        Enrollment $enrollment,
        ChangeEnrollmentStatus $action,
    ): JsonResponse {
        Gate::authorize('update', $enrollment);

        $updated = match ($request->action()) {
            EnrollmentAction::Suspend => $action->suspend($enrollment, $request->string('reason')->value() ?: null),
            EnrollmentAction::Reinstate => $action->reinstate($enrollment),
            EnrollmentAction::Revoke => $action->revoke($enrollment),
            EnrollmentAction::Extend => $action->extend($enrollment, $this->date($request, 'expires_at')),
        };

        return ApiResponse::ok(EnrollmentResource::make($updated)->resolve($request));
    }

    private function date(Request $request, string $key): ?CarbonImmutable
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
