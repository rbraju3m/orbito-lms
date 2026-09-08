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

## 1. What exists today (Phase 12)

Thirty-seven events across twelve contexts.

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

### Commerce

| Event | Payload | Fired when |
|---|---|---|
| `PaymentCaptured` | `Payment $payment`, `Order $order` | money is confirmed by the GATEWAY, never by the client |

`OrderPlaced` and `RefundIssued` are named in §4 and not built. Nothing
listens for either yet; Phase 13's analytics is the first thing that will,
and it should add them rather than reach into the Actions.

### Certification

| Event | Payload | Fired when |
|---|---|---|
| `CertificateIssued` | `Certificate $certificate` | a certificate exists — the PDF does not yet |

There is no `CertificateRevoked` yet. `RevokeCertificate` changes a status and
dispatches nothing, because nothing downstream reads it — worth adding with
the first consumer, not before.

### Engagement

| Event | Payload | Fired when |
|---|---|---|
| `ReviewChanged` | `int $courseId` | a review is published, moderated or deleted |
| `DiscussionReplied` | `int $discussionId`, `?int $replyId` | a reply is added, hidden or deleted |
| `QuestionAsked` | `Discussion $discussion` | somebody opens a thread on a course |
| `AnnouncementPublished` | `Announcement $announcement` | a draft announcement becomes visible |

`ReviewChanged` and `DiscussionReplied` speak in IDS rather than models
because both fire for deletions, where there is no longer a row to hand
anybody. `QuestionAsked` and `AnnouncementPublished` carry the model, because
by definition it is still there.

| `ReviewPublished` | `Review $review` | a review becomes visible, once |
| `AnswerAccepted` | `DiscussionReply $reply` | a reply is marked as the answer |

`AnnouncementPublished` fires on the TRANSITION, not on every save: an
instructor fixing a typo must not notify a thousand people twice.

`ReviewPublished` and `AnswerAccepted` were both named in §4 and built in P14
when they got a consumer, which is the rule this file states. `ReviewPublished`
is narrower than `ReviewChanged`: the latter fires on every write, including
deletions and edits that leave a review pending, and carries only a course id
because it exists to trigger a recount. `AnswerAccepted` fires on ACCEPTING
only — un-accepting is not an event anybody downstream wants, and a listener
reading a flag to decide whether to do nothing should not have been called.

### Gamification

| Event | Payload | Fired when |
|---|---|---|
| `PointsAwarded` | `PointTransaction $transaction`, `int $balance` | a balance actually moved |
| `StreakExtended` | `int $userId`, `int $currentDays`, `bool $continued` | the first activity of a UTC day |
| `BadgeAwarded` | `int $userId`, `Badge $badge` | a badge is earned, once ever |

`PointsAwarded` fires only when a row was written — a rule refused by its
dedupe key, its cooldown or its daily cap fires nothing, because nothing
happened. That is what lets badge evaluation listen to it rather than to the
domain events, and run exactly as often as something changed.

