# CLAUDE.md — Orbito LMS Engineering Instructions

> This is the primary engineering instruction file for this repository.
> Read this before writing any code. It overrides habit, not the user.

---

## 1. What this project is

Orbito LMS is a production-grade, API-first Learning Management System.

- **Backend:** Laravel (API-only), MySQL 8, Redis, queues, scheduler.
- **Frontend:** React + TypeScript + Vite + Mantine + TanStack Query + React Router.
- **Not a clone.** Tutor LMS and Klasio are *references*, not blueprints.

Product references:
- Functional reference: Tutor LMS 4.0.7 (free core) at
  `/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`
- Product/UX/business reference: https://klasio.com/

See `docs/` for the full architecture. Start at `docs/README.md`.

---

## 2. Hard rules

### Never
- Never modify, delete, or touch the Tutor LMS plugin or its database. It is **read-only reference**.
- Never copy proprietary code or design from Tutor LMS or Klasio.
- Never trust the frontend for: role, permission, price, currency, payment status,
  quiz score, completion status, enrollment status, or drip unlock.
- Never put business logic in a React component.
- Never put business logic in a controller. Controllers wire HTTP to Actions.
- Never create a "god" utility class. (Tutor's `Utils.php` is 10,503 lines / 289 public
  methods — this is the single clearest anti-pattern to avoid.)
- Never introduce a second large UI component library alongside Mantine.
- Never duplicate server state into a client store. TanStack Query owns server state.
- Never write a raw `role === 'admin'` check. Use policies and permissions.
- Never merge a feature that has no tests.

### Always
- Always inspect existing code before adding new code. Search first, write second.
- Always add DB indexes for every column you filter, sort, or join on.
- Always eager-load relations used by an API Resource. Assume N+1 until proven otherwise.
- Always validate in a Form Request, authorize in a Policy, and shape output in a Resource.
- Always emit a domain event for anything another domain might care about.
- Always store money as integer minor units + an ISO currency code. Never float.
- Always store timestamps in UTC. Convert at the edge.
- Always give every list endpoint pagination.

---

## 3. Definition of Done

A feature is done only when **all** of these are true:

| # | Check |
|---|-------|
| 1 | Migration written, reversible, indexed |
| 2 | Model + relations + casts |
| 3 | Action/Service holds the business logic |
| 4 | Form Request validates every input |
| 5 | Policy authorizes every action |
| 6 | API Resource shapes every response |
| 7 | Domain events emitted |
| 8 | Feature test: happy path |
| 9 | Feature test: authorization denied |
| 10 | Feature test: validation failure |
| 11 | Unit test for non-trivial domain logic |
| 12 | Frontend: typed API client function + query/mutation hook |
| 13 | Frontend: loading, empty, error, and success states |
| 14 | Frontend: responsive (360px → 1920px) |
| 15 | Frontend: light + dark mode verified |
| 16 | Frontend: keyboard reachable, labelled, focus-visible |
| 17 | `php artisan test` passes |
| 18 | `vendor/bin/phpstan analyse` passes at the configured level |
| 19 | `pnpm typecheck` and `pnpm build` pass |
| 20 | Relevant `docs/*.md` updated |

"It compiles" is not done. "It works on my screen" is not done.

---

## 4. Backend conventions

### Layout
```
app/
  Domain/<Context>/
    Models/
    Actions/          # one public __invoke/handle per file, one job each
    Data/             # DTOs
    Events/
    Listeners/
    Policies/
    Enums/
    Exceptions/
    Support/
  Http/
    Controllers/Api/V1/<Context>/
    Requests/<Context>/
    Resources/<Context>/
    Middleware/
```

Bounded contexts: `Identity`, `Catalog`, `Curriculum`, `Assessment`, `Enrollment`,
`Progress`, `Commerce`, `Certification`, `Engagement`, `Gamification`, `Analytics`,
`Media`, `Live`, `Content`, `Notification`, `AI`, plus `Platform` (plan-limit
usage counters, which every other context increments).

Filled in so far: `Identity`, `Catalog`, `Curriculum`, `Enrollment`, `Progress`,
`Assessment`, `Media`, `Platform`. The rest are empty placeholders so the shape
of the system is visible before it is built.

### Rules
- A controller method is at most ~20 lines: authorize → validate → call Action → return Resource.
- An Action does one thing. If it needs "and", it is two Actions.
- Cross-domain communication goes through **events**, not direct model calls.
  `Progress` does not call `Gamification`. It fires `LessonCompleted`; Gamification listens.
- Anything slow (certificates, emails, analytics rollups, video processing) is **queued**.
- Enums for every status. No magic strings.
- `declare(strict_types=1);` in every PHP file.

### Naming
- Actions: `CreateCourse`, `PublishCourse`, `SubmitQuizAttempt`, `GradeAssignment`.
- Events: past tense — `CourseEnrolled`, `LessonCompleted`, `PaymentCaptured`.
- Policies: `CoursePolicy@update`, never `canEditCourse`.

---

## 5. Frontend conventions

### Layout
```
src/
  app/            # providers, router, theme, error boundaries
  shared/
    api/          # axios client, interceptors, error normalisation
    ui/           # design-system wrappers over Mantine
    hooks/ lib/ types/
  features/<domain>/
    api/          # request functions + queryOptions/queryKeys
    components/
    hooks/
    routes/
    types/
```

### Rules
- **Server state → TanStack Query only.** No `useEffect` + `useState` fetching.
- Every query is defined with `queryOptions()` in `features/<d>/api/` and reused by
  components, prefetching, and router loaders. Never inline a `queryKey` in a component.
- Query keys come from a per-feature `keys` factory. Invalidation targets a key prefix.
- Mutations: `onMutate` optimistic update only where a rollback is safe (reordering,
  toggles, notes). Never optimistic for payments, grading, or publishing.
- Client state (drawer open, builder drag state, command palette) → Zustand, small stores.
- Forms: React Hook Form + Zod resolver. The Zod schema is the single source of truth
  for the form's TypeScript type.
- No business rules in components. Derived logic goes in a hook or a pure function.
- Components import from `shared/ui` wherever a wrapper exists; import
  `@mantine/core` directly where one does not. A wrapper that only re-exports a
  Mantine component is an indirection with no payload — do not add one.
- Every list view ships: skeleton → empty state → error state → data.
- No `any`. No `@ts-ignore` without a comment explaining why.

---

## 6. Security checklist (apply to every endpoint)

