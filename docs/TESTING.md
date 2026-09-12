# TESTING.md — Test Strategy

Established in Phase 2. Every later phase adds tests in these shapes; none
introduces a new testing tool without a reason recorded here.

**Where it stands during Phase 16:** 1,164 backend tests / 3,970 assertions across
18 Feature suites and 9 Unit suites · 279 frontend tests across 52 files ·
PHPStan level 6 clean · Pint, oxlint, `tsc` and `vite build` clean.

**Playwright specs exist for phases 2 and 3 only** — two files, `auth.spec.ts`
and `shell.spec.ts`. That gap has now outlasted thirteen phases and is the
oldest untouched item in this document; see §4.

**The suite takes ~14 minutes on a quiet machine** — 13 to 15 across Phase
16's runs, 26 once when a production build ran alongside it — up from ~2
before tenancy. Provisioning tests
build real schemas, and that is the price of testing the isolation rather than
trusting it. Provision one academy per FILE rather than per test where it
hurts.

**The suite drops tenant schemas by PREFIX, and the prefix is why it has its
own.** `UsesSharedTenant::tearDownUsesSharedTenant()` runs after every test and
drops every schema matching `config('tenancy.database.prefix')` except the
shared one — that is how a test that provisions an academy cleans up after
itself without knowing its uuid. Until `TENANCY_DB_PREFIX` was overridden in
`phpunit.xml`, that prefix was shared with development, so **running the suite
silently destroyed the developer's own academies**. The symptom was not a test
failure; it was a `db:seed` in another terminal dying with `Unknown database`
partway through a tenant migration, because the suite had dropped the schema
between two statements. Separate databases were not enough — the tenant prefix
is the boundary the teardown scans, so the boundary is what had to differ.

**The suite pins its own hosts, too.** `phpunit.xml` fixes `APP_URL`,
`FRONTEND_URL`, `SESSION_DOMAIN` and `SANCTUM_STATEFUL_DOMAINS`. It used to read
all but the second from the developer's `.env`, and a developer running Orbito
on `orbito.localhost` (`docs/RUNNING.md`) would have made Sanctum stop treating
the tests' Origin as first-party — failing every session login in the suite.
Two test runs at once are still unsafe: each drops the other's tenant schemas.

---

## 1. The rule

A feature is not done without tests. Every endpoint gets **at least three**:

| Test | Asserts |
|---|---|
| happy path | 200/201 and the documented response shape |
| authorization denied | 403 (or 404 where existence must not leak) |
| validation failure | 422 with field-level `details[]` |

If a test only proves the code runs, it is not pulling its weight. Test the
decision the code makes.

---

## 1a. Suites, and what each is for

**Feature (18 suites)** — Analytics, Api, Assessment, Auth, Catalog,
Certification, Commerce, Curriculum, Engagement, Enrollment, Gamification,
Identity, Learn, Live, Media, Notification, Platform, Tenancy.

**Unit (8)** — Assessment, Catalog, Curriculum, Identity, Notification,
Platform, Progress, Support. Unit tests are for non-trivial domain logic with
no database in it: a payload DTO, a checklist, a value object. Everything that
touches a row is a Feature test, because MySQL behaviour is the thing worth
asserting.

**Three files carry more than their own feature's weight**, and a change near
any of them should run them:

