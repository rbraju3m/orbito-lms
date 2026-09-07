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
- **Union in SQL; resolve cross-boundary ids in PHP.** See §16 — the same
  instinct that merges two paginated queries in PHP also writes a `whereHas`
  across two databases.
- **`error.meta` carries what the caller can DO about a failure** — a date to
  wait for, the item that blocks this one, the courses still outstanding. A
  423 that cannot say how to get in is a dead end.

---

## 16. Multi-tenancy — read this before touching a model or a query

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

## 17. Current phase

**Phases 0–9 complete**, plus a **multi-tenancy retrofit** (T1–T6) that
reversed the single-tenant decision. 642 tests / 2204 assertions.

Phase 9 delivered enrollment and access: drip, prerequisites, seat limits,
the enrollment lifecycle, the studio roster, completion and retake.

The retrofit delivered database-per-tenant, the platform admin surface, plans
and subscriptions. **Read §16 before writing any query.**

**Next, in this order:**

1. **Phase 9's frontend** (drip UI, the students table, prerequisites picker)
   — and the SPA changes tenancy forces: a members-only catalogue, and a 402
   state for a lapsed academy.
2. **Phase 10 — Commerce.** Note the collision the retrofit created: platform
   billing (academies paying us, already half-built in `Platform`) is a
   different thing from course sales (learners paying an academy). Do not let
   them share tables.

**Known debt, deliberately left:**

- Plan **limits** are stored and counted but never enforced. Phase 16.
- The roster cannot sort by learner name — a central column against tenant
  rows. The fix is denormalising the name onto `enrollments`.
- The suite takes ~430s, up from ~118s, because provisioning tests build real
  schemas. Provision one academy per file rather than per test when it hurts.