- Authenticated? Which guard?
- Policy applied? Tested for the *denied* case?
- Is any ID in the payload used to look up a resource the user may not own? (IDOR)
- Is price/total recalculated server-side from the DB, ignoring the client?
- Is the payment verified against the gateway before enrollment is granted?
- Is quiz scoring done server-side, with correct answers never sent to an in-progress attempt?
- Is the file upload extension+MIME+size validated, stored outside the webroot, and
  served through a signed, time-limited URL?
- Is the endpoint rate-limited? Auth and quiz-submit endpoints especially.
- Is anything sensitive logged? (never log tokens, card data, or full payloads)

---

## 7. Workflow for any new feature

1. Read the relevant `docs/` section.
2. Search the codebase for what already exists. Reuse it.
3. Check `docs/TUTOR_AUDIT.md` for how Tutor solved it and *why we differ*.
4. Write down acceptance criteria (they become the test names).
5. Migration → Model → Action → Policy → Request → Resource → Controller → route.
6. Tests (happy / denied / invalid).
7. API client fn → query hook → component → states → dark mode → responsive.
8. Run tests, PHPStan, typecheck, build.
9. Update docs.

---

## 8. Workspace layout

```
api/    Laravel 13 backend (API only). `composer check` = Pint + PHPStan + Pest.
web/    React 19 + TS SPA.            `npm run check`  = oxlint + tsc + vitest.
docs/   Architecture and planning. Update the relevant file with every feature.
```

Settled decisions (do not relitigate without being asked):
**Laravel 13** · **multi-tenant: one database per academy** · **Stripe + PayPal for MVP** ·
Mantine 9 · TanStack Query 5 · MySQL 8 · Redis via predis · Pest · Vitest · Playwright.

## 9. Authorization — how to use what Phase 3 built

Never write `if ($user->hasRole('admin'))`. Ask what they may *do*:

```php
$user->hasPermission('course.publish.own', $course);   // scope-aware
Gate::authorize('publish', $course);                   // in a controller
```

- Permissions are declared in `config/permissions.php` and synced with
  `php artisan permissions:sync`. Adding a capability means adding a key there
  and granting it to roles — not writing a new check.
- `role_assignments` carries an optional scope. A course-scoped role (Course
  Manager, Reviewer, TA) only applies to the resource it was granted on.
- **`hasPermission($key, $scope)` returns global ∪ scoped.** To ask the narrower
  question — "do they hold a seat on THIS resource?" — use
  `hasScopedPermission()` / `hasAnyScopedPermission()`, which ignore global
  roles. Getting this wrong made every instructor staff on every course; the
  regression tests are in `CourseScopedAccessTest`.
- **`holdsPermissionAnywhere(...$keys)` is the third question** — "could they
  EVER do this kind of thing?", held academy-wide or on any resource. It exists
  for the one moment with no resource to ask about: an upload, before the file
  is attached. Asking `hasPermission()` there refuses a Course Manager their
  own course's lesson video. NEVER use it to authorize an action on a
  resource — it says yes to a manager of course 42 about course 7.
- Policies are the only place authorization decisions live. `Gate::before`
  grants Super Admin everything; that is the one blanket bypass in the system.
  It has exactly one exception: when the subject of an ability is the **platform
  owner** it falls through to the policy instead of granting, so no other Super
  Admin can delete or suspend the permanent account. See
  `docs/ROLES_PERMISSIONS.md` §7.
- `GET /auth/me` returns the caller's permission keys so the SPA can hide UI.
  That is a convenience. Every endpoint still authorizes independently, and
  every endpoint needs a test for its 403 path.

**When you add a model in Phase 4+**: register it in the morph map in
`AuthServiceProvider` if it can be a role scope, and add its policy there too.

## 10. Patterns established in Phase 4 — reuse these

- **Lifecycle changes go through one Action.** `ChangeCourseStatus` owns the
  legal transitions and the timestamps. Do not set a status column directly.
- **A "can I do X yet?" rule belongs in a checklist class**, not scattered
  through a controller. `PublishChecklist` is both rendered by the UI and
  enforced on publish, so the two cannot drift.
- **Denormalise counters, maintain them by event, reconcile them nightly.**
  `courses.rating_avg`, `usage_counters`, `course_tags.usage_count`. Never
  compute an aggregate on a read path that renders a list.
- **Media rules live on `MediaCollection`** — disk, MIME allowlist, size cap.
  Never trust a client-declared MIME type or filename.
- **An id that merely `exists` is not authorized.** Referencing another user's
  media by id is rejected in the Form Request, not just validated for existence.

## 11. Patterns established in Phase 5 — reuse these

- **`course_items` is the spine.** A new content type is one `itemable` plus an
  `ItemType` case. Never add a parallel ordering, progress or drip mechanism.
- **`position` is course-global.** Anything that writes it goes through
  `ReorderCurriculum` (whole tree, one transaction, permutation-checked) or
  `NormalisePositions`. Nothing else touches the column.
- **Bulk-move endpoints take the whole collection, not a delta.** A delta lets
  two concurrent clients interleave into a state neither asked for.
- **The query cache is the single source of truth on the client.** Do not mirror
  server state into `useState` and sync it with an effect — apply optimistic
  changes in the mutation's `onMutate` and read straight from the cache.
- **Strict mode forbids implicit lazy loading.** Reaching for `$item->course`
  inside an Action or a controller must be `loadMissing('course')`; inside a
  loop, eager-load the batch instead.

## 12. Patterns established in Phase 6 — reuse these

- **`CourseAccess` is the ONLY answer to "may they consume this?"** (ADR-03).
  Quiz-start, downloads and every later gate call it. Never write a second
  enrollment check. Adding an access source means editing that one class.
- **Never recompute an aggregate on a read path.** Store it, maintain it by
  event, reconcile it on a schedule, and treat drift as a bug alert.
- **Create per-learner rows lazily.** `item_progress` appears on first view,
  not at enrollment.
- **423 Locked, not 403**, when the caller could legitimately gain access.
  403 means "you did something wrong"; 423 means "here is how to get in".
- **Sanitise author HTML on WRITE** (`RichTextSanitizer`), never on render, so
  the stored value is safe for the API, mobile and exports alike.
- **The default auth guard is `sanctum`.** Routes that allow anonymous access
  still resolve a bearer token. Session login/logout name `web` explicitly.
- **Throttle client heartbeats in a hook, not the component.** `timeupdate`
  fires up to 60×/second; `useWatchHeartbeat` collapses that to one request
  per 15s plus a flush on unmount.

## 13. Patterns established in Phase 7 — reuse these

- **Two resources per model when the audience differs.** `QuestionResource`
  (authoring, carries the answers) and `AttemptQuestionResource` (the learner,
  cannot express them). One resource with conditional fields is one `when()`
  away from leaking (ADR-06).