| File | Defends |
|---|---|
| `Tenancy/ScheduledCommandTest` | that a scheduled command walks academies. The ordinary harness leaves a tenant open, so it passes whether or not the command knows tenancy exists; this file calls `tenancy()->end()` first. **Every new scheduled command belongs here.** |
| `Identity/CourseScopedAccessTest` | that a `.own` permission held globally does not make somebody staff on every course. Four phases have re-made that mistake. |
| `Tenancy/CentralModelConnectionTest` | that every central model is pinned. Extend `CENTRAL_TABLES` when you add one. |
| `Media/UploadPermissionTest` | who may write into each media collection, asked "held anywhere" — including a Course Manager whose only authoring role is on one course. |
| `Media/UploadVolumeTest` | that the upload quota counts only UNUSED files — handing work in frees the room, the real submit endpoint proves it — that the default fits the largest legitimate submission, that a handed-in file cannot be deleted, and that a 429 carries `Retry-After`. |
| `Media/SweepUnusedUploadsTest` | what the unused-upload sweep must NEVER delete — a handed-in file (through the real submit endpoint), a file on an assignment brief, an avatar, an author's file — and that it cannot reach past the quota's definition of unused. |
| `Webhook/WebhookTargetTest` | the SSRF guard: every internal range, IPv4-mapped IPv6, a host with ONE internal record among public ones, and the developer escape hatch that production ignores. DNS is a `FakeHostResolver`, so nothing depends on the network. |
| `Commerce/CouponCheckoutTest` | that the basket's preview and the order agree (one `CouponRules`, asked twice); the limits — last use, a use given back when a checkout is abandoned, a paid use counting for ever, per person; a free order completing with no gateway; and checkout refusing a coupon that stopped applying. |
| `Commerce/CouponRevenueTest` | that a discounted order — a bundle and a course, an awkward 1001 off — still reports per-course revenue equal to the platform total, to the minor unit. |
| `Commerce/RefundTest` | what a refund must NOT do: touch access on a partial, take access that came from elsewhere, give back more than was paid (several partials land on exactly zero per line). And that a declined refund frees its amount, a fully refunded order returns its coupon use, and revenue drops on the refund's day — never the sale's. |
| `Commerce/ProviderRefundTest` | what a provider's refund report must NOT do: count one refund twice (several events, any order, a stale `pending`), read our own refund as a stranger's before Stripe's id is stored, or quietly rewrite a refund it contradicts — those stay unprocessed, for a person. And that a full dashboard refund revokes and a partial one does not, and Stripe's refund object is read by its PaymentIntent and our metadata. |
| `Commerce/StripeCheckoutTest` | what must NOT grant on Stripe Checkout: a completed session that is not paid yet, a session paid for less than the order, an expired or failed one. And that the session carries our total and our ids, lives only as long as the coupon hold, and that refunds — ours and Stripe's — reach the PaymentIntent behind it. |
| `Commerce/RefundReportTest` | what the refund-reports screen must NOT show — a report that settled, an event about a payment we never issued — and that a resolution is recorded once (the first one stands), only by somebody who can refund. |
| `Live/CohortTest` | that a run with sessions or learners is never deleted (409 `cohort_in_use`) — deleting would cascade its sessions and their attendance — and that the list's delete button shows only where it would succeed. Plus joining: status, deadline and capacity. |
| `Live/LiveSessionTest` | that the provider list the studio builds its picker from is the check scheduling enforces, that an edit keeps a pasted link it was never shown, that the host link never reaches a response, and that joining is the attendance record. |
| `Webhook/WebhookDeliveryTest` | a real enrolment arriving as a correctly signed `enrollment.created`; the retry schedule, auto-disable, redirects refused, the send-time re-check — and that a webhook can never throw into the request that fired it. |
| `Unit/Commerce/RevenueAllocatorTest` | that a bundle's price splits across its courses to the exact minor unit, including a 200-run fuzz. The platform total and the per-course figures must stay one number. |
| `Catalog/DownloadTest` | that owned is owned — archiving, a lapsed subscription and a file-delete attempt all leave a buyer their file — and that a paid download is actually granted. |
| `Platform/PlanLimitsTest` | that an academy's own writes stop at its plan's cap and a learner's enrolment never does. |

---

## 2. Backend — Pest

```
api/tests/
├── Pest.php          bootstrap + custom expectations
├── TestCase.php
├── Feature/          hit real routes through the real middleware stack
└── Unit/             pure domain logic (grading, progress math, pricing, access)
```

**Tests run against real MySQL**, not SQLite. The database is `orbito_lms_test`,
configured in `phpunit.xml`. SQLite would hide collation, JSON column, foreign
key and index behaviour we depend on — and those are exactly the things that
break in production.

`RefreshDatabase` is composed **inside `Tests\TestCase`**, not applied in
`Pest.php`. That is deliberate and load-bearing: the class wraps
`beginDatabaseTransaction()` to open the academy first, and a trait applied to
a Pest test class lands on the *subclass*, where it silently beats an inherited
override. The alias in `TestCase` keeps the original reachable.

### Every test runs inside an academy

One tenant schema is provisioned per **process**, not per test — 36 tables
migrated once rather than ~640 times — and both connections are transacted, so
a test's writes to the academy roll back exactly as its central writes do. The
schema name is deterministic, so a crashed run leaves one predictable database
that the next run drops.

