# EVENTS.md — Domain Event Catalogue

Cross-context communication happens through events and nothing else. `Progress`
does not call `Gamification`; it fires `ItemCompleted` and Gamification listens.
This file is the contract that makes that possible: if you are about to import
another context's model into your Action, the answer is on this page instead.

Every event is **past tense** — it reports something that already happened, so a
listener can never veto it. All of them live in
`app/Domain/<Context>/Events/`, and every cross-context subscription is wired in
`app/Providers/EventServiceProvider.php` and nowhere else, so the fan-out from
any event is readable in one place.

---

## 1. What exists today (Phase 8)

Twenty events across seven contexts.

### Identity

| Event | Payload | Fired when |
|---|---|---|
| `UserRegistered` | `User $user`, `bool $wantsToTeach` | a new account is created |
| `UserLoggedIn` | `User $user`, `string $ip`, `?string $userAgent` | a successful login, either guard |
| `InstructorApplied` | `InstructorProfile $profile` | somebody asks to teach |
| `InstructorReviewed` | `InstructorProfile $profile`, `InstructorStatus $status`, `?int $reviewedBy` | an application is approved, rejected or blocked |
| `RoleAssigned` | `RoleAssignment $assignment` | a role is granted, globally or scoped to a resource |
| `RoleRevoked` | `User $user`, `string $roleKey`, `?string $scopeType`, `?int $scopeId` | a role is taken away |

**One caveat about events fired inside a command running under
`Tenant::run()`:** the dispatcher reports no listeners at the moment of
dispatch and has them again immediately after — same object. Neither
`Event::fake()` nor a live listener observes them, so `EnrollmentExpired` from
the sweeper is verified by its state change rather than by assertion. Worth
resolving before Phase 10, where webhooks will fire events in tenant context.

`RoleRevoked` carries scalars rather than the model, because by the time it
fires the row is gone.

### Catalog

| Event | Payload | Fired when |
|---|---|---|
| `CourseCreated` | `Course $course` | a course row is created |
| `CourseStatusChanged` | `Course $course`, `CourseStatus $from`, `CourseStatus $to`, `?int $actorId` | any transition through `ChangeCourseStatus` |
| `CourseDeleted` | `int $courseId`, `int $ownerId`, `bool $wasPublished` | a course is deleted |

There is deliberately no `CoursePublished` event. Publishing is one transition
among several, and a listener that cares only about publishing reads
`$to === CourseStatus::Published`. A second event would be a second thing to
keep in step with the state machine.

### Curriculum

| Event | Payload | Fired when |
|---|---|---|
| `CurriculumChanged` | `Course $course` | any structural change: an item or section added, removed, reordered, published or unpublished |

One coarse event rather than `ItemAdded` / `ItemRemoved` / `ItemReordered`,
because every listener so far wants the same thing — recount — and a finer
grain would mean three subscriptions that must never disagree.

### Enrollment

| Event | Payload | Fired when |
|---|---|---|
| `CourseEnrolled` | `Enrollment $enrollment` | somebody gains access to a course |
| `EnrollmentSuspended` | `Enrollment $enrollment`, `?string $reason` | access closed, reversibly |
| `EnrollmentReinstated` | `Enrollment $enrollment` | back to Active — or Completed, if they had finished |
| `EnrollmentRevoked` | `Enrollment $enrollment` | cancelled; the row and its progress are kept |
| `EnrollmentExpired` | `Enrollment $enrollment` | the sweeper caught up with a lapsed date |
| `EnrollmentExtended` | `Enrollment $enrollment` | `expires_at` moved |
| `TenantProvisioned` | `Tenant $tenant`, `User $owner` | an academy and its schema now exist (Platform) |

### Progress

| Event | Payload | Fired when |
|---|---|---|
| `ItemCompleted` | `Enrollment $enrollment`, `CourseItem $item` | an item moves to completed, however it was earned |
| `CourseCompleted` | `Enrollment $enrollment` | the last required item completes, or a learner completes a flexible course by hand |

`ItemCompleted` fires the same way whether the learner pressed a button,
watched past the video threshold, submitted a quiz, or handed in an assignment.
That is the point: Gamification and Analytics will not need to know which.

### Assessment