- **Call `->resolve($request)` on a Resource, never `->toArray($request)`.**
  `toArray` skips `MissingValue` filtering, so `when(false)` fields serialise
  as `{}` instead of disappearing.
- **A shuffle a learner reloads must be deterministic.** Order by a hash of
  (attempt, option) — `Collection::shuffle()` is random on every call, so the
  options would jump between pages.
- **Grade a blank before deciding it needs a human.** An unanswered essay has
  nothing to read; queueing it leaves the whole result pending on an
  instructor clicking through empty answers.
- **An unscoped route binding must be re-scoped in the controller.** A question
  can be shared with a bank, so `{question}` resolves globally — membership of
  *this* quiz is then checked explicitly, or authoring your own quiz becomes
  edit-and-delete on any question id in the system.
- **Completion that is earned is not self-markable.** `ItemType::isSelfMarkable()`
  gates the mark-complete endpoint, and the player reads `is_self_markable`
  rather than deciding for itself.
- **Countdown from a monotonic clock, and never let it decide anything.**
  `useAttemptCountdown` derives the display from `performance.now()`; the
  server re-checks its own deadline on every save and on submit.
- **Mantine's `NumberInput` reports a string for anything not yet canonical**
  ("070", "1.", ""). `typeof value === 'number'` silently turns a half-typed
  field into the fallback — use `numberValue`/`optionalNumberValue`.

## 14. Patterns established in Phase 8 — reuse these

- **A "may they do this yet?" object is rendered AND enforced.**
  `SubmissionRules` is returned to the submit form and consulted by
  `SubmitAssignment`. One class, so the button and the server cannot disagree.
  Same shape as `PublishChecklist`.
- **Per-item rules narrow platform rules, never widen them.** An assignment's
  extension list and size cap sit inside what `MediaCollection` already allows;
  an author cannot type `exe` into a box and open a hole.
- **Decide against the clock once, then freeze it.** `is_late` is settled at
  submission time and stored. Moving `due_at` afterwards must not retroactively
  make somebody late, or un-late.
- **Arithmetic the policy implies is the server's job.** The late penalty is
  applied in `GradeSubmission`, not typed in by an instructor — otherwise it
  depends on them remembering, and the learner cannot see it was applied.
- **Union in SQL, do not merge in PHP.** The grading queue reads two tables;
  merging two paginated queries drops rows at every page boundary. And give the
  union a tiebreak — `submitted_at` has second precision, so without one a row
  can appear on two pages or on none.
- **Filter a shared list by what the reader may open**, not by a query
  parameter. A row that 403s when clicked is a bug, not a permission check.
- **Absent is not zero.** An ungraded submission omits `points_earned` rather
  than sending 0; "not marked yet" and "scored nothing" are different facts.
- **Never read `event.currentTarget` inside a `setState` updater.** The updater
  runs after React has released the event, so it is null and the tree crashes.
  Read the value first, then call the setter.
- **An upload response must be usable.** Endpoints that reference a file speak
  in the numeric id, so `MediaResource` returns `ref` beside the UUID — the
  same convention as `CourseItemResource` and `QuestionResource`.

## 15. Patterns established in Phase 9 — reuse these

- **Drip is asked of `CourseAccess`, not of a second gate.** `DripSchedule`
  answers "is this released yet?"; ADR-03 still owns "may they consume this?".
- **A rule with a batch path and a single path must be tested for agreement.**
  The player evaluates drip for a whole curriculum; the item endpoint
  evaluates one. An item the outline shows as open must not 423 when opened —
  `DripAccessTest` asserts exactly that, and it has already caught a real
  divergence over unpublished blockers.
- **Gates on the read path must be repeated on the WRITE path.** The progress
  endpoints resolved access at course level while the player resolved it per
  item, so a learner could complete a lesson drip had not released. Any new
  `forItem` check needs the matching write-side check.
- **Count and insert in ONE transaction.** The seat-limit count sat outside
  the transaction it then opened; two concurrent requests both took the last
  seat. It now counts behind `lockForUpdate()` on the settings row.
- **Never cache an authorization decision beyond one request.** Laravel
  memoises the controller on the `Route` object, so a per-instance memo
  outlives the request. `CourseAccess` had one; a second request could be
  served a `granted` decision made before the enrollment expired.
- **A prerequisite gates ENTRY, not continued presence.** Adding one to a live
  course must not evict the people already inside it.
- **Reset clears what was DECLARED, never what was EARNED.**
  `ItemType::isSelfMarkable()` is the line: wiping a passed quiz can leave an
  item permanently uncompletable once attempts are spent.
- **Union in SQL; resolve cross-boundary ids in PHP.** See § Multi-tenancy — the same
  instinct that merges two paginated queries in PHP also writes a `whereHas`
  across two databases.
- **`error.meta` carries what the caller can DO about a failure** — a date to
  wait for, the item that blocks this one, the courses still outstanding. A
  423 that cannot say how to get in is a dead end.

---

## 16. Patterns established in Phase 12 — reuse these

- **A "what may this reader DO?" answer belongs in the list's `meta`**,
  computed from the same rule the write endpoint enforces. `can_review`,
  `can_ask`, `can_moderate`, `can_manage`. A page then renders a form or an
  explanation, never a button that 403s — the same instinct as
  `PublishChecklist` (§10) and `SubmissionRules` (§14), applied to a list.
- **Per-ROW capabilities go on the detail, never in the list.** A `viewer`
  block is emitted only when the thread's replies are loaded, because each key
  is a policy call resolving `CourseAccess` and thirty rows would be ninety of
  them. Same for `is_wishlisted` on the course detail but not on the card.
- **A notification is a frozen MESSAGE, not a live view.** The payload is built
  when the event fires and never re-read: editing an announcement afterwards
  must not rewrite the mail already in somebody's inbox, and nothing has to be
  re-queried in a worker.
- **One notification class carrying a payload**, not one subclass per type.
  Both channels render the same three fields, so the bell and the email cannot
  drift apart. Adding a type is a `NotificationType` case plus a listener.
- **`DomainNotification::via()` is the SINGLE enforcement point** for
  preferences. No listener consults them, so a delivery raised from anywhere
  obeys the switches without having to remember to ask.
- **Store overrides, not state.** A missing `notification_preferences` row
  means the type's default, so a new type ships without a backfill across every
  academy and a changed default reaches whoever never touched the switch.
- **Never notify somebody about their own action.** That is what teaches people
  to ignore a bell.
- **Store a RELATIVE path in anything long-lived.** `action_path` is routed on
  internally by the SPA and rendered absolute only at send time; a stored
  absolute URL rots the day an academy changes address.