Two things to know before writing a test that touches tenancy:

- **`tenancy()->initialize()` purges the connection**, discarding its open
  transaction along with every uncommitted fixture. A test that switches
  academies must opt out with `Tests\Concerns\SwitchesTenants`, which drops
  the transaction and truncates + reseeds afterwards instead.
- **The harness hides an entire class of bug.** It leaves an academy open for
  the whole test, so a scheduled command that only works because tenancy
  happened to be initialised passes here and fails nightly in production.
  `ScheduledCommandTest` calls `tenancy()->end()` first, on purpose, and is the
  only place that condition is reproduced.

Provisioning tests build real schemas, which is why the suite went from ~118s
to ~1,150s. If that becomes painful, provision one academy per *file* rather than
per test before reaching for mocks.

### Laravel 13 moved a hook

`afterRefreshingDatabase()` now runs on **every** test and **after** the
transaction opens, so it is no longer the once-per-process hook it reads as.
`migrateDatabases()` is the one guarded by `RefreshDatabaseState::$migrated`.

### The harness models a fresh container per request

`Tests\TestCase::call()` calls `$this->app['auth']->forgetGuards()` before every
HTTP call.

This matters more than it looks. A real deployment boots a fresh container per
request, but the test process reuses one — so Illuminate's `RequestGuard` keeps
the user it resolved on an *earlier* request. Without forgetting the guards, a
test that revokes a token and then asserts the token no longer works **passes for
the wrong reason**: the guard answers from cache and never re-checks the token.

The acting-as user is re-applied after the flush, because that is deliberate test
intent rather than leaked state.

### Custom expectations

```php
expect($response)->toBeApiError('validation_failed');
```
Asserts the full error envelope *and* the stable machine code. A changed `code`
is a breaking API change even when the status is unchanged, so it is asserted
explicitly rather than inferred from the status.

### Commands

```bash
cd api
composer test        # pest
composer lint:check  # pint --test
composer analyse     # phpstan
composer check       # all three, in the order CI runs them
```

### Static analysis

Larastan at **level 6** from Phase 2. The plan said level 8 by Phase 10; it is
still 6 in Phase 16, and raising it is a deliberate un-taken decision rather
than an oversight — level 8 mostly argues with Eloquent's dynamic properties,
and the model docblocks that would satisfy it are already written by hand for
every model. Revisit if a real bug slips through the current level.
`tests/` is deliberately excluded: Pest rebinds `$this` inside test closures and
registers expectations at runtime, neither of which PHPStan can model without
hand-written stubs that would need updating on every new expectation. Test
correctness is enforced by running the suite.

---

## 3. Frontend — Vitest + Testing Library + MSW

```
web/src/
├── shared/test/
│   ├── setup.ts      jsdom polyfills, MSW lifecycle, viewport control
│   ├── handlers.ts   default happy-path API mocks + fixtures
│   ├── server.ts     MSW node server
│   ├── render.tsx    renderWithProviders + a fresh QueryClient per test
│   └── renderRoute.tsx  the same, inside a memory router — for anything that
│                        navigates, reads the URL, or renders a <Link>
└── **/*.test.tsx     colocated with the code under test
```

`matchMedia` is implemented against a settable viewport (default 1280px), not
stubbed to `false`. A stub that always answers false forces every responsive
component into its mobile branch, so a desktop-only element is simply absent and
the test fails for a reason unrelated to the code. Use `setTestViewportWidth`
to exercise the mobile layout.

jsdom lacks `document.fonts`, `visualViewport` and `Element.scrollIntoView`;
all three are polyfilled in `setup.ts` because Mantine's autosize Textarea,
floating-ui and Combobox need them. `scrollIntoView` is the nastiest of the
three: Combobox calls it from a timeout after the dropdown opens, so the
failure lands *after* the test that opened it has finished, and whichever test
happens to be running takes the blame. Test renders also pass
`env="test"` to `MantineProvider`, which disables transitions — otherwise
portalled menus are still animating when an assertion runs and failures look
like missing elements.

Component tests render through the same providers as the app —
`QueryClientProvider`, `MantineProvider`, `ModalsProvider`. Leaving one out is
how a confirm dialog silently never appears and a destructive-action test
passes for the wrong reason.