| Event | Payload | Fired when |
|---|---|---|
| `QuizAttemptSubmitted` | `QuizAttempt $attempt` | an attempt closes, whether by the learner, the expiry sweeper, or a deadline |
| `QuizAttemptGraded` | `QuizAttempt $attempt`, `bool $passed` | an attempt has a final score — immediately for an auto-graded quiz, or after a human finishes the open questions |
| `AssignmentSubmitted` | `AssignmentSubmission $submission` | work is handed in |
| `AssignmentGraded` | `AssignmentSubmission $submission`, `bool $passed` | a submission has a final mark |
| `AssignmentReturned` | `AssignmentSubmission $submission` | work is handed back for another attempt, without a mark |

`QuizAttemptGraded` and `AssignmentGraded` share a shape on purpose: the
gradebook (P13) should be able to treat both alike.

### Media

| Event | Payload | Fired when |
|---|---|---|
| `MediaUploaded` | `Media $media` | a file is stored and recorded |
| `MediaDeleted` | `int $ownerId`, `int $sizeBytes` | a file is removed — scalars, because the row is gone |

---

## 2. Who listens

| Event | Listener | Context | Queued |
|---|---|---|---|
| `UserRegistered` | `SendEmailVerification` | Identity | the notification is |
| `UserLoggedIn` | `TouchLastSeen` | Identity | no |
| `CourseCreated` | `TrackCourseUsage@created` | Platform | no |
| `CourseStatusChanged` | `TrackCourseUsage@statusChanged` | Platform | no |
| `CourseDeleted` | `TrackCourseUsage@deleted` | Platform | no |
| `InstructorReviewed` | `TrackInstructorUsage` | Platform | no |
| `MediaUploaded` | `TrackStorageUsage@uploaded` | Platform | no |
| `MediaDeleted` | `TrackStorageUsage@deleted` | Platform | no |
| `CurriculumChanged` | `RefreshCourseCurriculumCounters` | Catalog | no |
| `CurriculumChanged` | `RecountEnrollmentTotals` | Progress | **yes** |

`RecountEnrollmentTotals` is the only `ShouldQueue` listener: adding one lesson
changes the denominator for every enrolled learner, and ten thousand students
must not make "add lesson" wait. `SendEmailVerification` runs inline but the
notification it sends is queued, so registration never waits on SMTP — the
queueing is one layer down, which is worth knowing before you "fix" it.

**Eleven of the twenty events currently have no listener.** That is expected,
not an oversight — they are the seams the later phases attach to, and firing
them from the start means those phases add a listener rather than reopening the
Action that should have fired.

---

## 3. Rules

- **Past tense, always.** An event reports; it does not request. If you want to
  ask permission, that is a policy, not an event.
- **Fire from the Action that owns the change**, inside or immediately after
  its transaction — never from a controller, and never from a model observer.
- **Carry the model, not an id**, unless the row no longer exists after the
  change. A listener that has to re-query has to guess which relations to load.
- **Queue anything slow.** A listener that sends mail, builds a certificate,
  writes analytics or touches many rows implements `ShouldQueue`.
- **A listener never throws into the firing request.** If it can fail, it is
  queued and retried.
- **Subscribe in `EventServiceProvider`**, not with `Event::listen` scattered
  through service providers, so the fan-out stays readable.
- **Adding a listener is not a reason to change the event.** If a new consumer
  needs data the event does not carry, ask whether it should be reading a Query
  instead.

---

## 4. Planned

These are named here so the phases that add them do not invent a second
vocabulary for the same fact.

| Event | Context | Phase |
|---|---|---|
| ~~`EnrollmentExpired`, `EnrollmentSuspended`, `EnrollmentRevoked`~~ | Enrollment | **shipped P9**, with `EnrollmentReinstated` and `EnrollmentExtended` |
| `OrderPlaced`, `PaymentCaptured`, `RefundIssued` | Commerce | P10 |
| `CertificateIssued`, `CertificateRevoked` | Certification | P11 |
| `ReviewPublished`, `QuestionAsked`, `QuestionAnswered` | Engagement | P12 |
| `BadgeAwarded`, `StreakExtended` | Gamification | P14 |
| `SessionScheduled`, `AttendanceRecorded` | Live | P15 |
| `RoleAssignmentExpired` | Identity | still open — the enrolment sweeper shipped in P9 without it |

Outbound webhooks (ADR-12) subscribe to this catalogue rather than to anything
new: a webhook is one more listener, which is the whole reason extension does
not need a plugin loader.