- **A tenant table a CENTRAL model relates to needs `LivesInTenantSchema` AND
  the relation overridden.** `User::notifications()` replaces Laravel's, which
  fixes the WRITE path too — the database channel routes through that same
  relation.
- **Declare static route segments before the dynamic one.**
  `learn/:courseId/announcements` sits above `learn/:courseId/:itemId`, and
  those paths are part of the notification contract rather than a convenience:
  a link in a year-old email has to still land somewhere.
- **A button inside an anchor is not a button.** The wishlist toggle lives on
  the course page, not the catalogue card, because the card is one `<Link>`.
- **Measure the first-paint cost of anything in `AppLayout`** — and measure it
  the SAME way each time (see Known debt), or the number drifts into a figure
  nobody can reproduce. The bell cost 3.8 KB when it landed and 1.74 KB by
  Phase 16, once the account menu shared its Mantine parts — it is lazy now
  (`LazyNotificationBell`, with a same-size placeholder so the header does not
  shift). Mantine is a shared chunk, so a lazy route does not keep its imports
  out of it.

---

## 17. Patterns established in Phase 13 — reuse these

- **A log is written by listeners and read by nobody but a rollup** (ADR-08).
  Dashboards read rollups. The moment one screen queries `analytics_events`
  directly, that metric has two definitions.
- **An analytics write may never throw into its caller.** `RecordEvent`
  reports and returns null. Analytics observes the system; a full disk must
  lose a row in a traffic count, not break somebody's lesson. There is a test
  that drops the table and asserts enrolment still works.
- **A client may raise only what the server cannot see.**
  `EventName::isClientRaisable()` is the whole security boundary of the ingest
  endpoint. Adding a case there is a decision about trust, not a convenience.
- **Clamp any timestamp a client sends.** A device with a wrong year writes
  into next month's report, where nothing ever removes it.
- **A log table gets no foreign keys.** An event is a fact about the past;
  cascading from the thing it describes erases the history of it. Store a
  morph-map ALIAS in `subject_type` — a report read years later must survive a
  class moving namespace.
- **Every rollup is an upsert on its primary key.** "Run it again" has to be
  the recovery path for a failed night, not a corruption. A derived table that
  cannot be dropped and rebuilt is a fact table pretending to be a cache.
- **Pick one source per figure and say which.** Behaviour from the log, money
  from the ledger, the funnel from `item_progress`. Two derivations of one
  number are two numbers.
- **Aggregate queries return `stdClass`, not models.** `->toBase()` on a
  GROUP BY, because hydrating a model whose columns are `SUM(...)` hands every
  caller an object that lies about its own type — and PHPStan says so.
- **Densify a series server-side.** A day with no row must come back as zero,
  or a chart draws a straight line across the gap and reports activity that
  never happened.
- **Never sum distinct people across days.** Thirty daily active counts added
  together count a regular thirty times. Report a peak and name the field for
  what it is.
- **Cap any range a dashboard can ask for.** Presets, not a free date pair:
  "since the beginning" is a table scan somebody requests by accident.
- **A hook goes above the early returns.** React counts hooks; one placed
  after a `return` for a pending query is a hook that sometimes does not run.
- **Guard a fire-once effect with a ref.** React 19 runs effects twice in
  development, which double-counts every view in exactly the environment where
  somebody first checks the numbers.
- **Weigh a chart library against the bundle before reaching for one.** 90–150
  KB gzipped for the single screen that draws a line; ~90 lines of SVG did it
  for 0.2 KB.

---

## 18. Patterns established in Phase 14 — reuse these

- **Make idempotency a CONSTRAINT, not a check.** A nullable `dedupe_key` with
  a unique index lets one column serve both "once per source" and "repeatable"
  rules — MySQL allows any number of NULLs — and the action CATCHES the
  violation. A check-then-insert loses exactly the race a double click creates.
- **Anything a user can earn, somebody will try to farm.** Ask what happens
  when they undo and redo it. If the answer is "they earn again", the design
  is wrong before the code is.
- **Rules and thresholds are DATA an academy tunes; config is only the SEED.**
  A sync that updated would make the file the truth and revert every academy's
  tuning on deploy. Create-only.
- **A closed set of operators beats an expression language**, and an unknown
  one must REFUSE rather than pass. A DSL over JSON an academy can edit through
  the API is both undebuggable and a sandbox escape somebody eventually writes.
- **Evaluate against the event's payload, never a re-read model.** A queued
  listener runs after the row may have changed; re-querying awards on the
  state it finds rather than the state that earned it.
- **Registering a model in the morph map is part of making it a source.**
  Adding a trigger for `Review` — a model analytics had never touched — turned
  every review in the product into a 500. That the map is ENFORCED is why it
  failed loudly in the suite instead of storing an FQCN nobody would notice.
- **Derived counters read the domain's OWN data.** Badges count the ledger,
  not `item_progress`. It costs history that predates the feature and buys a
  context with no reach into another's tables — say which you chose, in the
  code.
- **A number nobody can trust is worse than no number.** A streak forgives
  nothing; a badge reads the LONGEST streak so a break never revokes one.
- **Re-evaluate from scratch when the set is small.** Badges are recomputed on
  every balance change, which means one added months later is earned by whoever
  already qualifies — no backfill, no support thread. Only the UNHELD ones are
  checked, so somebody with all of them costs one query.
- **Anything that ranks people needs a way out.** Exclude at the SOURCE, or the
  gap in the ranks names the person who opted out. And say plainly that opting
  out costs them nothing else.
- **A leaderboard is a snapshot.** Computing one per page load sums the whole
  ledger and reshuffles under the reader. Ship `computed_at` so they know.

---

## 19. Patterns established in Phase 15 — reuse these

- **Call the outside world BEFORE writing the row, and undo it after.**
  Creating a meeting fails first so the academy learns at the moment of
  scheduling; cancelling writes locally first so a provider outage cannot stop
  an academy calling off a class. The order is opposite in each direction and
  both directions are the safe one.
- **A provider integration you cannot exercise is UNPROVEN, and the code says
  so.** Zoom and Google Meet carry a ⚠ in their docblocks. The one that works
  is the manual one, and it is not a fallback.
- **`cancel()` must not throw when the thing is already gone.** A 404 upstream
  is the outcome the caller wanted; treating it as failure leaves the record
  cancelled here and live there.
- **A credential-bearing URL is `$hidden` on the model AND absent from every
  resource.** Two independent misses are needed to leak it, and there is a
  test asserting the string never appears in a response.
- **Derive a status from the clock; never sweep it.** A status that needs a
  cron to become true is wrong for as long as the cron is late — which is
  exactly when somebody is trying to use it.
