# TESTING.md — Test Strategy

Established in Phase 2. Every later phase adds tests in these shapes; none
introduces a new testing tool without a reason recorded here.

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

`RefreshDatabase` is applied to the whole `Feature` suite in `Pest.php`.

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

Larastan at **level 6** from Phase 2, rising to **level 8** by Phase 10.
`tests/` is deliberately excluded: Pest rebinds `$this` inside test closures and
registers expectations at runtime, neither of which PHPStan can model without
hand-written stubs that would need updating on every new expectation. Test
correctness is enforced by running the suite.

---

## 3. Frontend — Vitest + Testing Library + MSW

```
web/src/
├── shared/test/
│   ├── setup.ts      jsdom polyfills, MSW lifecycle
│   ├── handlers.ts   default happy-path API mocks
│   ├── server.ts     MSW node server
│   └── render.tsx    renderWithProviders + a fresh QueryClient per test
└── **/*.test.tsx     colocated with the code under test
```

**Unhandled requests fail the test** (`onUnhandledRequest: 'error'`). A mock that
drifts from the API contract is the failure mode this whole layer exists to catch.

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

### The critical-flow suite (grows by phase)

| Phase | Flow |
|---|---|
| 2 | shell renders · navigation · light/dark toggle · unknown route |
| 3 | register → verify → login → logout · password reset |
| 4 | create a course → publish it |
| 5 | build curriculum by drag and drop → reorder persists |
| 6 | enrol → play a lesson → progress updates → resume |
| 7 | take a quiz → submit → see the result |
| 8 | submit an assignment → grade it → see feedback |
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