`StreakExtended` also fires when a streak RESTARTS (`continued` is false),
because starting again is worth knowing too.

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
| `CourseCompleted` | `IssueCertificateOnCompletion` | Certification | **yes** |
| `CertificateIssued` | `RenderPdfOnIssue` | Certification | **yes** |
| `CertificateIssued` | `NotifyOnCertificateIssued` | Notification | **yes** |
| `ReviewChanged` | `RefreshCourseRating` | Engagement | no |
| `DiscussionReplied` | `RefreshDiscussionCounters` | Engagement | no |
| `DiscussionReplied` | `NotifyOnDiscussionReplied` | Notification | **yes** |
| `QuestionAsked` | `NotifyStaffOnQuestionAsked` | Notification | **yes** |
| `AnnouncementPublished` | `NotifyOnAnnouncementPublished` | Notification | **yes** |
| `AssignmentGraded` | `NotifyOnAssignmentGraded` | Notification | **yes** |
| `CourseEnrolled` | `RemoveFromWishlistOnEnrollment` | Engagement | **yes** |
| `CourseEnrolled` | `RecordEnrollmentEvents` | Analytics | **yes** |
| `ItemCompleted` | `RecordProgressEvents@item` | Analytics | **yes** |
| `CourseCompleted` | `RecordProgressEvents@course` | Analytics | **yes** |
| `QuizAttemptSubmitted` | `RecordAssessmentEvents@quizSubmitted` | Analytics | **yes** |
| `QuizAttemptGraded` | `RecordAssessmentEvents@quizGraded` | Analytics | **yes** |
| `AssignmentSubmitted` | `RecordAssessmentEvents@assignmentSubmitted` | Analytics | **yes** |
| `AssignmentGraded` | `RecordAssessmentEvents@assignmentGraded` | Analytics | **yes** |
| `PaymentCaptured` | `RecordCommerceEvents` | Analytics | **yes** |
| `CertificateIssued` | `RecordCertificationEvents` | Analytics | **yes** |
| `ItemCompleted` | `AwardForProgress@item` | Gamification | **yes** |
| `CourseCompleted` | `AwardForProgress@course` | Gamification | **yes** |
| `QuizAttemptGraded` | `AwardForAssessment@quiz` | Gamification | **yes** |
| `AssignmentGraded` | `AwardForAssessment@assignment` | Gamification | **yes** |
| `ReviewPublished` | `AwardForEngagement@review` | Gamification | **yes** |
| `AnswerAccepted` | `AwardForEngagement@answer` | Gamification | **yes** |
| `PointsAwarded` | `EvaluateBadges@points` | Gamification | **yes** |
| `StreakExtended` | `EvaluateBadges@streak` | Gamification | **yes** |
| `BadgeAwarded` | `NotifyOnBadgeAwarded` | Notification | **yes** |

`RecountEnrollmentTotals` was the first `ShouldQueue` listener: adding one
lesson changes the denominator for every enrolled learner, and ten thousand
students must not make "add lesson" wait. `SendEmailVerification` runs inline
but the notification it sends is queued, so registration never waits on SMTP —
the queueing is one layer down, which is worth knowing before you "fix" it.

**Every notification listener is queued, without exception.** Each can address
thousands of people and each can send mail; neither belongs on the request
that caused it. The counter refreshes beside them are NOT queued, and the
contrast is deliberate — a learner who publishes a review and still sees the
old average will assume the write failed.

Notification listeners do not consult preferences. `DomainNotification::via()`
is the single enforcement point, so a delivery raised from anywhere — a
command, a future digest — obeys the switches without having to remember to
ask.

**Analytics listens to almost everything and changes nothing.** That is ADR-08
working: the log is written by queued listeners and read by nobody but the
rollup jobs. None of them may throw into the request either — `RecordEvent`
reports and returns null rather than propagating, because a full disk must
lose a row in a traffic count, not break somebody's lesson.

Four names in the vocabulary have NO domain event and never will:
`course_viewed`, `item_started`, `search_performed`, `cart_abandoned`. The
server cannot see any of them, which is the entire reason
`POST /analytics/track` exists — and the reason its allowlist is exactly those
four.

**Analytics and Gamification listen to the same domain events and read none
of each other's tables.** Two contexts fed by one source, neither aware of the
other — and neither of them able to break the request that fired the event,
because every listener in both is queued.

Gamification's own events are what BADGES listen to, not the domain ones. A
rule refused by its dedupe key writes nothing and fires nothing, so badge
evaluation runs exactly as often as a balance moved rather than as often as a
lesson was ticked.

**Several events still have no listener.** That is expected, not an oversight —
they are the seams the later phases attach to, and firing them from the start
means those phases add a listener rather than reopening the Action that should
have fired.

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
| `PaymentCaptured` shipped P10; `OrderPlaced` / `RefundIssued` not built | Commerce | — |
| `CertificateIssued` shipped P11; `CertificateRevoked` not built | Certification | — |
| ~~`ReviewPublished`, `QuestionAsked`, `QuestionAnswered`~~ | Engagement | **shipped P12** as `ReviewChanged`, `QuestionAsked`, `DiscussionReplied`, `AnnouncementPublished`; `ReviewPublished` and `AnswerAccepted` followed in P14 |
| ~~`BadgeAwarded`, `StreakExtended`~~ | Gamification | **shipped P14**, with `PointsAwarded` |
| `SessionScheduled`, `AttendanceRecorded` | Live | P15 |
| `RoleAssignmentExpired` | Identity | still open — the enrolment sweeper shipped in P9 without it |

Outbound webhooks (ADR-12) subscribe to this catalogue rather than to anything
new: a webhook is one more listener, which is the whole reason extension does
not need a plugin loader.