- **Open a time window early on purpose.** People arrive early; a door that
  refuses them until the second is a support ticket every time. Fifteen
  minutes, stated in the code.
- **Claim a "did we already send?" flag BEFORE sending.** Crashing halfway
  under-notifies a few people; the reverse mails everybody twice on every
  retry. And floor the window as well as capping it, or a late sweeper
  announces something that has already finished.
- **Anything that reschedules must clear what the old schedule triggered.**
  A moved session with a stale `reminder_sent_at` never reminds again.
- **A scoping column NARROWS an audience, and every read has to honour it.**
  A cohort's session shown to the whole course is the mistake that makes
  cohorts pointless.
- **A capacity check belongs inside the transaction that inserts**, behind the
  row's own lock. Third time in this codebase; write it the same way.
- **An instant and a timezone are two facts.** Store both when the thing
  happens at a real moment somebody has to be awake for — the opposite of the
  UTC-day rule for anything derived.
- **Register a model in the morph map when you make it an itemable or a
  trigger source.** Second phase running that this was the bug; the enforced
  map is why it failed loudly instead of storing an FQCN.

---

## 20. Patterns established in Phase 16 — reuse these

- **`PlanLimits` is the ONLY answer to "does this academy's plan have room?"**
  — the class the `plans` migration has named since Phase 1. Same shape as
  `CourseAccess` (ADR-03): a shared service crossing contexts on purpose,
  because a limit must be answered synchronously and an event cannot say no.
  Adding a capped dimension is a `UsageMetric` case with a `planKey()`, never
  a second check somewhere else.
- **A counter's name and a plan's key are separate vocabularies.**
  `courses_total` is what we count; `max_courses` is what an operator types
  into a JSON column. `UsageMetric::planKey()` is the only place they meet, so
  renaming a counter cannot silently uncap every academy.
- **Cap the party who can DO something about it.** `isEnforced()` is that
  decision, declared once. An academy's own actions (a course, a seat) are
  blocked at the cap; a learner's enrolment never is, because they have just
  paid and cannot change their academy's plan. Counting and surfacing a cap
  without enforcing it is a legitimate answer, and the resource says which is
  which — a panel that showed the student cap as a wall would be lying.
- **Some checks CANNOT be atomic, and the comment has to say so.**
  `usage_counters` is central; the rows it caps live in the academy's schema,
  so no transaction spans both and `lockForUpdate()` is unavailable. Being one
  over a billing cap is bounded and reconciled nightly. Do not copy this
  reasoning to a seat limit — overselling a course costs a learner their
  place.
- **Over-limit is a STATE.** A downgrade puts an academy instantly over on
  everything it already built. Nothing is deleted to make it fit, and every
  read path has to be able to render a number larger than the allowance.
- **An operation is not a transition, and a tally needs the transition.**
  `suspend()` on a suspended row and `extend()` on a live one both fire their
  event and change nothing; a counter driven off the event double-counts on
  exactly those calls, and the previous status is gone by the time a listener
  runs. `EnrollmentAccessChanged` fires only on a real flip and carries the
  new answer — `CourseStatusChanged`'s `became()` / `left()`, reduced to a
  boolean. Given that, one question settles both directions: does this person
  hold any OTHER enrolment that grants access?
- **A derived counter and its reconcile are ONE definition written twice.**
  `TrackStudentUsage` and `ReconcileUsageCounters` must agree on what a
  student is — access-granting, distinct by person — or the nightly job
  reports drift that is not there and hides the drift that is.
- **Two 402s are two problems.** `subscription_lapsed` and
  `plan_limit_reached` share a status and nothing else. `ApiError` keys on the
  CODE; keying on the status told somebody at their course cap to renew a
  subscription they had already paid for.
- **Never make a lookup shared to save a query.** `SubscriptionState` was
  briefly a `scoped` binding so the write gate and `PlanLimits` would agree;
  Laravel's container outlives a request under Octane and inside a test, so
  the second read got the first read's plan. Third time this codebase has hit
  it (§ Phase 9, `CourseAccess`).
- **Mantine's `Alert` is `role="alert"` by default.** A standing explanation
  that announces itself on every render teaches a screen-reader user to ignore
  the one that matters. Use `role="note"` for the paragraph that is always
  there.
- **A new purchasable OWNS NO CONTENT.** A bundle points at courses and buying
  one fans out into an enrolment each; `CourseAccess` never sees a bundle,
  because ADR-03 still owns "may they consume this?" and a bundle is one of
  the ways an enrolment comes to exist. What you bought, you keep — the
  §Phase 9 rule that a prerequisite gates ENTRY, not continued presence.
- **Money that arrives as one line must be SPLIT before it can be reported
  per course.** `courseRevenue()` reads `order_items` where the purchasable is
  a course, so a bundle line is invisible to it. `RevenueAllocator` allocates
  at ORDER time — largest remainder, summing exactly to the line, ties broken
  on `course_id` so a re-run cannot move a penny — and stores it. Anything
  that rounds independently loses or invents money, and the platform total
  then disagrees with the sum of its own parts.
- **An allocation is a SNAPSHOT.** Repricing a course next month must not
  rewrite what last month's report said it earned. Same rule as
  `order_items.title_snapshot`.
- **Partial overlap sells; total overlap does not.** Refusing a five-course
  bundle over one purchase last year is hostile. Return what they already own
  so the page can say what is new BEFORE payment, and refuse only the order
  with nothing to deliver.
- **A dead wire is invisible to a suite that starts downstream of it.**
  `SyncCourseProduct` was written in P10 and called by nothing, so no product
  existed outside a factory, no price could be set, and `PublishChecklist`
  blocked every paid course. Every commerce test began at
  `Product::factory()`, minting the row the application never minted. When a
  fixture builds something the app is supposed to build, at least one test
  must build it the app's way.
- **A comment that says "until then" is a bomb with no timer.**
  `price_configured` read `pricing_model === Free` for six phases under
  "Pricing lands in Phase 10". Tie the temporary check to the thing that will
  replace it, or it outlives everyone's memory of it.
- **Two audiences for a price, too.** `PriceView` is the CATALOGUE's answer
  and is null for anything not sellable — which a draft course's product
  always is. The authoring endpoint returns `ProductPriceResource`: what is
  stored, on sale or not. ADR-06 again.
- **A signed URL is the credential, so mint it in ONE place.** `media.download`
  streams on the signature alone — no user — so the only access check a file
  ever gets is at mint time. Downloads mint in `GET /downloads/{slug}/file`
  and nowhere else; no resource carries a link, or a list would hand out one
  per row. Want a count? You can only count MINTS; a cap on those spends a
  download every time a transfer fails halfway.
