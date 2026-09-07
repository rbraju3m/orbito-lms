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
`Media`, `Live`, `Content`, `Notification`, `AI`.

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
- Components import from `shared/ui`, not `@mantine/core`, wherever a wrapper exists.
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
**Laravel 13** · **single tenant per deployment** · **Stripe + PayPal for MVP** ·
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

## 15. Current phase

**Phases 0–8 complete.** Audit, architecture, foundation, identity, catalog,
curriculum, learning, assessment, assignments.

**Phase 9 is next: enrollment and access** — manual and bulk enrollment,
expiry, suspension and revocation, drip (date / days / sequential),
prerequisites, seat limits, course completion in both modes, reset and retake.
`CourseAccess` (ADR-03) is the one place a new access source is added; adding a
second enrollment check anywhere is the failure this phase must avoid. See
`docs/ROADMAP.md`.