**Mocks that a mutation changes must be stateful.** A static MSW handler
returns the original fixture the moment a mutation settles and invalidates,
silently undoing every optimistic update — so the test ends up asserting the
opposite of the real behaviour. See `CurriculumBuilder.test.tsx`.

**Unhandled requests fail the test** (`onUnhandledRequest: 'error'`). A mock that
drifts from the API contract is the failure mode this whole layer exists to catch.
For the same reason, an error fixture must use the real envelope — `details` is
a list of `{field, code, message}`, and a fixture that invents a different shape
tests a response the API never sends.

**MSW resolves handlers in the order given.** To override a default with a
failure case, register the failing handler *first*: `server.use(failing,
...defaults)`.

**A state updater runs after React has released the event.** Two components
read `event.currentTarget` inside one; both crashed the whole tree the moment
somebody typed. If a test renders but the page dies on the first keystroke, the
component test that types into every field is the one that catches it.

**Fixtures mirror ADR-06 too.** `runnerFixture` has no `is_correct`,
`match_key` or accepted answers, because the runner payload has none. A fixture
that could carry them would let a test pass against a shape the API never sends.

`testTimeout` is 20s, not Vitest's 5s default. These render whole Mantine trees
and drive them through userEvent — comfortably under a second on an idle
machine, several times that when every worker is busy. Prefer a click-and-paste
helper over `userEvent.type` for long strings; typing key by key through a full
component tree dominates the runtime.

Every test gets a **fresh QueryClient** with retries off — a shared cache would
leak one test's data into the next, and retries would turn a fast error
assertion into a timeout.

Queries are asserted through the component, not by calling the hook directly:
what matters is that the user sees a loading state, then either content or a
usable error.

### What every component test covers

- loading state renders
- success state renders the real content
- error state renders, with a retry affordance and the request id
- (where relevant) the empty state

### Commands

```bash
cd web
npm run test          # vitest run
npm run test:watch
npm run test:coverage
npm run typecheck     # tsc -b --noEmit — `any` is a build failure
npm run lint          # oxlint
npm run check         # lint + typecheck + test
```

---

## 4. End-to-end — Playwright

`web/e2e/` runs against a real production build served by `vite preview`.
Two projects: `chromium` (desktop) and `mobile` (Pixel 7) — a desktop-only suite
would let mobile regressions ship, and mobile is the primary target.

**Known limitation:** current Playwright does not support Ubuntu 20.04, which is
this development host. E2E therefore runs in CI (ubuntu-latest) and on any
developer machine running Ubuntu 22.04+ / macOS. `npm run e2e` will refuse to
install browsers on 20.04; this is a host constraint, not a configuration bug.

**Only the phase 2 and 3 rows below are written** (`shell.spec.ts`,
`auth.spec.ts`). Because the specs cannot be run on this host, later phases
have been verified end to end against the running API with scripted HTTP
instead — the transcripts are in `ROADMAP.md`. The remaining rows are the
backlog, and are worth clearing on a host that can run them.

### The critical-flow suite (grows by phase)

| Phase | Flow |
|---|---|
| 2 | shell renders · navigation · light/dark toggle · unknown route |
| 3 | register → verify → login → logout · password reset ✅ |
| 4 | create a course → publish it |
| 5 | build curriculum by drag and drop → reorder persists |
| 6 | enrol → play a lesson → progress updates → resume |
| 7 | take a quiz → submit → see the result |
| 8 | submit an assignment → grade it → see feedback |
| 8 | one grading queue shows both a quiz and an assignment |
| 10 | add to cart → checkout → webhook → access granted |
| 11 | complete a course → certificate issued → verify publicly |

---

## 5. CI

`.github/workflows/ci.yml` runs three jobs on every push and PR:

| Job | Steps |
|---|---|
| `api` | Pint check → PHPStan → Pest, against real MySQL 8 and Redis 7 services |
| `web` | oxlint → prettier check → tsc → vitest → build |
| `e2e` | Playwright chromium against the built SPA |

A red build blocks the merge. There is no "fix it later" lane.

---

## 6. What we do not test

- Framework behaviour (Laravel's router, Mantine's rendering).
- Getters, constructors, and plain data mapping with no branching.
- Exact copy strings — assert roles, labels and behaviour, so a wording change
  does not turn into a failing test.

Coverage percentage is not a target. Coverage of *decisions* is.