- **A soft delete is invisible to a foreign key.** `DeleteMedia` soft-deletes
  the row and removes the bytes FIRST, so a RESTRICT on a column pointing at
  media guards nothing. A "may this be deleted?" rule belongs in the Action,
  before anything is removed.
- **A declared permission hook that nothing calls is not a permission.**
  `MediaCollection::uploadPermission()` existed from Phase 4 and was never
  read, while a doc footnote claimed students could upload only into two
  collections. Grep for the CALL site, not the definition.
- **Deleting a purchasable must retire its product.** A product that outlives
  its purchasable can still be checked out of somebody's basket; the capture
  then takes the money and finds nothing to grant. `BundleDeleted` /
  `DownloadDeleted` → `SyncProductForPurchasable` retires it. Retire, never
  delete: an order line already points at it.
- **Owned is owned, for every kind of product.** Archiving takes something off
  sale, never out of an owner's hands; a lapsed academy's buyers keep reading
  (GETs are not gated); a thing with owners is archived, not deleted.
- **A lifecycle reconciliation goes ONE way.** A course leaving `published`
  takes its bundles to draft; re-publishing it does not put them back on sale.
  The author may have removed a course or changed the price since, and a
  change elsewhere must not sell something on their behalf.
- **A quota counts what NOTHING ELSE bounds.** `UploadQuota` charges a person
  only for files nothing references. A handed-in file is already bounded by
  the assignment's rules and seen by whoever marks it; a cap on everything
  ever submitted would one day stop a diligent learner with nothing they could
  do about it. Ask what the person over the limit can DO — here, hand the
  files in or remove them.
- **A form that removes an uploaded file must DELETE it.** Dropping it from
  local state left an orphan its owner could never see again — harmless until
  something counts it.
- **Handed in is handed in.** A file a submission points at cannot be deleted:
  the row copies the name and size, not the bytes. Same guard, same place, as
  a download's file (`DeleteMedia`).
- **A rejection's headers are part of its answer.** `ApiExceptionRenderer`
  rebuilt every 429 without `Retry-After`, on every limiter. An envelope that
  drops "when may I retry?" is a dead end — the 423 rule again.
- **A sweep deletes from the limit's OWN definition.** `media:sweep-unused`
  reads `UploadQuota::unusedFiles()`, narrowed by
  `MediaCollection::sweptWhenUnused()`, so a file it removes is exactly one
  the quota was charging for. And a thing nothing references YET (avatars) is
  not an orphan, it is unwired: sweep only what could have been used and
  wasn't.
- **A request our servers make to a URL somebody typed is SSRF until proven
  otherwise.** `WebhookTarget` vets EVERY address a host resolves to — when the
  endpoint is saved and again before every send — and the delivery connects
  to the address it vetted (`CURLOPT_RESOLVE`), with redirects refused.
  Checking the name and then letting the HTTP client resolve it again is the
  DNS-rebinding hole.
- **Sign frozen bytes.** A webhook body is encoded once, when the event fires,
  stored, and signed at send time. Re-encoding on a retry is a chance to send
  different bytes under the same event id — and the receiver hashes the bytes.
- **Split a discount across the LINES, never just the order.** Every revenue
  figure sums order lines (and bundle allocations), so a discount held only on
  the order makes them disagree by exactly the discount. `CouponDiscount`
  computes it once, splits it by largest remainder, and each line's
  `total_minor` is net of its share; a discounted bundle's courses share its
  NET line. `CouponRevenueTest` is the proof, with an amount that divides
  evenly nowhere.
- **A place held by an unpaid order expires by the clock.** A coupon
  redemption counts while its order is paid, or unpaid and inside the
  reservation window; an abandoned checkout gives the use back with nothing to
  sweep it. The count and the insert still happen under the coupon row's lock
  — fourth time in this codebase; write it the same way.
- **An order the SERVER priced at zero needs no gateway.** ADR-05 refuses to
  believe the client about money; a free order has none to believe. It
  completes at checkout through `GrantOrderAccess`, the same delivery a
  captured payment uses, and fires no `PaymentCaptured` — nothing was.
- **Retry state belongs on the row, not the queue.** `DeliverWebhook` counts
  `attempts` in the database and `release()`s, which the sync test queue
  ignores; tests drive each retry by running the job again. The alternative —
  re-dispatching — retries eight times inside the request that fired the event.

---

## 21. Multi-tenancy — read this before touching a model or a query

> Code comments cite this section as **`(§ Multi-tenancy)`**, by name and not
> by number. It has been §16 through §21 as phases added their own
> pattern sections, and thirty comments quietly pointed at the wrong place
> each time. Cite any section of this file by its NAME.

One MySQL schema per academy, via `stancl/tenancy`. Isolation is
**structural**: a query that forgets a filter still cannot reach another
academy, because that data is not on the connection.

**The boundary.** Central: `tenants`, `users`, `sessions`,
`password_reset_tokens`, `personal_access_tokens`, `user_social_links`,
`plans`, `subscriptions`, `usage_counters`. Everything else — including
`roles`, `permissions` and `role_assignments` — is per-academy.

**Tenancy resolves from the authenticated user** (`tenant` middleware, always
after `auth:sanctum`). Consequences you cannot design around:

- There is **no anonymous surface**. The catalogue, course pages, previews and
  the player are members-only. `is_preview` means "try before you *enrol*".
- A route with no user cannot resolve an academy. The signed media download
  carries the tenant inside the signed payload (`tenant.signed`); Phase 10
  webhooks must do the same.
- Never enable `makeTenancyMiddlewareHighestPriority()`. It would run the
  tenant middleware before `auth:sanctum`, which has no user to read.

**Every bug this has produced was the same question — which connection is this
running on?** They do not look alike:

- Eloquent copies a **pinned parent's** connection onto an unpinned child, so
  `$user->courses()` looked centrally. A tenant model a central model points
  at needs `LivesInTenantSchema`.
- `whereHas`, `has` and `orderBy(subquery)` compile to ONE statement. Across
  the boundary they cannot work. Resolve ids on one side, then `whereIn`.
- Validation rules name a table, not a connection: central tables must be
  written `unique:mysql.users,email`.
- A central model that is not pinned (`protected $connection = 'mysql'`)
  follows the academy's connection the moment tenancy initialises.
  `CentralModelConnectionTest` enforces this — extend `CENTRAL_TABLES` when
  you add one.
- `tenancy()->initialize()` **purges** the connection, discarding any open
  transaction. It short-circuits when the tenant is already active, which is
  why `RunsForEveryTenant` restores the caller's context instead of ending.

