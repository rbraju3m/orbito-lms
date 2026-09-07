<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Actions;

use App\Domain\Catalog\Models\Course;
use App\Domain\Enrollment\Data\EnrollmentIntent;
use App\Domain\Enrollment\Exceptions\EnrollmentRejected;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Grants many seats at once and reports on every row.
 *
 * Synchronous and bounded rather than a queued import: an instructor pasting a
 * class list wants to know which three addresses were typos, now. A job id
 * they have to poll turns a ten-second task into a workflow. The bound is what
 * makes that safe — see MAX_ROWS.
 *
 * Deliberately NOT all-or-nothing. One unknown address must not discard the
 * thirty-nine seats that were fine.
 */
final class BulkEnrollStudents
{
    /**
     * Above this the request stops being interactive and the answer is a
     * queued import (Phase 10), not a bigger loop.
     */
    public const MAX_ROWS = 200;

    public function __construct(private readonly EnrollInCourse $enroll) {}

    /**
     * @param  list<string>  $emails
     * @return list<array{email: string, status: string, message: string|null}>
     */
    public function handle(
        Course $course,
        array $emails,
        User $grantedBy,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $expiresAt = null,
    ): array {
        $emails = collect($emails)
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values();

        /** @var Collection<string, User> $users */
        $users = User::query()
            ->whereIn('email', $emails->all())
            ->get()
            ->keyBy(fn (User $user): string => mb_strtolower($user->email));

        $results = [];

        foreach ($emails as $email) {
            $user = $users->get($email);

            if ($user === null) {
                $results[] = $this->row($email, 'not_found', 'No account with that email address.');

                continue;
            }

            try {
                $this->enroll->handle(
                    $user,
                    $course,
                    EnrollmentIntent::manual($grantedBy->id, $startsAt, $expiresAt),
                );

                $results[] = $this->row($email, 'enrolled');
            } catch (EnrollmentRejected $e) {
                // A full course or an already-enrolled student is a fact about
                // that row, not a failure of the request.
                $results[] = $this->row($email, 'skipped', $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * @return array{email: string, status: string, message: string|null}
     */
    private function row(string $email, string $status, ?string $message = null): array
    {
        return ['email' => $email, 'status' => $status, 'message' => $message];
    }
}
