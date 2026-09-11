# ROADMAP.md — Phases, Dependency Graph, MVP Scope

## Where this stands

**Phases 0–15 are complete**, front and back, and the system was
**retrofitted to multi-tenancy** partway through — a reversal of the
single-tenant decision recorded as risk R4. **Phase 16 is in progress**: plan
limits are enforced and bundles have shipped (see §Phase 16 below) — which
also closed a Phase 10 hole that made every paid course unpublishable.

**Two integrations are written and UNPROVEN.** Neither is called done, and
both need credentials rather than code:

| | |
|---|---|
| `StripeGateway` (P10) | has never contacted Stripe. Commerce is complete and tested against `FakeGateway`; no real money has moved. |
| `ZoomProvider` / `GoogleMeetProvider` (P15) | have never contacted either service. `ManualProvider` — the host pastes a link — works and is tested, and is what most academies will use. |

| | |
|---|---|
| Backend | 1,164 Pest tests / 3,970 assertions · PHPStan level 6 clean · Pint clean |
| Frontend | 279 Vitest tests across 52 files · `tsc` clean · oxlint clean · build clean |
| Budget | first-paint JS **250.28 KB** gzipped against **255 KB** — raised from 250 in Phase 16, see there. `npm run size` is the measurement (`web/scripts/first-paint.mjs`): entry script plus every `modulepreload`, gzip-9 through Node's zlib, and it fails above the budget |
| E2E | Playwright specs for phases 2–3 only; the host cannot run it (Ubuntu 20.04) |
| Suite runtime | ~14 minutes on a quiet machine (13–15 across Phase 16's runs), up from ~2 — provisioning tests build real schemas |

Each completed phase below carries what it delivered, the decisions that shaped
it, the bugs it found, and what it deliberately left. Where a phase's exit
criterion was verified against a running API, the transcript is the record — if
one contradicts the code, the code is right and the doc is stale.

**What an instructor can do today:** sign up, be approved, build a course with
lessons, resources, quizzes, assignments and live sessions, price and publish
it against an enforced checklist, schedule cohorts, announce things, answer
questions, work through one queue of everything waiting to be marked, and read
analytics built from an append-only event log.

**What a platform operator can do:** sign in as the permanent owner (created
automatically, undeletable — `ROLES_PERMISSIONS.md` §7), see every academy on
the installation with its subscription, provision a new one, approve or reject
a signup, suspend and reinstate, move an academy onto a plan and renew it, and
step INSIDE any academy to use its own screens as a Super Admin.

**What an academy admin can do, as of Phase 16:** see the academy's usage
against its plan, price any course — which, until Phase 16, nothing in the
product could do — sell several courses as one bundle with the saving shown,
and sell or give away digital downloads.

**What a learner can do:** find a course or a bundle, buy it, enrol, learn through a
player with video resume and notes, take a timed quiz, hand in written and
uploaded work, read the feedback and hand in again, attend a live class, ask a
question, review the course, earn points and badges, download a verifiable
certificate or a file they bought — and see all of it in a calendar, an inbox and a dashboard.

**What is conspicuously missing:** most of Phase 16 onward —
subscriptions, the blog and page builder, multilingual and RTL, and outbound
webhooks. (Plan-limit enforcement, long the oldest item on
this list, has landed.) Plus the two unproven
integrations above, and the Playwright gap, which has now outlasted thirteen
phases. On the operator surface specifically: no cross-academy usage view, no
audit of who approved what, and no screen for editing a plan — plans are still
changed in the database.

**Self-registration now names its academy.** `POST /auth/register` takes an
`academy` slug, which travels in the link an academy hands out
(`/register?academy=<slug>`), and `RegisterUser` sets `users.tenant_id` from
it. Until this landed the account belonged nowhere and its Student role went
into whichever academy happened to be open — a hole left by the tenancy
retrofit, invisible to the suite for the same reason `ScheduledCommandTest`
exists.

Whether an academy accepts a signup is now its own decision, at
`/admin/academy`: **open** (the default), or **closed**. `invite` is declared
in `RegistrationMode` and deliberately NOT built — no invitations table, no
accept flow — so an academy that selects it finds registration closed rather
than silently falling back to open. Invitations are the natural next piece if
academies want a controlled roster; host-based signup is the other route, and
becomes worth doing when academies get their own domains.

---

## 1. Implementation dependency graph

Arrows mean "must exist first". Anything on the same row can be built in parallel.

```
                          ┌──────────────────────────────┐
                          │ P2  FOUNDATION               │
                          │ Laravel + React skeletons,   │
                          │ API envelope, error handling,│
                          │ queues, cache, CI, tests,    │
                          │ Mantine theme, TanStack setup│
                          └───────────────┬──────────────┘
                                          ▼
                          ┌──────────────────────────────┐
                          │ P3  IDENTITY                 │
                          │ auth, roles, permissions,    │
                          │ policies, profiles,          │
                          │ instructor approval          │
                          └───────────────┬──────────────┘
                                          ▼
                          ┌──────────────────────────────┐
                          │ P4  CATALOG                  │
                          │ categories, tags, courses,   │
                          │ co-instructors, publishing,  │
                          │ media foundation             │
                          └───────────────┬──────────────┘
                                          ▼
                          ┌──────────────────────────────┐
                          │ P5  CURRICULUM               │
                          │ sections, course_items spine,│
                          │ lessons, builder UI, reorder │
                          └───────────────┬──────────────┘
                     ┌────────────────────┼────────────────────┐
                     ▼                    ▼                    ▼
        ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────┐
        │ P6  LEARNING       │ │ P7  QUIZ           │ │ P8  ASSIGNMENT   │
        │ player, video,     │ │ questions, banks,  │ │ submissions,     │
        │ progress tables,   │ │ attempts, grading  │ │ grading, files   │
        │ notes, resources   │ │  (needs P6 progress│ │ (needs P6)       │
        └─────────┬──────────┘ │   to record marks) │ └────────┬─────────┘
                  │            └─────────┬──────────┘          │
                  └──────────────────────┼─────────────────────┘
                                         ▼
                          ┌──────────────────────────────┐
                          │ P9  ENROLLMENT & ACCESS      │
                          │ enrollments, CourseAccess,   │
                          │ drip, expiry, completion     │
                          └───────────────┬──────────────┘
                     ┌────────────────────┼────────────────────┐
                     ▼                    ▼                    ▼
        ┌────────────────────┐ ┌────────────────────┐ ┌──────────────────┐
        │ P10 COMMERCE       │ │ P11 CERTIFICATES   │ │ P12 ENGAGEMENT   │
        │ products, cart,    │ │ templates, PDF,    │ │ reviews, Q&A,    │
        │ orders, payments,  │ │ verification       │ │ announcements,   │
        │ coupons, refunds,  │ │ (needs P9 complete)│ │ notifications    │
        │ earnings, payouts  │ └────────────────────┘ └──────────────────┘
        └─────────┬──────────┘
                  │                    ══════ MVP LINE ══════
                  ▼
        ┌────────────────────┐
        │ P13 ANALYTICS      │  needs events from P4–P12
        └─────────┬──────────┘
                  ▼
        ┌────────────────────┐
        │ P14 GAMIFICATION   │  needs the P13 event stream
        └─────────┬──────────┘
                  ▼
        ┌────────────────────┐   ┌────────────────────┐
        │ P15 LIVE LEARNING  │   │ P16 BUSINESS       │
        │ cohorts, sessions, │   │ subscriptions,     │
        │ Zoom/Meet, webinars│   │ bundles, downloads,│
        │ (needs P5 spine,   │   │ coaching, blog,    │
        │  P9 access, P10)   │   │ page builder       │
        └─────────┬──────────┘   │ (needs P10)        │
                  │              └─────────┬──────────┘
                  └────────────┬───────────┘
                               ▼
                  ┌────────────────────┐
                  │ P17 AI             │  needs P4/P5/P7 content models
                  └─────────┬──────────┘
                            ▼
                  ┌────────────────────┐
                  │ P18 MOBILE API     │  audit + harden, no new domains
                  └─────────┬──────────┘
                            ▼
                  ┌────────────────────┐
                  │ P19 HARDENING      │  security, perf, 2FA, CI/CD, monitoring
                  └────────────────────┘
```

### Hard dependencies worth naming

| Edge | Why |
|---|---|
| P3 → everything | Policies are the authorization substrate; retrofitting them means touching every controller. |
| P5 → P6, P7, P8 | All three attach to `course_items`. Building them before the spine means three bespoke ordering models. |
| P6 → P7, P8 | Quiz and assignment results write `item_progress`. Progress must exist first. |
| P9 → P10 | Payment grants **access**. If access resolution is not one service before commerce, it will be five. |
| P9 → P11 | A certificate is issued on `CourseCompleted`, which only P9 can emit. |
| P4 → P16 | Plan/usage counters (course count, student count, storage bytes) must be maintained from the moment those rows are created, not backfilled. |
| P13 → P14 | Gamification rules listen to the same events analytics ingests. One event catalogue, two consumers. |
| Media (inside P4/P6) → P8, P11, P16 | Submissions, certificate PDFs, and downloads all reuse the media + signed-URL layer. |

### Things that must be right in P2–P5 or they are expensive later

1. **The API error envelope and pagination contract** — every client parses it.
2. **`course_items` as the single spine** — three later phases hang off it.
3. **`role_assignments` with a scope column** — adding scope later rewrites every policy.
4. **Money as minor units + currency** — a decimal-to-integer migration touches every order.
5. **The domain event catalogue** — analytics, gamification, notifications and webhooks
   are all consumers; adding events later means backfilling history you don't have.
6. **The tenancy decision (risk R4)** — a tenant key cannot be added cheaply after P4.

---

## 2. Phase detail

### Phase 0 — Audit ✅ complete
`TUTOR_AUDIT.md`, `KLASIO_REFERENCE.md`, `FEATURE_MATRIX.md`.

### Phase 1 — Architecture ✅ complete
`ARCHITECTURE_PROPOSAL.md`, `DATABASE.md`, `API.md`, `ROLES_PERMISSIONS.md`,
`FRONTEND_ARCHITECTURE.md`, `DESIGN_SYSTEM.md`, `ROADMAP.md`, `CLAUDE.md`.

Approved 2026-09-07: Laravel 13, single-tenant, Stripe + PayPal.
**The tenancy half was reversed after Phase 9** — see Phase T and ADR-13. The
record is left standing rather than rewritten: the decision was made, held for
seven phases, and then changed.

### Phase 2 — Foundation ✅ complete

Delivered:
- `api/` — Laravel 13.30.1, API-only, 16 bounded-context directories scaffolded
- `web/` — React 19 + TS 6 + Vite 8 SPA
- `/api/v1/health` with the success envelope; one exception renderer for the error
  envelope with stable machine codes
- Request-id correlation (`X-Request-Id`) through middleware → logs → error bodies
- Offset **and** cursor pagination normalised to the documented contract
- Named rate limiters (`api`, `auth`, `analytics`, `webhook`)
- Sanctum cookie + token auth foundation, explicit CORS allowlist with credentials
- Redis cache, session and queues (predis — no PHP extension needed) + Horizon
- JSON log channel for queryable production logs
- Mantine 9 theme with light/dark/system, `shared/ui` state components
- TanStack Query 5 with a typed axios client and `ApiError` normalisation
- Pest + Larastan (level 6) + Pint · Vitest + Testing Library + MSW · Playwright
- GitHub Actions CI running all three suites against real MySQL 8 and Redis 7

**Exit met:** `/api/v1/health` returns 200 against real MySQL + Redis; the SPA renders
it at `/system` with real loading, error and success states; dark mode works;
17 backend tests and 11 frontend tests pass; PHPStan and tsc are clean; the
production build is ~196 KB gzipped, inside the 250 KB budget.

Deferred from this phase: Docker/Sail (the host already provides MySQL + Redis
natively, so it would have added a moving part without removing one).

### Phase 3 — Authentication & Users ✅ complete

Delivered:
- **Permission registry** — 97 permissions in 16 groups, 8 roles, declared in
  `config/permissions.php` and reconciled by `php artisan permissions:sync`.
  Sync is additive: it never silently deletes a capability a role still uses.
- **Course-scoped roles (ADR-07)** — `role_assignments(user_id, role_id, scope_type,
  scope_id, expires_at)`. A Teaching Assistant on course 42 answers *true* for
  course 42 and *false* everywhere else. Assignments can expire.
- **Policies** — `UserPolicy`, `RolePolicy`, `InstructorProfilePolicy`, with the
  single `Gate::before` Super Admin bypass. No code branches on a role name.
- **Auth** — register, login, logout, email verification, password reset, change
  password. Sanctum cookie for the SPA; bearer tokens for every other client.
- **Instructor lifecycle** — apply → pending → approve/reject/block. Approval is
  the *only* thing that grants the Instructor role.
- **`GET /auth/me`** returns the caller with their resolved permission keys.
- **Frontend** — auth pages, route guards, app shell with permission-filtered
  navigation, profile, security, instructor application, admin review screen.

Security properties that are tested, not just intended:
- Login answers identically for a wrong password and an unknown address.
- Password reset answers identically for known and unknown addresses.
- A verification signature valid for one user cannot verify another.
- Password reset and password change revoke every issued API token.
- Suspending a user revokes their tokens.
- The last Super Admin cannot be demoted.
- A scoped role assignment whose target does not exist is rejected, never
  silently downgraded to a global grant.

**Exit met.** Verified against the running API:

| Role | Permissions | `/admin/instructors` | `/admin/users` |
|---|---|---|---|
| Super Admin | 97 | 200 | 200 |
| Admin | 79 | 200 | 200 |
| Staff | 23 | 200 | 200 |
| Instructor | 40 | 403 | 403 |
| Student | 10 | 403 | 403 |
| Pending applicant | 10 | 403 | 403 (`course.create` = false) |

119 backend tests, 23 frontend tests, PHPStan level 6 and `tsc` clean.

### Phase 4 — Course Management ✅ complete

Delivered:
- **Course lifecycle as an explicit state machine** — draft → in_review →
  published → archived, with legal transitions on the enum and every move going
  through one `ChangeCourseStatus` action. Archiving is reversible; deletion is
  a soft delete because enrollments, orders and certificates will hang off the row.
- **Publish checklist** (`PublishChecklist`) — one definition of "ready to
  publish", rendered by the Studio UI *and* enforced on publish, so what the
  instructor sees can never disagree with what the server accepts. A refusal
  returns the failed checks as `details[]`.
- **Catalog** — categories (hierarchical, seeded taxonomy), tags with maintained
  usage counts, courses, co-instructors, split `course_details` / `course_settings`.
- **Denormalised course columns** (`rating_avg`, `enrollment_count`, `item_count`)
  so a course card never needs the JOIN + AVG the audited product does on every read.
- **Media foundation** — public and private disks, MIME sniffed from the file's
  own bytes, generated filenames, per-collection size and type rules, and
  short-lived signed URLs for private content (ADR-09).
- **Usage counters** — `usage_counters` maintained by event listeners per owner
  and platform-wide, with `usage:reconcile` to detect and correct drift. Billing
  is Phase 16, but "how many students did this instructor have" cannot be
  backfilled, so the counting starts now.
- **Course-scoped roles now have a real target.** `course` is in the morph map,
  which closes ADR-07's loop: a Course Manager, Reviewer or TA is granted on one
  course and has no rights on any other.

Two authorization findings, both now regression-tested:
- `hasPermission($key, $scope)` returns global ∪ scoped. Using it to ask "does
  this user have a seat here?" made **every instructor staff on every course**.
  Fixed with `hasScopedPermission()` / `hasAnyScopedPermission()`, which ignore
  global roles entirely.
- A course author granted the reviewer role on their own course could approve
  their own submission. Now explicitly denied.

**Exit met.** Verified against the running API: an instructor creates a course,
is refused publication with the exact checklist reasons, adds a description,
publishes, and the course appears in the anonymous catalogue — while a student
PATCHing it gets 403 and the usage counters read 1 total / 1 published.

218 backend tests, 38 frontend tests, PHPStan level 6 and `tsc` clean.

### Phase 5 — Curriculum Builder ✅ complete

Delivered:
- **The `course_items` spine (ADR-01), realised.** `position` is COURSE-GLOBAL,
  not section-local, which is what makes "what comes next?" a single indexed
  query across section boundaries. `ItemType` declares quiz, assignment and
  live_session now even though their entities arrive in Phases 7, 8 and 15, so
  the spine — and progress, drip and ordering with it — never changes shape.
- **One write path for `position`.** `PATCH …/curriculum/order` takes the whole
  tree, validates it is a permutation of what the course holds, and rewrites
  every position in one transaction. Partial moves are deliberately not
  accepted: they let concurrent drags silently interleave. A stale tree gets a
  409 rather than quietly dropping someone's work.
- Sections and items: create, inline rename, duplicate, delete, preview and
  publish flags. Deleting or duplicating renormalises positions so the sequence
  stays dense.
- Lessons with content and a `VideoProvider` seam (none/upload/YouTube/Vimeo/
  external). Switching provider clears the field the new one does not use, so a
  stale media id can never point at a file the lesson no longer shows.
- **Curriculum rules joined the publish checklist**: at least one section, at
  least one published *completable* item (a downloadable resource is not
  something a learner finishes), and no empty sections — named, so the author
  does not have to hunt for them.
- **The builder UI**: dnd-kit with an explicit drag handle (not long-press, so
  a phone can still scroll), keyboard sensor with named handles, collapse/expand,
  inline rename with Escape-to-cancel, one save indicator for the whole surface
  rather than a toast per drag, and a Retry that keeps the user's arrangement.
  Confirmation only when deleting a section that actually has items.

**Exit met.** Verified against the running API:

```
publish with no curriculum   -> 422 course_not_publishable
                                  Add at least one section before publishing.
built: 3 sections, 10 items, positions [0..9]
after drag                   -> 200; sections [3,3,4]; positions [0..9]
stale reorder                -> 409 curriculum_rejected
publish with curriculum      -> 200 published; item_count 10
publish with an empty section-> 422 These sections have no items: "Bibliography".
```

259 backend tests, 59 frontend tests, PHPStan level 6 and `tsc` clean.

### Phase 6 — Learning Experience ✅ complete

**ADR-02 delivered — the fix for the audit's worst finding.** The reference
product writes one `usermeta` row per completed lesson and recomputes a course
percentage on every read, loading all content and running a query per assignment
in a loop; rendering "My courses" there is O(courses × items) queries. Here
progress is **stored** in `course_progress`, maintained by events, and
"Continue learning" is ONE indexed read.

**ADR-03 delivered — `CourseAccess`.** One service answers "may this user
consume this content?" for the player, item content, media signing and notes.
Access sources grow by phase (owner, course staff, platform staff, enrollment,
preview today; drip in P9, purchase in P10, subscriptions in P16) without a
second code path ever existing. This is the question the audited product
answers in many places that disagree.

Also delivered:
- `enrollments` with status, source, expiry and seat limits. Expiry is evaluated
  live, not trusted from the status column — access must not depend on a cron
  having fired.
- `item_progress` created **lazily** on first view: 10k students × 100 items
  would otherwise be a million rows nobody has opened.
- The player: full-bleed shell, curriculum sidebar with progress, prev/next
  across section boundaries, mark-complete (optimistic, with rollback), notes,
  and a locked state that explains *why* rather than erroring.
- Video resume, and a heartbeat throttled to one request per 15s — `timeupdate`
  fires up to 60×/second. `watch_max_seconds` only grows, so scrubbing back
  cannot un-earn progress; watching past the course's threshold auto-completes.
- Flexible vs strict completion, honoured: strict completes itself, flexible
  waits for the learner.
- `progress:reconcile`, because drift in a stored aggregate is a bug alert.

**Two real bugs found and fixed while building:**
- **Rich text was never sanitised.** A compromised instructor account could run
  script in every learner's browser. Now sanitised on *write* with an allowlist
  (`symfony/html-sanitizer`), so the stored value is safe everywhere it is used.
- **The default auth guard was `web`.** The player routes are deliberately open
  to anonymous visitors for free previews, so they carry no auth middleware —
  and `$request->user()` therefore ignored bearer tokens on exactly those
  routes. An enrolled learner arrived looking anonymous and was refused their
  own content. Default guard is now `sanctum`; session login names `web`.

**Exit met.** Verified against the running API:

```
before enrol   granted=False reason=not_enrolled;  locked lesson -> 423
after enrol    0/4 = 0%
stored HTML    '<p>Content</p>'          (<script> stripped on write)
watch 290/300s auto-completed 1 item     (90% threshold)
continue       Modern Bengali Poetry @ 25%, resume point present
all complete   100%  is_complete=True
my courses     completed: 1;  continue-learning: 0 entries
add a lesson   4/4 -> 4/5 = 80%          (denominator moves for every learner)
reconcile      corrupted 99/5 -> corrected 4/5, then "consistent"
```

339 backend tests, 71 frontend tests, PHPStan level 6 and `tsc` clean.

### Phase 7 — Quiz Engine ✅
Quizzes, questions (10 types), options, question banks, quiz→question pivot · attempt
lifecycle with a server deadline · autosave answers · auto-grading · manual grading queue ·
feedback modes · results · the quiz builder and the quiz runner.
**Exit:** every question type is authored, taken, graded, and reviewed; expiry is
enforced regardless of the client clock.

**ADR-06 delivered — the answers never leave the server during an attempt.**
Two resources describe a question: `QuestionResource` for authoring, which
carries `is_correct`, `match_key`, accepted answers and the explanation, and
`AttemptQuestionResource` for the learner, which cannot express any of them.
Matching serves its targets shuffled and detached from the option they belong
to; fill-in-the-blank serves a count, not the blanks; ordering serves options
with no `position`. Nine tests assert the absence of each, because "we
remembered not to include it" is not a guarantee.

Also delivered:
- Ten question types with documented answer payloads, one grader method each.
  Partial credit where it is fair (multiple choice net of wrong picks, matching,
  fill-in-the-blank), all-or-nothing where it is not (ordering). Unanswered is
  never penalised.
- Attempts that resume rather than burn: reopening a quiz with an attempt in
  progress returns that attempt, with the question order and the point total
  frozen at start so later edits to the quiz cannot change a score in flight.
- A deadline the server sets and re-checks. `expires_at` is written at start;
  every save and the submit re-read it. The client's countdown is a display.
- The manual grading queue, `awaiting_review` as a first-class status, and a
  results screen that reveals correct answers only when the quiz's policy says
  so — never, on submission, on pass, or once attempts run out.
- The quiz builder (settings, question CRUD per type, drag-to-reorder) and the
  runner (paging, autosave with a save-state indicator, expiry alert,
  confirm-before-submit).

**Three real bugs found and fixed while building:**
- **`->toArray($request)` on a Resource bypasses `MissingValue` filtering**, so
  every `when(false)` field serialised as `{}` rather than vanishing. Scores
  were reaching open attempts. All 23 call sites now use `->resolve($request)`.
- **`{question}` is an unscoped route binding** (a question can be shared with a
  bank), and the builder never checked the question belonged to *this* quiz.
  Authoring your own quiz was edit and delete on any question id in the system.
- **A quiz item could be marked complete by hand.** The mark-complete endpoint
  was a way past every quiz in the course — the frontend declaring a completion
  status, which is exactly what is never trusted. `ItemType::isSelfMarkable()`
  now gates it, and the player reads that rather than deciding for itself.

Two smaller ones: `Collection::shuffle()` no longer takes a seed in Laravel 13,
so shuffled options jumped between page loads of the same attempt (now ordered
by a hash of attempt+option); and a blank essay queued itself for manual
grading, leaving the learner's whole result pending on an instructor clicking
through empty answers.

**Exit met.** Verified against the running API:

```
authored       10 question types, 18 points
studio view    10 questions, answers visible = True
attempt start  10 questions served, deadline in 119s
runner payload is_correct=False match_key=False accepted=False score_keys=[]
autosave       10 answers accepted, no score leaked = True
submit         status=awaiting_review earned=11/18
  single_choice    1/1       long_answer      0/4  (awaiting review)
  multiple_choice  0/2       fill_blank       1/2
  true_false       1/1       matching         2/2
  short_answer     1/1       ordering         2/2
  image_choice     1/1       image_matching   2/2
grading queue  1 attempt(s) waiting
manual grade   status=graded percent=77.78 passed=True
progress       item status=completed self_markable=False course=100%
self-mark      409 progress_rejected
```

And the deadline, with the client's own clock untouched:

```
client sees    119s left, deadline 2026-09-07T10:20:26+00:00
   (the server's expires_at is moved into the past; the browser is not told)
save answer    409 attempt_rejected: Time ran out on this attempt.
submit late    status=graded percent=0
```

417 backend tests, 91 frontend tests, PHPStan level 6 and `tsc` clean.
First-paint JS 237.6 KB gzipped, against a 250 KB budget.

### Phase 8 — Assignments ✅
Assignment authoring · submission with files and text · late policy · grading and
feedback · re-submission · the grading queue shared with quizzes.
**Exit:** submit → grade → feedback → resubmit works, with file validation enforced.

**One queue, two kinds.** An instructor thinks in terms of "what is there to
mark today", not "which table is it in", so quizzes and assignments arrive in
a single list. The two sources are unioned in SQL rather than merged in PHP —
merging two paginated queries silently drops rows at every page boundary — and
the queue is filtered by what that particular grader may actually open, so a
row never 403s when clicked. This also closed a Phase 7 gap: the manual grading
queue had an API but no screen, and an instructor could not reach it at all.

Also delivered:
- Assignment authoring on the curriculum spine — one `itemable`, no new
  ordering, progress or drip mechanism (ADR-01 holding for the third content
  type in a row).
- Text and file submissions. Per-assignment file rules NARROW the
  `MediaCollection`'s rules and never widen them, so an author typing `exe`
  into a box cannot open a hole the platform already closed.
- A late policy with three real behaviours: refuse, accept in full, or accept
  with a penalty. Lateness is decided by the server at submission time and
  frozen onto the row; the penalty is arithmetic the server does at grading,
  so it cannot depend on an instructor remembering.
- Re-submission, and handing work *back*: a returned submission does not
  consume an attempt, because charging a learner for work the instructor chose
  not to grade would make the gesture punitive.
- `SubmissionRules` — one class rendered by the submit form and enforced by the
  submit Action, so the button and the server cannot disagree about whether
  handing in is possible. The Phase 4 `PublishChecklist` shape, reused.

**Three real bugs found and fixed while building:**
- **An uploaded file could not be attached to anything.** `MediaResource`
  returned only the UUID, while every endpoint that *references* a file speaks
  in the numeric id. The upload endpoint's response was unusable, which no
  earlier phase had noticed because no screen had yet uploaded anything. It now
  returns `ref` alongside `id`, the same convention as `CourseItemResource`.
- **`event.currentTarget` read inside a `setState` updater**, in the quiz
  question editor and again in the new grading screen. A state updater runs
  after React has released the event, so `currentTarget` is null and the whole
  tree crashes — typing into a fill-in-the-blank row took the page down.
- **The grading queue paged non-deterministically.** `submitted_at` has second
  precision, so two pieces of work handed in together ordered arbitrarily, and
  a row could appear on two pages or on none.

**Exit met.** Verified against the running API:

```
authored       50 marks, due set, late=penalise 25%, attempts=1
learner sees   can_submit=True past_due=True will_be_late=True penalty=25%
upload         essay.pdf id=01a07b89… ref=2
handed in      attempt 1 late=True files=1 script_stripped=True marked=False
no more goes   can_submit=False reason=no_attempts_left
self-mark      409 progress_rejected
shared queue   2 waiting: assignment/Close reading, quiz/Chapter quiz
graded         raw=40 penalty=10 final=30 passed=True
handed back    status=returned mark_cleared=True
re-opened      can_submit=True attempts_used=0
second go      201 attempt 2
queue again    2 waiting
progress       assignment=completed self_markable=False course=100%
```

489 backend tests, 116 frontend tests, PHPStan level 6 and `tsc` clean *(at
Phase 8; 642 backend tests today)*.
First-paint JS 240.5 KB gzipped, against a 250 KB budget.

### Phase 9 — Enrollment & Access ✅ backend complete
`CourseAccess` service · manual and bulk enrollment · expiry, suspension, revocation ·
drip (date / days / sequential) · prerequisites · seat limits · course completion in both
modes · reset and retake.
**Exit met:** one service answers every access question; drip and expiry are enforced
server-side.

**Four defects it surfaced**, all pre-existing:
- The progress WRITE path resolved access at course level while the read path
  used `forItem`, so a learner could complete a lesson drip had not released.
- The seat-limit count sat outside the transaction it then opened — two
  concurrent requests could both take the last seat.
- `RecalculateCourseProgress` auto-completed in both modes while its docblock
  claimed strict only. The code was right; `completion_mode` governs finishing
  *early*, not whether 100% counts.
- `CourseAccess` memoised authorization decisions, and Laravel memoises the
  controller on the `Route` object — so the cache outlived the request.
  Invisible under php-fpm, live under Octane.

**Frontend complete**, built against the tenant-aware API: drip in the outline
and a reason-specific lock screen, the studio roster with bulk enrol and the
enrolment lifecycle, per-item drip fields, prerequisites and access settings,
plus the two states tenancy forces — a members-only catalogue behind
`RequireAuth`, and an app-wide banner for a lapsed subscription.

One bug worth recording: the roster query used `apiGet`, which unwraps the
envelope and discards `meta`, so it would have rendered "no students yet" for
every course in production. The tests caught it before anyone saw it.

### Phase T — Multi-tenancy retrofit ✅ complete (T1–T6)
One MySQL schema per academy (`stancl/tenancy`), users central, tenancy
resolved from the authenticated user. Platform admin surface, plans and
subscriptions. See ADR-13.

**R4 said a tenant key "cannot be added cheaply after P4". That was wrong
because it assumed the wrong mechanism** — a `tenant_id` column would have
meant 44 tables, global scopes and a leak audit of every query;
schema-per-tenant moved the migrations wholesale and left the models, Actions
and Policies alone.

**What it cost instead** was seven bugs that were all one question — which
connection is this running on? — wearing different clothes: relations
inheriting a pinned parent's connection, `whereHas` compiling across schemas,
validation rules resolving against the wrong default, Sanctum's token model
following the tenant, `UsageCounters` never writing `tenant_id` (every academy
would have shared one set of counters), all five scheduled commands running
centrally with no academy open, and a connection purge discarding an open
transaction.

**The decision with the widest blast radius** was identification-by-user,
which removes the anonymous surface: the catalogue, course pages, previews and
the player are members-only. A public storefront would need subdomain
identification and is a real change, not a flag.

### Phase 10 — Commerce  ⚠️ COMPLETE against FakeGateway; never run against Stripe

**Scope was deliberately narrowed** to the money path: products, prices, cart,
checkout with server-side repricing, orders, one gateway, verified idempotent
webhooks, access on `PaymentCaptured`. Coupons, refunds, tax, invoices, PayPal
and the multi-currency UI were deferred until that path is proven.

**Three decisions were taken and are load-bearing.**

1. **The academy is the merchant of record.** Each academy connects its own
   gateway credentials, encrypted per tenant; the platform never touches
   learner money and takes on no money-transmitter exposure. This CHANGES
   `DATABASE.md` §6, which was written pre-tenancy and assumed one merchant —
   so `instructor_earnings` and `payouts` become an academy's INTERNAL ledger,
   not a platform obligation. They were not built, precisely because building
   them to the old shape would have been building the wrong thing.
2. **Platform billing stays manual.** `AssignPlan` remains the operator's
   lever; Stripe Billing is a separate integration from one-off checkout.
3. **Commerce lives entirely in the tenant schema**, gateway credentials
   included, which follows from (1).

**What exists** (`app/Domain/Commerce/`, Pint and PHPStan clean): the migration
for 9 tenant tables · enums · models · the `PaymentGateway` interface with
`FakeGateway` and `StripeGateway` · `PaymentGatewayFactory` ·
`SyncCourseProduct` · `PlaceOrder` · `InitiatePayment` · `HandleWebhook` ·
`CapturePayment` · `PaymentCaptured` · factories · `MoneyPathTest`.

**The migration has been run** against MySQL 8 in a real tenant schema. All 9
tables, their indexes and their foreign keys are as written; `down()` then
`up()` round-trips; `credentials` and `webhook_secret` are opaque at rest.
No change to the migration was needed.

**The money path is proven** — `tests/Feature/Commerce/MoneyPathTest.php`,
17 tests. The four cases the design rests on:

| | |
|---|---|
| Signature | wrong secret · absent header · body swapped after signing. Each throws, and `payment_events` stays EMPTY — an unverified delivery is not audited against a payment we have no verified reason to associate it with. |
| Replay | the same event id three times → one event row, one enrolment, one capture. The replay RETURNS rather than throwing, because a retry must answer 2xx. Two different event ids for one payment are caught by `isOpen()` instead. |
| Amount | a short capture fails the payment and leaves the order unpaid. An overpayment is accepted deliberately. A mismatched currency is refused. |
| Forgery | a payment id we never issued is stored with `processed_at` null and grants nothing; one learner naming another's `external_id` gains nothing. |

Also covered: the happy path end to end (enrolment lands with
`source = purchase` and the order id), server-side repricing (editing the price
after checkout does not move the order total), and the refusals for paying
twice, re-buying an owned course, and an unconnected gateway.

**The tests were verified by mutation, not by being green.** Removing the
`hash_equals` check kills exactly the three signature tests; removing the
amount comparison kills exactly the short-capture test; removing the replay
guard kills exactly the replay test.

**The HTTP surface is built** — 12 routes, in `routes/api/commerce.php`:

| | |
|---|---|
| Basket | `GET`/`DELETE /cart`, `POST /cart/items`, `DELETE /cart/items/{cartItem}` |
| Buying | `POST /checkout`, `POST /orders/{order}/pay`, `GET /orders`, `GET /orders/{order}` |
| Webhook | `POST /webhooks/payments/{gateway}/{tenant}` |
| Gateways | `GET`/`PUT`/`DELETE /admin/payment-gateways[/{gateway}]` |

With it: `InitializeTenancyByWebhookRoute` (registered `tenant.webhook`),
`OrderPolicy`, a `manage-gateways` gate, the `AddToCart` and
`ConnectPaymentGateway` actions, three form requests and three resources.

**The webhook route is the only unauthenticated write in the system.** Three
omissions from its middleware are each load-bearing — no `auth:sanctum` (the
caller is a provider with no account), no `tenant` (nothing to resolve an
academy from, so it is in the PATH), and no `subscription` (the money has
already moved; refusing a capture over the academy's own overdue bill would
take a learner's payment and grant nothing). `{tenant}` is
attacker-controllable and that is fine: resolving it only opens a connection,
and everything after is gated on the signature verifying against THAT
academy's secret. Unknown and closed academies 404 identically, so the route
cannot enumerate academy ids.

**The frontend is built** — `web/src/features/commerce/`:

| | |
|---|---|
| Buying | `BuyPanel` on the course page, `/cart`, `/orders`, `/orders/:id` |
| Admin | `/admin/payment-gateways`, behind `gateway.manage` |
| Shared | `shared/lib/money.ts` — minor units formatted through `Intl` |

`EnrolPanel` no longer says "paid enrolment arrives with checkout". Being paid
had been folded into the same list as an unmet prerequisite and a full course,
which disabled the button; paying is now the way PAST a price, so only the real
gates block it. A priced course with a dead buy button was the bug waiting
there.

**The catalogue now carries a price** (`CoursePrice`, on both course
resources, eager-loaded via a new `Course::product()` morphOne). Without it
a buy button could not name a figure without a second request. It is a LABEL:
what charges is re-read at checkout, and there is a test asserting the listing
prices five courses without an N+1.

**What still does NOT exist:**

- **The Stripe adapter has never contacted Stripe.** Written to the documented
  API, signature check follows the documented scheme, but no sandbox
  credentials were available. Treat the first live run as the test. The SPA
  offers Stripe in its gateway picker, so this is the one gap a user can reach.
- **No inline-SDK payment flow.** `PaymentHandoff` can return a
  `client_secret`, and nothing consumes it — only `redirect_url` is acted on.
  A provider that settles inline would leave the order sitting in
  `awaiting_payment`.
- Coupons, refunds, tax, invoices, and the earnings/payout surface. Deferred,
  not forgotten — see the scope note at the top of this entry.

**The exit criterion is HALF met.** "A forged client-side success grants
nothing" is proven at both layers: the domain refuses it, and
`CheckoutApiTest` asserts that `/confirm`, `/complete`, `/success` and
`/capture` on an order all 404 — the absence of the endpoint IS the property.
"A paid enrollment completes through a real sandbox webhook" is not:
`FakeGateway` is not Stripe, and until sandbox credentials exist that half
stays open.

**Two consequences of this phase that will surprise the next reader:**

- **A basket takes the platform's BASE currency at creation and never changes
  it.** A product with no price in that currency cannot be added — a 409, not
  a silent conversion. This follows from multi-currency being deferred, and it
  means `ProductFactory::pricedAt()` defaulting to USD while
  `orbito.currency.base` is BDT will trip up the next commerce test written.
- **Gateway credentials are write-only over the API.** No response contains
  them; a partial `PUT` keeps what it does not send, so toggling test mode
  cannot silently disconnect a gateway.

**Resume here.** Get Stripe sandbox credentials and run a real payment end to
end. That is the only thing between this phase and done, and it is the half of
the exit criterion no amount of local testing can close.

Two things the UI does that are worth keeping when it changes:

- **The basket calls its total an estimate**, because it is one: the basket is
  priced live on every read, the order is priced once at checkout, and a sale
  ending in between makes them differ honestly. There is a test asserting the
  page says so.
- **The order page polls only while the answer can still change.** A webhook
  lands out of band and nothing tells the browser, so an order in
  `awaiting_payment` refetches every 5s and a settled one never does.

### Phase 11 — Certificates ✅ complete

**Exit met:** completing a course issues a verifiable certificate without
blocking the request. `CourseCompleted` → queued `IssueCertificateOnCompletion`
→ `CertificateIssued` → queued `RenderPdfOnIssue`. The learner's click returns
before any of it.

Delivered: `certificate_templates` and `certificates` · idempotent issuance ·
revocation · dompdf rendering into private media · the holder's list with a
shareable link · the **public verification page** · academy-managed templates.

**Two decisions were taken and are load-bearing.**

1. **The verification URL carries the tenant id** — `/verify/{tenant}/{token}`.
   A hiring manager has no account, so this is the second route with no
   authenticated user and no Laravel signature. The id is a uuid7 and
   immutable; a slug could be renamed and an academy cannot reissue paper
   already in the world. `InitializeTenancyByWebhookRoute` was generalised to
   `InitializeTenancyByPathTenant` (`tenant.path`) because two routes now
   need it.
2. **dompdf, not headless Chrome.** This host cannot run Playwright and Chrome
   is the same class of dependency. The cost is written into `CertificateHtml`:
   no flex, no grid, so absolute positioning and tables, and DejaVu Sans is the
   only font with the glyph coverage for a Bengali name.

**The hard part was idempotency.** `CourseCompleted` is not once-per-lifetime:
`RecalculateCourseProgress` fires it whenever the last item flips, queues
retry, and a learner can complete → reset → complete. Somebody holding two
certificate numbers for one course cannot prove which is real. The guarantee is
the UNIQUE key on `enrollment_id`; the read above it is an optimisation, and
the race it loses is caught rather than prevented.

**Distinctions the whole phase turns on:**

- **Revoked ≠ expired ≠ absent.** An expired certificate was genuinely earned,
  so it stays `issued` and the page says "issued, then lapsed" — calling it
  invalid would make an honest holder look like a forger.
- **Revoking never deletes.** A withdrawn certificate that 404'd would be
  indistinguishable from a forgery, which protects the forger.
- **A 404 says "no record", never "fake".** We know we have no record; we do
  not know what the paper in their hand is.
- **The certificate is valid before its PDF exists.** The document is a
  rendering of the fact, not the fact itself, so a failed render leaves a
  valid certificate — and `certificates(status, pdf_media_id)` is indexed so a
  sweep can find them.

**What the public page refuses to say** is most of its design:
`CertificateVerificationResource` is a separate class (ADR-06) that omits the
token, the course slug and uuid, the PDF, and the revocation reason.

**Not built:** the QR code named in the original scope. The layout carries a
`show_qr` flag and nothing renders one — a QR encoding the verification URL is
a small addition, but it needs a QR library and the URL is already printed.

**Bundle note.** Mantine's `ColorInput` cost **5.5 KB gzipped on the
first-paint path** for one admin field, because Mantine is a shared chunk —
a lazy route does not keep its component imports out of it. Swapped for a
native `<input type="color">`: 249.7 KB → 244.2 KB. Worth remembering before
reaching for a heavy Mantine component on a rarely-visited screen.

### Phase 12 — Reviews / Discussion / Notifications ✅ complete

**Exit met:** `courses.rating_avg` and `rating_count` are COLUMNS, maintained
by `RefreshCourseRating` on `ReviewChanged` and reconciled nightly by
`ReconcileEngagementCounters`. No read path computes an average.

**This is the last MVP phase to ship, but it does not close the MVP.** §3
below says a student "buys it with a real verified payment", and Phase 10's
second exit criterion is still open: `StripeGateway` has never contacted
Stripe. Every feature in the MVP scope now exists; one sandbox payment is what
signs it off.

Shipped in five slices: reviews and moderation · threaded Q&A with accept and
one level of nesting · announcements and wishlist · notifications · the
frontend for all of it.

**The decisions worth knowing before changing any of it:**

- **Publishing an announcement is its own endpoint and its own button.**
  Saving a draft and sending it to a thousand people are different acts and
  must not be one careless boolean apart. `AnnouncementPublished` fires on the
  TRANSITION, so a typo fix does not notify everybody a second time.
- **One notification class, not one per type.** `DomainNotification` carries a
  frozen `NotificationPayload`; the in-app entry and the email render the same
  three fields, so they cannot drift. The payload is frozen at send time —
  editing an announcement afterwards must not rewrite mail already sent.
- **The in-app record cannot be switched off.** Silencing the inbox destroys
  the record, not the interruption; email is what the preferences govern. The
  matrix returns `locked: true` rather than omitting the channel, because a
  missing switch reads as a bug and a disabled one explains itself.
- **Preferences store OVERRIDES ONLY.** No row means the type's default, so a
  new notification type ships without a backfill across every academy.
- **Nothing notifies somebody about their own action.** No `quiz.graded` (the
  learner watched it happen), no enrolment welcome, and an announcement's
  author is excluded from its own fan-out.
- **`action_path` is stored relative.** The SPA routes on it internally, and a
  thousand stored absolute URLs would rot the day an academy changes address.

**The frontend added one pattern the next phase should copy:** every
engagement list carries what the reader may DO with it — `can_review`,
`can_ask`, `can_moderate`, `can_manage` — computed from the same rule the
write endpoint enforces. A page renders a form or an explanation, never a
button that 403s. The per-thread version (`viewer.can_reply` and friends) is
sent on the thread and deliberately NOT in the list: each key is a policy call
resolving `CourseAccess`, and thirty threads would be ninety of them.

**Two costs measured rather than guessed:**

- The bell adds **3.8 KB gzipped to the first-paint path**, because
  `AppLayout` is eager and Mantine is a shared chunk. Accepted for a control
  on every signed-in page; the same arithmetic that rejected `ColorInput` in
  Phase 11 (§ above) applies to anything heavier.
- A fan-out issues **one indexed preference lookup per recipient** inside the
  queued job. Correct, cacheless, and the right place for it — but a
  five-thousand-learner announcement is five thousand small queries. The fix
  is a batch resolver, deferred because it would be a memo with a lifetime and
  §15 has been paid for that twice.

**Not built:** websockets. The badge is one indexed count polled every 60s,
which is cheaper than a connection per signed-in tab and is the reason this
phase needed no infrastructure.

### Phase 13 — Analytics ✅ complete

ADR-08 delivered: an append-only log written by queued listeners, four rollup
tables built on a schedule, and dashboards that read the rollups and never the
log. Front and back.

**The decisions worth knowing before changing any of it:**

- **The client may raise exactly four event names** — `course_viewed`,
  `item_started`, `search_performed`, `cart_abandoned` — and the endpoint 422s
  everything else. That allowlist IS the security boundary: a browser that
  could post `payment_completed` would write revenue into the dashboards
  without paying anybody, and `course_completed` would let a learner report
  finishing after one lesson. Every other name is raised by a listener on a
  domain event, where it cannot be lied about.
- **`analytics_events` has no foreign keys.** An event is a fact about the
  past; cascading from `courses` would mean deleting a course erases the
  history of everybody who took it.
- **Nothing may throw into the request.** `RecordEvent` reports and returns
  null. Analytics observes the system and must not be able to break it — there
  is a test that drops the table and asserts enrolment still works.
- **Rollups are idempotent.** Every write is an upsert on the primary key, so
  "run it again" is the recovery path for a failed night rather than a
  corruption. The four tables hold no facts and can be dropped and rebuilt.
- **A day is a UTC day**, said out loud in every response, because an academy
  has no timezone and a shifting local day could not be rebuilt
  deterministically.
- **Money comes from the ledger, not the log.** An order is not a course.
  Splitting a payment inside an event would give the platform total and the
  per-course totals two definitions free to disagree.
- **The funnel reads `item_progress`, not the log** — it asks about the
  present state of every learner, not about a day, and the index for it was
  put there in Phase 6.
- **`peak_daily_active` is not a sum.** Distinct people cannot be added across
  days without counting a regular five times over, so the API reports the
  busiest day and names the field for it.
- **The heatmap stays in curriculum order.** "They drop out after the third
  video" is the insight; sorting by severity destroys the adjacency that makes
  it visible. Shading carries severity, order carries the course, and every
  shaded cell prints its number because colour alone is not information.

**Two costs measured rather than guessed:**

- **No chart library.** Recharts and its peers are 90–150 KB gzipped for the
  one screen in the product that draws a line. The hand-rolled SVG chart is
  ~90 lines and the whole phase added **0.2 KB gzipped to the first-paint
  path** (11.14 → 11.36 on the entry chunk); the dashboard itself is a 1.5 KB
  lazy chunk.
- **No websockets and no partitioning.** Monthly `PARTITION BY RANGE` needs a
  DDL job in every academy's schema forever; at one schema per academy the
  `occurred_at` index plus a weekly 400-day prune is the honest answer.

**Not built:** a per-student activity view (L6). `active_learners` counts
people but nothing shows one learner's timeline, and the instructor series has
an endpoint with no screen. Both are additive.

### Phase 14 — Gamification ✅ complete

A rule engine on the domain events, an append-only points ledger, badges,
streaks and snapshot leaderboards. Front and back.

**The decisions worth knowing before changing any of it:**

- **A scheme somebody can farm is worse than no scheme**, because it stops
  measuring learning and starts measuring who worked out the trick. So
  `point_transactions.dedupe_key` is a UNIQUE index, the awarding action
  CATCHES the violation rather than checking first, and re-ticking a lesson
  earns nothing. Repeatable rules pass NULL and are held by a cooldown and a
  daily cap instead.
- **Rules are DATA.** An academy retunes points, switches a rule off or adds
  its own without a deploy, and `gamification:sync` only ever CREATES what is
  missing — a sync that updated would make the config file the truth and
  quietly revert every academy's tuning on the next deploy.
- **Conditions are a closed set of six operators, and an unknown one refuses
  the award.** A typo in an academy's rule must not silently pay everybody.
  Badge criteria are a closed set of five shapes for the same reason: a DSL
  that can express anything is a DSL nobody can debug when a learner asks why
  they got nothing.
- **Rules are evaluated against the trigger, never against a re-read model.**
  By the time a queued listener runs the row may have changed, and a rule that
  re-queried would award on the state it finds rather than the state that
  earned it.
- **Gamification is not a second progress bar.** Reviewing a course and
  writing an answer somebody accepted are the two triggers that reward doing
  something for other people — and the accepted-answer points go to whoever
  WROTE it, never the asker who marked it, or the cheapest way to earn is to
  ask yourself a question.
- **A streak counts UTC days and forgives nothing.** A grace day makes the
  number a lie, and somebody shown a 40-day streak they did not earn stops
  believing any of it. Badges read the LONGEST streak, so a break never takes
  one back.
- **Badges are re-evaluated from scratch, not incrementally.** A badge added
  months later is earned by everybody who already qualifies the next time they
  do anything — no backfill job, no support thread.
- **A leaderboard nobody can leave is hostile.** `is_ranked` opts out, and the
  builder excludes at the SOURCE so the ranks close up rather than leaving a
  gap that names the person who opted out. Weekly is the default, because an
  all-time board nobody new can appear on is a list of who joined early.

**Two things that surprised the work:**

- Two of the new triggers point at `Review` and `DiscussionReply`, which
  `analytics_events` had never touched — so neither was in the ENFORCED morph
  map, and `TriggerContext::for()` turned every review in the product into a
  500. Loudly, in the suite, which is exactly what enforcing the map is for.
  Registering a model there is now part of making it a trigger source.
- Badges count from gamification's OWN data, never from `item_progress`. Two
  consequences, both deliberate: a learner who finished fifty lessons before
  this phase has no ledger rows and no badge, and deactivating a rule freezes
  the badges that depend on it.

**Not built:** a points ledger UI beyond the last twenty entries, cohort-scoped
boards (P15 can add the scope), and any way to spend points. Points are a score,
not a currency — a shop would make every rule a pricing decision.

### Phase 15 — Live Learning ✅ complete (with one honest gap)

Cohorts, a provider seam with three implementations, sessions on the
curriculum spine, attendance, webinars, reminders and a calendar. Front and
back.

**⚠ THE GAP, stated first because it is the same one Phase 10 has.**
`ZoomProvider` and `GoogleMeetProvider` are written against the published APIs
and **have never contacted either service**. An integration cannot be proven
without credentials, and calling the phase complete without saying so is how a
feature ships and fails on its first real use. `ManualProvider` — the host
pastes a link — is fully working and tested, and is what most academies will
use anyway: Orbito keeps the schedule, the roster, the reminders and the
attendance, which is the part a video service does badly.

**The decisions worth knowing before changing any of it:**

- **The provider call happens BEFORE the row is written.** A session saved
  first and then failing at Zoom would leave a row in the schedule with no way
  to join it — visible to learners, in their calendar, dead.
- **Cancelling is the reverse:** cancelled HERE first, at the provider second,
  and a provider failure is swallowed. A session cancelled upstream but still
  `scheduled` here sends forty people to a dead link.
- **Following the link IS the attendance record.** It is the only signal every
  provider has in common, so `join` is a POST and there is no way to get the
  URL without recording the visit.
- **The join window opens fifteen minutes early**, because people arrive early
  for a class and a link that refuses them until the second is a support
  ticket every time.
- **`host_url` never leaves the server.** On Zoom the start link opens the
  meeting as the host; the model hides it, no resource names it, and a test
  asserts it never appears.
- **A live session is completable but NOT self-markable**, like a quiz and an
  assignment. The difference is only what counts as earning it: no score, no
  submission, so the evidence is attendance.
- **A cohort NARROWS the audience.** A session attached to one is for that run
  only. Showing it to everybody on the course is the mistake that makes
  cohorts pointless, and the calendar query is written around exactly that.
- **Cohort capacity is checked inside the enrolment transaction**, behind the
  cohort's own row lock — the third time this race has come up and the third
  time it is written the same way rather than checked-then-inserted.
- **`reminder_sent_at` is claimed BEFORE the reminders go out.** A crash
  halfway under-notifies a few people; the reverse mails everybody twice on
  every retry. The window has a FLOOR as well as a ceiling, so a scheduler
  that was down does not send "starts in 30 minutes" about a class that
  finished.
- **A session stores an instant and the zone it was scheduled in** — the
  opposite of every other dated thing in the system, and for a good reason:
  a class happens at a moment somebody has to be awake for.

**Webinar registration is members-only**, which is a consequence of the
tenancy design rather than a product choice: tenancy resolves from the
authenticated user, so there is no anonymous surface to register from. The
registration is already keyed on EMAIL so the public path in P16 cannot
produce two places for one person.

**The bug this phase caught, again:** `LiveSession` became an itemable and was
not in the enforced morph map, so creating one was a 500 — the identical
failure Phase 14 hit with `Review`. Loudly, in the suite. Registering a model
in the map is now part of making it an itemable or a trigger source, and it is
in CLAUDE.md twice.

**Not built:** provider-reported attendance reconciliation (the `source` column
and the interface's deliberate silence on it are the seam), recurring sessions
as a single row — a cohort's weekly call is many sessions, because the roster
and the attendance are per occurrence — and any studio UI for scheduling
beyond the API.

### Phase 16 — Advanced Business
Subscriptions and memberships · bundles · digital downloads · coaching/booking · blog ·
page builder (blocks) · multilingual content · RTL · plan limits and billing · webhooks out.

**Plan limits — done.** The oldest open item in the codebase: counters have
been maintained since P4 and nothing read them. `PlanLimits` — the class the
`plans` migration has named since Phase 1 — is now the one answer to "does
this academy's plan have room for one more?", and it is both rendered and
enforced.

- **Two kinds of cap, and the difference is deliberate.**
  `UsageMetric::isEnforced()` says which. Courses and instructor seats are the
  academy's OWN decisions, so the academy is the right party to stop: 402
  `plan_limit_reached`, with `meta` naming the metric, the cap, the usage and
  the plan. Students are not: a learner enrols, often having just paid, and
  cannot change their academy's plan. That cap is counted, surfaced as
  over-limit, and left to the operator. Storage the same until a plan
  declares a byte cap.
- **The student counter was declared and never incremented.** `UsageMetric`
  has listed it since P4 with nothing behind it. `TrackStudentUsage` counts
  DISTINCT people holding at least one access-granting enrolment, and
  `usage:reconcile` recomputes it with `COUNT(DISTINCT user_id)` so the two
  definitions cannot drift.
- **An operation is not a transition.** The enrolment events announce that
  somebody pressed suspend or extend, and both are reachable with nothing to
  change — suspending an already-suspended row, extending a live one. A tally
  driven off them double-counts on exactly those calls, and the previous
  status is gone by the time a listener runs. `EnrollmentAccessChanged` fires
  only on a real flip of `grantsAccess()` and carries the new answer, which is
  what `CourseStatusChanged`'s `became()` / `left()` does for Catalog. With
  that guarantee one question settles both directions: does this person hold
  any OTHER enrolment that grants access?
- **The check cannot be atomic with its insert, and says so.** `usage_counters`
  is central; the rows it caps are in the academy's schema. No transaction
  spans both connections, so the `lockForUpdate()` pattern the course seat
  limit uses is unavailable. An academy can end up one over its cap under a
  concurrent double-click; the nightly reconcile reports it, and nobody has
  lost a seat they paid for. Overselling a COURSE costs a learner their place;
  being one course over a billing cap costs a number.
- **`over_limit` is a state, not a corruption.** A downgrade puts an academy
  instantly over on everything it already built, and nothing is deleted to
  make it fit.
- **Two bugs found on the way.** `SubscriptionState` was briefly registered as
  a shared binding so the write gate and `PlanLimits` would agree — which
  handed the second read of a request the first read's plan, the same trap
  `CourseAccess` was burned by in Phase 9. Reverted; two lookups is the price.
  And `ApiError.isSubscriptionLapsed` keyed on **status 402**, so the new
  limit error would have raised "your subscription has lapsed" at somebody
  whose subscription is paid. It keys on the code now, with
  `isPlanLimitReached` and `isBillingBlocked` beside it.

**Bundles — done**, and they fell into a Phase 10 hole on the way.

- **A bundle owns no content.** It points at courses, and buying one fans out
  into an enrolment each with `source = bundle`. `CourseAccess` is untouched
  (ADR-03 still owns "may they consume this?"), so drip, progress, the roster
  and certificates all worked on day one. The cost is stated rather than
  hidden: a course added to a bundle after somebody bought it does not reach
  them, and the explicit "grant to existing buyers" action is deliberately not
  built — a silent backfill enrolling hundreds of people is not a side effect
  of editing a form.
- **Bundle money is allocated across its courses at ORDER time.**
  `BuildDailyRollups::courseRevenue()` reads `order_items` where the
  purchasable is a course, so a bundle line was invisible to it — an
  instructor selling mainly through bundles would have read £0 on their own
  dashboard. `RevenueAllocator` splits the price by list price, largest
  remainder, summing EXACTLY to the line, with ties broken on `course_id` so
  a re-run cannot move a penny. A fuzz test asserts the sum over 200 random
  inputs. This is the coupon problem CLAUDE.md predicted, reached early.
- **Partial overlap sells.** Owning two of five courses does not block the
  bundle — the detail returns `owned_course_ids` so the page says what is new
  before payment. Only owning ALL of them is refused, because that order has
  nothing to deliver.
- **`EnrollmentIntent::bundle()` bypasses prerequisites**, three lines below
  `purchase()`, which deliberately does not. A curated path is the most
  natural bundle there is; enforcing prerequisites would leave a buyer paid-up
  and locked out of the half they bought it for.
- **A course leaving `published` takes its bundles back to draft**, one way
  only. Re-publishing does not re-publish the bundle: the author may have
  changed it since.

**The hole: nothing in the product could set a price.** Found because a bundle
cannot be published without one. `SyncCourseProduct` was written in P10 and
**wired to nothing**, so no `Product` row was ever created outside a factory;
there was no endpoint that could write a price; and `PublishChecklist` passed
`price_configured` only for FREE courses, under a comment saying pricing would
land in P10. It did, and the check was never updated — **a paid course could
not be published at all**, and the entire paid path was unreachable through
the API. Every commerce test starts from `Product::factory()`, which mints the
row the application never minted, so the suite could not see it.

Closed rather than worked around: `SetProductPrice` is now the single write
path for `product_prices`, `CoursePricingChanged` + `SyncProductForPurchasable`
connect Catalog's events to Commerce, and `price_configured` asks whether
there is a real price in the accounting currency. Pricing got its own
permission (`course.price.own` / `.any`) rather than riding on `update`: what
a course EARNS is a different decision from what it says.

This also unblocks the Stripe sandbox test at the top of this file — you could
not previously buy a course, because you could not price one.

**Digital downloads — done.** A file an academy sells, free or paid. Buying
one grants the right to FETCH it, never an enrolment.

- **Delivery is the signed media URL certificates already use** — a fresh
  15-minute link per fetch, unlimited. The `DATABASE.md` sketch drew a
  per-purchase token with a count and an expiry; it would have been a second
  delivery mechanism that could not even count what it claimed to, because
  `media.download` streams on the signature alone, with no user. The fetch
  endpoint is the ONLY place a link is minted, and no resource carries one.
- **Owned is owned.** Archiving takes a download off sale, never out of an
  owner's library; a lapsed academy's buyers keep fetching (the fetch is a
  GET); a download with owners cannot be deleted, only archived; and the FILE
  behind any download cannot be deleted at all.
- **`DeleteMedia` soft-deletes, so no foreign key could guard that file.** The
  planned RESTRICT would have been false comfort — the bytes go before the
  row, and a soft delete is invisible to a foreign key. The guard is in the
  action, before anything is removed.
- **Downloads have their own revenue line.** A download has no course, so
  without `download_revenue_minor` the platform total would have silently
  stopped equalling the sum of its parts. The invariant is now "courses +
  downloads = platform", and a test asserts it.
- **`MediaCollection::uploadPermission()` had never been called** — every
  collection was gated by `media.upload` alone, which students hold. It is
  enforced now for `download` and `certificate`; the authoring collections
  are recorded as debt (ROLES_PERMISSIONS footnote ⁴ claimed otherwise).
- **Two bundle bugs fixed on the way**: deleting a published bundle left its
  product active — a paid basket could grant nothing — and the bundle requests
  checked media ids with `exists` alone. Both have regression tests.
- **First paint went over its 250 KB budget during this phase.** Measured
  with `npm run size` at every commit: **249.96 KB** at the start of the
  phase (0.04 KB of headroom), **250.07** after plan limits — the first
  crossing — **250.41** after bundles, **251.52** after downloads. The budget
  row had said "~246": stale, produced by a method nobody could reproduce,
  and the reason `npm run size` now exists. Even "gzip level 9" is not a
  definition on its own — Node's and Python's zlib disagree by 0.3 KB on the
  same files — so the script IS the definition.
  Of the downloads slice's 1.1 KB, the three new nav icons are 0.25; most of
  the rest is the bundler splitting two modules the shell already had
  (`auth/api/keys.ts`, `IconAlertTriangle`) into eager chunks of their own,
  because the new lazy pages share them.
- **Resolved: the bell went lazy and the budget went to 255 KB.** The bell was
  worth 1.24 KB (251.52 → 250.28) — not the 3.8 KB §16 recorded when it
  landed, because the account menu now shares its Mantine parts and its icon.
  Everything else small was measured before deciding: `NoAcademyBanner` and
  the platform guard, both operator-only, were worth 0.11 KB together, and
  with the three new nav icons swapped out as well the shell would have sat at
  ~249.9 — under by 0.1 KB, gone at the next nav entry. So the budget moved,
  deliberately, instead of four cuts buying nothing. The structural fix is
  splitting the route table: `router.tsx` is the largest module on first paint
  and grows with every route. That is its own slice.

**Upload permissions — done.** Found during downloads and closed on its own.
Every media collection except two had been open to anybody holding
`media.upload`, which every student does, so a learner could put a 2 GB
`lesson_video` on the academy's storage bill — and `ROLES_PERMISSIONS.md`
said they couldn't. Each collection now names who may write into it.

- **An upload has no resource to ask about.** `hasPermission()` with no
  scope counts academy-wide roles only, so asking it would refuse a Course
  Manager — who holds `curriculum.manage.own` on their course alone — their
  own lesson video. `holdsPermissionAnywhere()` is a third question beside
  the two the codebase had, and it is documented as never being an
  authorization for an action ON something.
- **Lists, not keys.** Admins hold `.any` and not `.own`; a single `.own` key
  per collection would have locked them out.
- **Still open** at the time: upload volume — closed in the next slice.

**Upload volume limits — done.** The half upload permissions left open: a
learner could upload 25 MB submissions at the general 120-a-minute rate,
forever, and never hand one in.

- **The quota counts what is UNUSED.** A person may hold 512 MB
  (`MEDIA_UNATTACHED_QUOTA_MB`) of avatar and submission files that nothing
  references (`UploadQuota`). A file stops counting once it is handed in:
  what a learner submits is already bounded by the assignment's file count,
  size cap and attempts, and lands in front of somebody who marks it. A cap
  on everything ever submitted would one day stop a diligent learner with
  nothing they could do about it; this one always has an answer — hand the
  files in, or remove them. The default fits the largest submission an
  assignment may ask for (20 × 25 MB), and a test holds it there.
- **Authoring collections are not counted.** What staff store is the
  academy's storage — the plan's figure, not one person's.
  `MediaCollection::hasPersonalQuota()` is exactly the collections open on
  `media.upload` alone, and a test holds the two lists together.
- **An `uploads` rate limit** — 20 a minute per person on `POST /media`, on
  top of the general one. It stops a script; the quota decides how much a
  person may keep.
- **Removing a file from the submission form now deletes it.** It used to
  drop the file from the list and leave it on the server — an orphan that
  would now count against the learner's quota where they could never see it.
- **A handed-in file can no longer be deleted.** Students hold
  `media.delete.own` and `DeleteMedia` guarded only downloads, so a learner
  could delete work waiting to be marked; the submission row copies the name
  and size, not the bytes. Found while deciding what "remove some first" was
  allowed to mean.
- **Every 429 was missing `Retry-After`.** `ApiExceptionRenderer` rebuilt the
  response and dropped the throttle's headers, on every limiter including
  login's, while API.md §5 promised them.
- **Still open:** an assignment with unlimited attempts still takes 20 × 25 MB
  per attempt — the instructor's setting, and visible in the grading queue.
  And nobody can delete a handed-in file, admins included, which moderation
  will one day need.

**Unused-upload sweep — done.** `media:sweep-unused`, nightly at 03:20 in
every academy, deletes submission files nothing used within 48 hours
(`MEDIA_UNUSED_GRACE_HOURS`). The form keeps what a learner attached only in
the page, so once it is left those files are unreachable and would count
against their quota for good — this is what keeps the quota fair over years.

- **One definition of unused.** The sweep reads `UploadQuota::unusedFiles()`,
  so a file it deletes is exactly one the quota was charging for; a test holds
  the swept collections inside the counted ones.
- **Through `DeleteMedia`, file by file.** The bytes go first, the counters
  move, and its guard still applies: a file handed in between the query and
  the delete is refused there and left alone. One stuck file does not stop the
  rest, and fails the run so an operator hears of it. `--dry-run` reports
  without deleting.
- **Not avatars.** Nothing references one yet, so a live avatar cannot be told
  from an abandoned one. They join the sweep when they are wired to a
  profile — the reference first, or every profile picture goes after two days.
- **Indexed on `(collection, created_at)`.** The sweep asks across every
  owner, which `(owner_id, collection)` cannot serve.

**Outbound webhooks — done.** An academy's Super Admin points endpoints at
other systems and picks what they receive; every delivery is signed, retried
and logged. The integrator's reference is `docs/WEBHOOKS.md`.

- **Fifteen topics, each one domain event** (ADR-12), under a dotted public
  name. Curated rather than the whole catalogue: internal plumbing is not
  offered, and neither is `UserLoggedIn`, which would stream everybody's
  sessions to a third party.
- **Names and emails ride along** with every person-event — a decision taken
  explicitly, because an endpoint is a third party. It is also why
  `webhook.manage` stays with the Super Admin alone.
- **The payload is frozen bytes.** Built in the request when the event fires
  (one indexed query when nobody is listening), stored, and signed and sent
  unchanged on every attempt; only the HTTP is queued. The fan-out can never
  throw into the request that fired the event.
- **SSRF, closed three ways:** https to a public address, checked when the
  endpoint is saved and again before every send; the connection pinned to the
  address that was checked (`CURLOPT_RESOLVE`), so DNS rebinding has no second
  lookup to win; redirects refused.
- **Eight attempts over ~45 hours**, counted on the delivery row. Five
  deliveries in a row that exhaust their attempts switch an endpoint off;
  switching it back on resets the count. Redeliver reuses the event id; a
  `ping` tests an endpoint; the log is pruned after 30 days.
- **Still open:** no secret overlap on rotation; no notification when an
  endpoint switches itself off; `enrollment.expired` fires from the sweeper,
  where the harness cannot observe listeners (EVENTS.md), so it has no
  end-to-end test; and webhooks are not a plan-gated feature.

**Coupons — done.** Codes an academy hands out for money off: percentage or
fixed, the whole basket or chosen courses, bundles and downloads, with total
and per-person limits, a window and a minimum spend. Reference:
`docs/COUPONS.md`.

- **One set of rules, asked twice.** `CouponRules` is rendered by the basket (a
  preview and a reason) and enforced by `PlaceOrder` under a lock on the
  coupon's row. A coupon that stops applying while it sits in a basket blocks
  checkout with its reason, rather than charging a price nobody was shown.
- **The known debt is closed.** A discount is computed once and split across
  the order's lines by largest remainder, and each line's total is net of its
  share — bundle allocations too — so per-course revenue plus downloads still
  equals the platform total to the minor unit. `CouponRevenueTest` asserts it
  with an awkward 1001 across a bundle and a course.
- **A use expires by the clock.** A redemption counts while its order is paid,
  or unpaid and under an hour old; an abandoned checkout gives its use back
  with nothing to sweep it.
- **A free order completes at checkout.** A 100% coupon, or a fixed one worth
  more than the basket, leaves nothing for a gateway to verify, so
  `CompleteFreeOrder` marks it paid and delivers it through `GrantOrderAccess`
  — extracted from `CapturePayment` so a free order and a paid one cannot be
  delivered differently.
- **Tutor's mistake avoided:** usage is keyed on the coupon's id, never its
  mutable code; the code is snapshotted onto the order. A used coupon is
  switched off, never deleted.
- **Still open:** automatic discounts, stacking, category scope — and refunds,
  which will have to decide whether a refunded order's use still counts.

**Refunds — done.** Money given back on an order, all of it or part of it,
through the gateway or recorded when made elsewhere. Reference:
`docs/REFUNDS.md`.

- **Claim, move, complete.** The amount is claimed under a lock on the ORDER row
  and written pending before anything leaves, so two refunds cannot give back
  the last of it twice. A provider that refuses leaves it failed, freeing the
  amount; one that accepts without settling leaves it pending.
- **A full refund takes away what that order granted** — its enrolments and
  downloads, never access from anywhere else — unless the admin opts out for a
  goodwill refund. A partial refund never touches access.
- **Split by what each line has LEFT**, down to bundle courses, so a run of
  partials lands on zero everywhere; and taken off revenue on the day it
  COMPLETED, never the day of the sale. Daily revenue is net and can be
  negative, so the rollup columns became signed; courses plus downloads still
  equal the platform total on every day.
- **Coupons:** a fully refunded order is no longer a sale and gives its use
  back (`OrderStatus::sales()`).
- **`RefundIssued`**, named since Phase 10, is built with its first consumer:
  the `refund.issued` webhook.
- **Found on the way:** the refund request first had a `method()` accessor,
  which silently overrides `Request::method()` — the HTTP verb. PHPStan's
  return-type check caught it before it shipped.
- **Provider refund webhooks — done.** Stripe's `refund.created`,
  `refund.updated` and `refund.failed` reach `ReconcileProviderRefund`: a
  pending refund asked for here completes or fails by itself, matched on the
  uuid it carried to Stripe even before Stripe's id is stored; a refund made in
  the dashboard is claimed, split and completed like any other, and a full one
  revokes. A unique index on `refunds.external_id` records each once however
  many events describe it. What the books cannot absorb is left unprocessed
  for a person (REFUNDS.md §6).
- **Still open:** refunding a single chosen line, credit notes with invoices,
  and a screen for refund reports left for a person.

Still open in this phase: subscriptions and memberships, coaching, the blog,
the page builder, multilingual, RTL.

### Phase 17 — AI
Provider abstraction · outline / lesson / quiz / description / summary generation ·
generation audit and cost accounting · UI that treats output as a draft, never a commit.

### Phase 18 — Mobile API readiness
Contract audit for UI-agnosticism · token lifecycle and device management · push
notifications · offline-friendly payloads and ETags · a reference client that proves it.

### Phase 19 — Production Hardening
Pen-test pass · 2FA · session/device management · audit log · rate-limit tuning · query
and index review under load · monitoring, alerting, backups, restore drill · CI/CD ·
public changelog.

---

## 3. MVP scope (Phases 2–11, engagement in 12)

**The MVP is:** an instructor signs up, is approved, builds a course with lessons,
quizzes and assignments, prices it, publishes it; a student finds it, buys it with a
real verified payment, learns through a good player, takes the quiz, submits the
assignment, gets graded, completes the course, and downloads a verifiable certificate.
Reviews and Q&A are in. Everything works on a phone, in dark mode, with a keyboard.

**Explicitly out of MVP:** subscriptions, memberships, bundles, downloads, coaching,
live classes, cohorts, webinars, gamification, AI, page builder, blog, content bank,
interactive quiz types, drag-and-drop certificate designer, course versioning, SSO,
multi-tenancy, RTL, real-time.

Every excluded item has a designed seam (see the ADRs) so it is additive, not structural.

---

## 4. Suggested sequencing note

P6, P7 and P8 are drawn in parallel and can be staffed that way, but if there is a single
developer, build them in the order **P6 → P7 → P8**: the player proves the progress model
before two more content types depend on it, and the quiz runner is the highest-risk UI in
the product.