**Login and register run BEFORE the tenant middleware could know whose academy
to open** — it reads the authenticated user, and there is none yet. Login reads
it from the account; **register is TOLD**, by an `academy` slug in the request
that comes from the link an academy hands out, because a signup has no account
to read it from. Their session payload is mostly tenant data (roles,
permissions, instructor profile), so `AuthenticatedAcademy` opens the academy
first and loads the relations second. The reverse order is a 500 naming whichever tenant table it reached
first, and **the harness hides it** by leaving an academy open all test long.
`PlatformOwnerTest` calls `tenancy()->end()` to defeat that, the same trick
`ScheduledCommandTest` uses.

**Anything scheduled runs centrally with no academy open**, so it must walk
them (`RunsForEveryTenant`). Tests cannot catch this on their own — the
harness leaves a tenant open during `$this->artisan()`, which is what
`ScheduledCommandTest` exists to defeat.

**Testing.** One schema per *process*; both connections transacted. A test
that switches tenants must opt out with `SwitchesTenants`, or its own
fixtures vanish when the connection is purged.

**Platform vs academy.** `users.is_super_admin` is the platform operator, who
belongs to no academy. `RoleKey::SuperAdmin` is a role granting everything
**within one academy**. Similar names, unrelated powers.

**`subscription` gates writes only.** A lapsed academy reads and exports
everything; 402, never 403. The platform admin surface sits outside the gate
so the action that fixes a lapse survives it.

---

## 22. Current phase

**Phases 0–15 complete**, front and back, plus a **multi-tenancy retrofit**
(T1–T7) that reversed the single-tenant decision. **Phase 16 in progress:
plan limits, bundles, course pricing, digital downloads, upload
permissions, upload volume limits, outbound webhooks and coupons**
(§ Patterns established in Phase 16).
1,302 backend tests / 4,750 assertions · 314 frontend tests.

Per-phase retros — what each delivered, decided, and deliberately left — are in
`docs/ROADMAP.md`. This section is only what a new session needs before
touching anything.

### What to do next

**1. One Stripe sandbox payment.** Every MVP phase has shipped, but the MVP is
not signed off: its own definition (`docs/ROADMAP.md` §3) says a student "buys
it with a real verified payment", and `StripeGateway` has never contacted
Stripe. Commerce is complete and tested against `FakeGateway`. This needs
credentials, not code — and as of Phase 16 a course can finally be PRICED
through the API, which it could not be before.

**2. Zoom / Google Meet, likewise.** Both providers are written and have never
been called. `ManualProvider` works and is what most academies will use.

**3. Phase 16 (Advanced Business), continued.** Plan limits, **bundles**,
**digital downloads**, **upload permissions** and **upload volume limits** are
done — bundles closed a Phase 10 hole on the way (nothing could set a price),
downloads fixed two bugs bundles shipped, uploads closed a hole downloads
found, and volume limits closed the rest of it (§ Patterns established in
Phase 16), and a nightly sweep now deletes the submission uploads nothing
used. **Outbound webhooks** (`docs/WEBHOOKS.md`) and **coupons**
(`docs/COUPONS.md`) are done too. Also ahead: refunds — which must decide
whether a refunded order's coupon use still counts — subscriptions and memberships (after the Stripe test), coaching, blog,
page builder, multilingual, RTL. It is
markedly larger than the phases before it, and it is where the public
marketing surface finally arrives — which is what webinar registration and
lead capture have both been waiting for.

### The platform owner

One permanent account — `config('orbito.owner')`, created and repaired by
`EnsurePlatformOwner` after every central migration and by
`php artisan orbito:ensure-owner`. It is the only account holding BOTH
super-admin answers: the central `is_super_admin` flag (the academy registry)
and the `SuperAdmin` role inside every academy. `POST /admin/tenants/{t}/enter`
moves it between academies; `users.tenant_id` is which one it is inside.

Delete, suspend and demote are each refused in three independent places — the
policy, the Action, and `User::deleting` — because any one alone is a hole.
`isPlatformOwner()` is the configured EMAIL, not a column: a boolean somebody
can set is one somebody can unset. Full account in `docs/ROLES_PERMISSIONS.md` §7.

The registry's screens are `/platform/academies`, guarded by
`RequirePlatformOperator` — the operator FLAG, never a permission, because
permissions are roles and roles live inside an academy. Two rules that surface
there and generalise:

- **A legal-transition list belongs on the resource, from the rule that
  enforces it.** `TenantStatus::allows()` is read by `ChangeTenantStatus` and
  rendered as `available_actions`, so a button that would 409 cannot exist. The
  §16 `meta` pattern, applied to a single row.
- **Switching academies clears the whole query cache.** `tenant_id` decides
  which schema every request resolves against, so after `enter` every cached
  answer belongs to the academy just left. `queryClient.clear()` is the correct
  amount, not a heavy hammer.
- **An operator inside NO academy gets 409 `no_academy_selected`**, not a 500
  about a missing table. `/auth/me` is the single exemption — it opts in with
  `->defaults('tenant_optional', true)` and degrades to empty roles, because it
  is the answer that sends them to the registry. A route that needs an academy
  and does not have one must say so.

### Traps that are still live

Every one of these has already cost time at least once.

- **Two Laravel SPAs on `localhost` share the `XSRF-TOKEN` cookie.** Cookies
  are per host, not per port, so another local Laravel app breaks Orbito's
  login with "CSRF token mismatch" while Orbito's own config is correct. Run
  Orbito on `orbito.localhost` (`docs/RUNNING.md`); `phpunit.xml` pins its own
  hosts, so the suite passes either way.
- **The `.own` permission trap, four times over.** Every instructor holds the
  `.own` keys GLOBALLY, so `hasPermission('x.own', $course)` — global ∪ scoped
  — is true for every course in the academy. Use `hasAnyScopedPermission()` for
  "do they staff THIS course?". See § Authorization and `CourseScopedAccessTest`.
- **Register a model in the MORPH MAP** when it becomes an itemable, a
  gamification trigger source, or an analytics subject. The map is enforced, so
  forgetting is a 500 on the write path that caused it — which is how it was
  caught in P14 (`Review`) and P15 (`LiveSession`), both times by the suite.
- **The queue is synchronous in tests**, so a listener that writes a file
  writes a real one and a listener that sends mail really sends it. Fake the
  disk in `beforeEach`, not the test body. And `Notification::fake()` and
  asserting a database row are mutually exclusive — fake to assert channels,
  do not fake to assert the row landed.
