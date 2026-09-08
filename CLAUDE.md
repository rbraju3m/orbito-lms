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
- Policies are the only place authorization decisions live. `Gate::before`
  grants Super Admin everything; that is the one blanket bypass in the system.
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
- **Union in SQL; resolve cross-boundary ids in PHP.** See §18 — the same
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
- **Measure the first-paint cost of anything in `AppLayout`.** The bell cost
  3.8 KB gzipped; Mantine is a shared chunk, so a lazy route does not keep its
  imports out of it.

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

## 18. Multi-tenancy — read this before touching a model or a query

> Code comments cite this section as **`(§ Multi-tenancy)`**, by name and not
> by number. It has been §16, §17 and now §18 as phases added their own
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

## 19. Current phase

**Phases 0–13 complete** front and back, plus a **multi-tenancy retrofit**
(T1–T7) that reversed the single-tenant decision.
915 backend tests / 3038 assertions · 198 frontend tests.

**Every MVP phase has shipped, but the MVP is not signed off.** Its own
definition (`docs/ROADMAP.md` §3) says a student "buys it with a real verified
payment", and no real money has ever moved through `StripeGateway`. That one
sandbox run is the last thing between here and MVP.

Phase 9 delivered enrollment and access: drip, prerequisites, seat limits,
the enrollment lifecycle, the studio roster, completion and retake.

The retrofit delivered database-per-tenant, the platform admin surface, plans
and subscriptions. **Read §18 before writing any query.**

**Phase 10 (Commerce) is COMPLETE against `FakeGateway`, front and back** —
the money path, the HTTP surface, and a SPA that can buy a course. But
`StripeGateway` has never contacted Stripe, so no real money has ever moved
through it. Read `docs/ROADMAP.md` Phase 10 before touching it.

Three decisions there are settled and load-bearing:

- **The academy is the merchant of record** — it connects its own gateway
  credentials, per tenant, and the platform never touches learner money. This
  supersedes `DATABASE.md` §6, which assumed a single merchant, and is why
  `instructor_earnings`/`payouts` were deliberately NOT built.
- **Platform billing stays manual** (`AssignPlan`); it is a different flow from
  course checkout and shares nothing but vocabulary.
- **Commerce is entirely tenant-side**, credentials included.

Resume by running a real Stripe sandbox payment. That is the only thing left
in this phase.

**Phase 11 (Certificates) is COMPLETE**, front and back. Completing a course
issues a verifiable certificate without blocking the request. Two things it
established that the next phase needs:

- **`tenant.path` is now the shared answer for a route with no user AND no
  Laravel signature** — the payment webhook and the public verification page.
  The academy is attacker-controllable in the path, which is safe only because
  each route carries its own unguessable credential checked against that
  academy's data. Unknown and closed academies 404 identically.
- **The queue is synchronous in tests, so a listener that writes a file writes
  a REAL one.** Faking the disk inside a test body is too late when the write
  happens in `beforeEach`. 54 stray PDFs accumulated before this was caught.

Two things the HTTP surface established that the next reader needs:

- **The webhook is the only unauthenticated write in the system.** It sits
  outside `auth:sanctum`, `tenant` AND `subscription`, and each omission is
  load-bearing — see `routes/api/commerce.php`. Its academy comes from the
  path (`tenant.webhook`) and is attacker-controllable, which is safe only
  because nothing is trusted until the signature verifies against THAT
  academy's secret. Unknown and closed academies 404 identically so the route
  cannot enumerate academy ids.
- **A basket takes the platform's BASE currency and never changes it.** A
  product with no price in that currency cannot be added — 409, not a silent
  conversion. Multi-currency is deferred, so `ProductFactory::pricedAt()`
  defaulting to USD while `orbito.currency.base` is BDT will trip up the next
  commerce test written.

**Phase 12 (Reviews / Q&A / Announcements / Wishlist / Notifications) is
COMPLETE**, front and back — the last MVP phase to land. Its exit criterion —
rating averages are columns, never `AVG()` on a card — is met by
`RefreshCourseRating` plus `ReconcileEngagementCounters`. The patterns worth
carrying forward are in §16; the retro is in `docs/ROADMAP.md`.

Three things the next reader will otherwise trip on:

- **The in-app notification channel cannot be switched off**, by design. The
  preferences table keys on channel because push arrives in P18, but today
  `NotificationChannel::Database->isLocked()` is true and the API 422s a
  request to disable it. Do not "fix" that into a silent no-op.
- **The queue is synchronous in tests, so `Notification::fake()` and asserting
  a database row are mutually exclusive.** Fake to assert channels; do not
  fake to assert the row landed in the tenant schema. Both kinds of test exist
  in `NotificationDeliveryTest`.
- **`ProductFactory` is not the only fixture that lies about defaults.**
  `AnnouncementFactory` makes a DRAFT; a test asserting a fan-out must publish
  it through `PublishAnnouncement`, because setting `published_at` directly
  fires no event.

**Phase 13 (Analytics) is COMPLETE**, front and back. ADR-08 is delivered: an
append-only log, four rollup tables, and dashboards that read the rollups and
never the log. The patterns are in §17; the retro is in `docs/ROADMAP.md`.

Three things the next reader will otherwise trip on:

- **`EventName::isClientRaisable()` is a security boundary, not a filter.**
  Adding a case to that allowlist lets a browser assert the fact. Everything
  currently outside it — payments, completions, passes, certificates — is
  established by the server precisely so it cannot be forged.
- **The rollups are keyed on UTC days and nothing converts.** A figure that
  looks a day off in Dhaka is not a bug; `range.timezone` says UTC in every
  response, and the fix would be an academy timezone, which does not exist.
- **`analytics:rollup` writes derived rows, so a tenant-blind version would
  build them into the CENTRAL database** and leave every academy's dashboards
  empty with no error. `ScheduledCommandTest` covers it; extend that file for
  any new scheduled command.

**Known debt, deliberately left:**

- Plan **limits** are stored and counted but never enforced. Phase 16.
- Analytics has **no per-student activity view** (L6) and the instructor
  series has **an endpoint with no screen**. Both additive.
- **Order-level discounts will split the revenue figures.** `discount_minor`
  is always 0 today, so per-course line totals sum exactly to the platform
  total. When coupons land (P16), the discount has to be allocated across
  items — largest remainder, so the parts sum to the whole — or the two will
  disagree by the discount and a dashboard will show it.
- A notification fan-out issues **one preference lookup per recipient** inside
  the queued job. Correct and cacheless, but a five-thousand-learner
  announcement is five thousand small queries. A batch resolver is the fix; it
  would be a memo with a lifetime, and §15 has been paid for that twice.
- The roster cannot sort by learner name — a central column against tenant
  rows. The fix is denormalising the name onto `enrollments`.
- The suite takes ~370-560s, up from ~118s, because provisioning tests build
  real schemas. Provision one academy per file rather than per test when it
  hurts.
- `ItemEditorDrawer` issues two sequential writes (lesson body, then the item's
  drip fields). Body first is deliberate — writing is the expensive thing to
  lose — but a failure between them is a partial save with no test.
- A test artifact (`storage/tenanttest/…pdf`) is committed in 998ee74 and
  6423ce9. Ignored now; dropping it needs a rebase.