- **The suite drops tenant schemas by PREFIX.** `tearDownUsesSharedTenant()`
  drops everything matching `tenancy.database.prefix` except the shared one, so
  the test run needs its OWN prefix — `TENANCY_DB_PREFIX` in `phpunit.xml`.
  Separate databases are not enough. Before that override, running the suite
  destroyed the developer's own academies, and the symptom appeared in a
  different terminal as `Unknown database` mid-migration.
- **A scheduled command runs centrally with NO academy open.** It must walk
  them (`RunsForEveryTenant`). The harness hides this; `ScheduledCommandTest`
  exists to defeat the harness, and every new scheduled command belongs in it.
- **Factories lie about defaults, deliberately.** `AnnouncementFactory` makes a
  draft, `CohortFactory` makes a draft, `WebinarFactory` makes a draft, and
  `ProductFactory::pricedAt()` defaults to USD while `orbito.currency.base` is
  BDT. Publishing through the Action is what fires the event.
- **A new academy ships with default gamification rules** (`TenantDatabaseSeeder`).
  An engine test that wants to control its own rules must clear the table
  first — the seeded `lesson.completed` rule otherwise pays out alongside
  whatever the test created.
- **`EventName::isClientRaisable()` is a security boundary**, not a filter.
  Adding a case lets a browser assert that fact.
- **`point_transactions.dedupe_key` is the anti-farming constraint.** The
  action CATCHES the unique violation rather than checking first. Do not
  "simplify" it into a check-then-insert.
- **A platform operator with no academy sees no product.** `tenant_id` null
  means the central connection, where none of the domain tables exist. That is
  why `EnsurePlatformOwner` adopts an academy and why `enter` exists — an
  operator staring at empty screens has usually just left one.
- **`host_url` and gateway `credentials` must never reach a client.** Both are
  `$hidden` and absent from every resource; there is a test asserting the
  session start link never appears in a response.
- **The in-app notification channel cannot be switched off**, by design. The
  API 422s a request to disable it. Do not turn that into a silent no-op.
- **UTC days vs instants are both deliberate and not in conflict.** Analytics
  rollups, streaks and leaderboards use a UTC day because a period has to be
  rebuildable; a live session stores an instant and its scheduled zone because
  a class happens at a moment somebody has to be awake for.

### Known debt, deliberately left

- **Invitations are declared and not built.** `RegistrationMode::Invite` exists
  so an academy that wants a controlled roster is not silently given open
  signup; the API refuses it as a value and the UI greys it out. Building it
  means an invitations table, an accept flow and an admin screen.
- **A course added to a bundle after purchase does not reach existing
  buyers.** Deliberate: the fix is an explicit "grant to existing buyers"
  action with its own confirmation, not a side effect of saving a form.
- **Bundles cannot hold downloads.** Downloads exist now, but `bundle_items`
  still names `course_id`: teaching it about downloads means a morph there, a
  grant that switches on type, and an allocation target that is not a course.
  Its own slice.
- **Avatars are counted but never swept.** Nothing references an avatar yet —
  the header draws initials — so every avatar reads as unused, and a live one
  cannot be told from an abandoned one. Wiring avatars to a profile means
  adding that reference to `UploadQuota::unusedFiles()` FIRST, then turning on
  `MediaCollection::sweptWhenUnused()` for them; the other order deletes every
  profile picture two days after it is uploaded. Nobody can delete a
  handed-in file either, admins included; moderation will one day need that.
- **Webhooks: no secret overlap on rotation**, no notification when an
  endpoint switches itself off, and `enrollment.expired` has no end-to-end
  test — it fires from the sweeper, where the harness cannot observe
  listeners (`docs/EVENTS.md`). Not plan-gated either; if it becomes a paid
  tier, the cap belongs in `PlanLimits`.
- **Nothing scans uploads**, and every byte goes through PHP — the
  direct-to-storage flow `MediaStatus::Pending` was declared for was never
  built. Downloads cap at 500 MB and allow no executables; that allowlist is
  the whole defence.
- `UpdateCourseRequest` and `UpsertLessonRequest` carry private copies of the
  owned-media check that `ValidatesOwnedMedia` now shares.
- **The first-paint budget is 255 KB, raised from 250 in Phase 16 on
  purpose**, and first paint is 250.75 — 250.28 after the bell went lazy,
  then +0.30 for the webhooks nav icon and +0.17 for coupons'. At 250 the
  shell had 0.04 KB of room; measured, no set of small cuts bought more than
  ~0.1 KB. The real fix is splitting the route table — `router.tsx` is the
  largest module on first paint and grows with every route — and it is its
  own slice. `npm run size` is the ONLY measurement: Node's and Python's zlib
  disagree by 0.3 KB at the same "level 9", so a number from anywhere else is
  not comparable. Raising the budget again is a decision for ROADMAP, not a
  flag to flip.
- Plan **limits** enforce courses and instructor seats only. Students and
  storage are counted and shown, never blocking — see § Patterns established
  in Phase 16. Storage has no cap in any seeded plan yet, and an academy
  cannot change its own plan: `/admin/plan` reads, the operator writes.
- **Playwright covers phases 2–3 only.** Two spec files, thirteen phases ago.
  The host cannot run it (Ubuntu 20.04); CI can.
- **The platform UI covers the registry, not the platform.** `/platform/academies`
  ships list, provision, approve/reject/suspend/reinstate, plan and renewal, and
  enter/leave. There is still no operator view of usage across academies, no
  audit of who approved what, and no screen for editing plans themselves —
  `config/orbito.php` and the database are the only way to change one.
- **No studio UI for scheduling** live sessions or cohorts. The API is
  complete; the authoring screens are not.
- **No provider-reported attendance.** `session_attendance.source` and the
  interface's deliberate silence on the subject are the seam.
- Analytics has **no per-student activity view** (L6), and the instructor
  series has **an endpoint with no screen**.
- Points cannot be **spent**. They are a score, not a currency; a shop would
  turn every rule into a pricing decision.
- A notification fan-out issues **one preference lookup per recipient** inside
  the queued job. Correct and cacheless; a batch resolver is the fix if a
  five-thousand-learner announcement ever hurts.
- The roster cannot **sort by learner name** — a central column against tenant
  rows. The fix is denormalising the name onto `enrollments`.
- `ItemEditorDrawer` issues **two sequential writes** (lesson body, then drip
  fields). Body first is deliberate; a failure between them is a partial save
  with no test.
- The suite takes **~14 minutes** on a quiet machine, up from ~2, because
  provisioning tests
  build real schemas. Provision one academy per FILE rather than per test
  where it hurts.
- A test artifact (`storage/tenanttest/…pdf`) is committed in 998ee74 and
  6423ce9. Ignored now; dropping it needs a rebase.
