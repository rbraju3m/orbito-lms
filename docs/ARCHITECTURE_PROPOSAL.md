# ARCHITECTURE_PROPOSAL.md — System Architecture

Companion documents: `DATABASE.md`, `API.md`, `ROLES_PERMISSIONS.md`,
`FRONTEND_ARCHITECTURE.md`, `DESIGN_SYSTEM.md`, `ROADMAP.md`.

> **The name is now mostly wrong.** Phases 0–15 are built, plus a
> multi-tenancy retrofit, so this is a record rather than a proposal. Each ADR below carries its delivery status;
> where the shipped code differs from the original decision, the difference is
> stated rather than quietly edited away.

---

## 1. Shape of the system

```
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  Web SPA     │  │  Mobile app  │  │  3rd party   │
│ React+Mantine│  │ (RN/Flutter) │  │  integrator  │
└──────┬───────┘  └──────┬───────┘  └──────┬───────┘
       │  same versioned JSON API, no HTML  │
       └──────────────┬─────────────────────┘
                      ▼
        ┌───────────────────────────────┐
        │  Laravel API  /api/v1         │
        │  Controllers → Actions        │
        │  Policies · Requests · Resources│
        └───┬───────────────┬───────────┘
            │ events        │
    ┌───────▼──────┐  ┌─────▼──────────┐
    │ Redis queues │  │  MySQL 8       │
    │ Horizon      │  │  (domain DB)   │
    └───────┬──────┘  └────────────────┘
            │
   listeners: progress rollups · gamification · notifications ·
   analytics ingest · certificate PDF · media processing · webhooks
```

**Modular monolith, not microservices.** One deployable, hard internal boundaries.
Domains talk through events, not through each other's models. This keeps a small team
fast now and leaves clean seams if anything ever needs extracting.

---

## 2. Bounded contexts

Events in **bold** exist today; the rest arrive with their phase.

| Context | Owns | Key events emitted |
|---|---|---|
| **Identity** | users, roles, permissions, sessions, profiles | **`UserRegistered`**, **`UserLoggedIn`**, **`InstructorApplied`**, **`InstructorReviewed`**, **`RoleAssigned`**, **`RoleRevoked`** |
| **Catalog** | courses, categories, tags, instructors-on-course | **`CourseCreated`**, **`CourseStatusChanged`**, **`CourseDeleted`** |
| **Curriculum** | sections, course_items, lessons, resources | **`CurriculumChanged`** |
| **Assessment** | quizzes, questions, attempts, assignments, submissions | **`QuizAttemptSubmitted`**, **`QuizAttemptGraded`**, **`AssignmentSubmitted`**, **`AssignmentGraded`**, **`AssignmentReturned`** |
| **Enrollment** | enrollments, access grants, access resolution | **`CourseEnrolled`**, `EnrollmentRevoked` |
| **Progress** | item_progress, course_progress, watch state | **`ItemCompleted`**, **`CourseCompleted`** |
| **Commerce** | products, cart, orders, payments, gateway accounts | **`PaymentCaptured`**, **`RefundIssued`** — `OrderPlaced` named and not built |
| **Certification** | templates, certificates, verification | **`CertificateIssued`** — `CertificateRevoked` not built; nothing reads it yet |
| **Engagement** | reviews, discussions, announcements, wishlist | **`ReviewChanged`**, **`DiscussionReplied`**, **`QuestionAsked`**, **`AnnouncementPublished`**, **`ReviewPublished`**, **`AnswerAccepted`** |
| **Notification** | inbox, preferences, queued mail | — (listener-heavy; six listeners, no events of its own) |
| **Media** | media, variants, storage accounting, signed delivery | **`MediaUploaded`**, **`MediaDeleted`**, `MediaProcessed` |
| **Analytics** | events, rollups, reports | — (listener-heavy; five listeners, no events of its own) |
| **Gamification** | rules, points, badges, streaks, leaderboards | **`PointsAwarded`**, **`StreakExtended`**, **`BadgeAwarded`** |
| **Live** | sessions, cohorts, webinars, attendance | **`SessionScheduled`**, **`AttendanceRecorded`** |
| **Webhook** | endpoints, deliveries, signing, the SSRF guard | — (listener-only: `SendWebhooks` on 16 events, the whole of ADR-12; P16) |
| **Content** *(P16)* | blog, pages, blocks, leads | — |

A seventeenth directory, **Platform**, was added in Phase 4 and is not a bounded
context in the same sense: it holds tenants, plans, subscriptions and the
plan-limit usage counters that every other context increments. Every context
has a directory under `app/Domain/`; the ones whose phase has not arrived are
empty placeholders, which is deliberate — the shape of the system is visible
before it is filled in.

**Filled in as of Phase 15:** Identity, Catalog, Curriculum, Assessment,
Enrollment, Progress, Media, Platform, Commerce, Certification, Engagement,
Notification, Analytics, Gamification, Live. **Still empty:** Content (P16),
AI (P17).

**Bold events are built.** The unbolded ones are named here so the phase that
adds them uses this vocabulary rather than inventing a second one for the same
fact — see `EVENTS.md` §4, which also records which named events were
deliberately never built and why.
| **AI** *(P17)* | providers, actions, generation audit | `AiGenerationCompleted` |

### Dependency rule
A context may **read** another context's public read-model (a query service or a
read-only Resource), and may **listen** to its events. It may not write another
context's tables, call another context's Actions directly, or import another context's
Eloquent model into its own Action. Cross-context writes go through events.

Example — the single most important one:

```
Progress::CompleteItem
   └─ fires ItemCompleted
        ├─ Progress\RecalculateCourseProgress   (same context, sync)
        ├─ Gamification\AwardPointsForItem      (queued)
        ├─ Analytics\RecordEvent                (queued)
        ├─ Notification\MaybeNotifyInstructor   (queued)
        └─ Progress\MaybeCompleteCourse → CourseCompleted
                                            ├─ Certification\IssueCertificate (queued)
                                            └─ Gamification\AwardCourseBadge  (queued)
```

Progress knows nothing about certificates.

---

## 3. Backend layering

```
Route  →  Controller  →  Form Request (validate)
                      →  Policy (authorize)
                      →  Action (business logic, transactional)
                      →  Domain Event
                      →  API Resource (shape)
```

**Controller** — HTTP only. ~20 lines. No queries, no conditionals about business rules.
**Form Request** — every input validated and typed. `authorize()` delegates to the Policy.
**Action** — a single class with one public method. Owns the transaction boundary.
Composes repositories/models. Returns a DTO or a model, never a response.
**Policy** — the only place authorization decisions live. See `ROLES_PERMISSIONS.md`.
**Resource** — the only place response shape is decided. Never leaks a model's `toArray()`.

### Directory layout
```
app/
├── Domain/
│   └── Curriculum/
│       ├── Models/{Section,CourseItem,Lesson,Resource}.php
│       ├── Actions/{CreateSection,ReorderCurriculum,UpsertLesson,DuplicateItem}.php
│       ├── Data/{CurriculumTreeData,ReorderOperationData}.php
│       ├── Events/{CurriculumChanged}.php
│       ├── Policies/{SectionPolicy,CourseItemPolicy}.php
│       ├── Enums/{ItemType,DripMode}.php
│       ├── Queries/{CurriculumTreeQuery}.php      # read model
│       └── Support/
├── Http/
│   ├── Controllers/Api/V1/Curriculum/…
│   ├── Requests/Curriculum/…
│   ├── Resources/Curriculum/…
│   └── Middleware/
├── Support/            # framework-level cross-cutting only
└── Providers/
```

---

## 4. Key architectural decisions

### ADR-01 — Curriculum is a single ordered spine, not a post tree
**Status: delivered (P5).** FIVE item types now hang off it — `lesson`,
`resource`, `quiz` (P7), `assignment` (P8), `live_session` (P15) — and none of
them added a second ordering, progress or drip mechanism. Adding the fifth was
one enum case, one `makeItemable` branch and a morph-map entry.
**Problem.** Tutor stores lessons, quizzes and assignments as separate post types under
`post_parent`, ordered by `menu_order`. Every "what is item #7", "what's next", and
"how many items does this course have" is a heterogeneous multi-table query.

**Decision.** One `course_items` table is the ordered spine of a course. It carries
`course_id`, `section_id`, `position`, `type`, `title`, `is_preview`, drip fields, and a
polymorphic pointer (`itemable_type`, `itemable_id`) to the type-specific row
(`lessons`, `quizzes`, `assignments`, `resources`, later `live_sessions`).

**Consequences.**
- Reordering = updating `position` in one table.
- Progress has exactly one FK target (`course_item_id`).
- Prev/next is one indexed query.
- Adding a new item type in P15/P16 requires no change to progress, drip, or ordering.
- Cost: one extra join to reach type-specific fields. Acceptable and cacheable.

### ADR-02 — Progress is stored, not computed
**Status: delivered (P6).** `progress:reconcile` runs nightly at 03:10 and
reports drift as a bug alert.
**Problem.** Tutor recomputes course percentage on every read, and stores one usermeta row
per completed lesson.

**Decision.** `item_progress` (one row per enrollment × item, created lazily) plus
`course_progress` (one row per enrollment) holding `completed_items`, `total_items`,
`percent`, `last_item_id`, `last_activity_at`, `completed_at`. `course_progress` is
updated by a listener on `ItemCompleted` and by a `CurriculumChanged` recount job.

**Consequences.** "Continue learning" and "My courses" become single indexed queries.
Recount on curriculum change is a queued job. Percentages can drift only if a job fails —
so a nightly reconciliation command is part of P6.

### ADR-03 — Access is resolved by one service
**Status: delivered (P6).** The signature settled as
`CourseAccess::for(?User, Course)` plus `forItem(?User, CourseItem)` and
`enrollmentFor(User, Course)` — the user is nullable because free preview
content is reachable anonymously. Quiz start, assignment submission, media
signing and every download route call it. Adding an access source in P9/P10
means editing that one class.
**Problem.** Access can come from: free enrollment, a paid order, a subscription, a
membership, a bundle, an admin grant, being the instructor, or an item being marked
preview. Tutor checks these in many places and they disagree.

**Decision.** `Enrollment\Queries\CourseAccess` answers it. Every gate — API,
player, media signing, download — calls it. It returns an `AccessDecision` value
object with `granted`, `reason`, `source`, `enrollment`, `expiresAt`.

A refusal the caller could legitimately fix answers **423 Locked**, not 403,
and carries the reason: 403 means "you did something wrong", 423 means "here is
how to get in".

### ADR-04 — Money is integer minor units + currency
**Status: delivered (P10).** Held everywhere since, including in analytics —
the revenue rollups sum minor units and the CSV export ships them raw with the
currency code beside them, because a spreadsheet dividing by 100 is the
reader's decision and a float here would already have lost.
`amount_minor BIGINT` + `currency CHAR(3)`. Never float, never a bare decimal without a
currency. A `Money` value object handles arithmetic and formatting; the API returns
`{"amount_minor": 249900, "currency": "BDT", "formatted": "৳2,499.00"}` so clients never
format money themselves and locale rules stay server-side.

### ADR-05 — Payment truth is server-side and idempotent
**Status: delivered (P10) against `FakeGateway`; UNPROVEN against a real
gateway.** The shape is built and tested — including the absence of any
`/confirm` endpoint, which `CheckoutApiTest` asserts by 404 — but
`StripeGateway` has never contacted Stripe. The webhook is the only
unauthenticated write in the system and each middleware it omits is
load-bearing; see `routes/api/commerce.php`.
The client never reports success. Flow:
`CreateOrder` (server prices from DB) → `InitiatePayment` (gateway) → redirect →
**webhook** → `VerifyPayment` (signature + amount + currency + order match) →
`CapturePayment` → `PaymentCaptured` → `GrantAccess`.
Webhooks are stored in `payment_events` with a unique `(gateway, external_id)` so
replays are no-ops. A reconciliation job polls pending orders as a safety net.

### ADR-06 — Quiz answers never leave the server during an attempt
**Status: delivered (P7).** Enforced by having **two resources**, not one with
conditional fields: `QuestionResource` (authoring, carries the answers) and
`AttemptQuestionResource` (the learner, cannot express them). One resource with
`when()` guards is one mistake away from leaking.

The attempt has a server-recorded `expires_at`; every save and the submit
re-read it, so submissions after it are handled per the quiz's expiry policy
regardless of client clocks. Grading happens entirely in
`Assessment\Grading\QuestionGrader`, called from `SubmitQuizAttempt`;
`GradeAnswerManually` finalises through the same path so downstream listeners
see one consistent event.

Matching serves its targets shuffled and detached from the option they belong
to, fill-in-the-blank serves a count rather than the blanks, and ordering
serves options with no `position`. Nine tests assert each absence.

### ADR-07 — Roles carry an optional scope
**Status: delivered (P3).**
`role_assignments(user_id, role_id, scope_type NULL, scope_id NULL)`. A global admin has
`scope_type = NULL`; a TA on course 42 has `scope_type = 'course', scope_id = 42`.
Policies compute effective permissions as `global ∪ scoped-for-this-resource`.
This is how Course Manager / Reviewer / TA work without new tables per role.

**The correction that cost a security bug.** `hasPermission($key, $scope)`
answers the *broad* question and returns global ∪ scoped, so it must never be
used to ask "does this person hold a seat on THIS resource?" — that made every
instructor staff on every course. `hasScopedPermission()` /
`hasAnyScopedPermission()` ignore global roles and answer the narrow question.
The regression tests are in `CourseScopedAccessTest`.

### ADR-08 — Analytics is an append-only event log + rollups
**Status: delivered (P13), with two documented exceptions.**
`analytics_events` is written by queued listeners and never read by a dashboard.
`analytics:rollup` builds `analytics_daily_*` nightly over yesterday and today, and
hourly over today alone. Dashboards read only rollups. This keeps the write path
cheap and the read path O(rows in range).

Two things read something other than the log, and both are deliberate:

- **Money comes from the orders ledger.** An order is not a course, so
  splitting a payment across courses inside an event would give the platform
  total and the per-course totals two definitions free to disagree. The ledger
  is the one place money is already correct.
- **The item funnel reads `item_progress`.** A funnel asks about the present
  state of every learner rather than about a day, and that table already holds
  exactly it, one row per learner per item.

Partitioning the log by month was part of the original plan and was not built —
see `docs/DATABASE.md` §10. Retention is `analytics:prune`, weekly, 400 days.

### ADR-09 — Media is private by default
**Status: partly delivered (P4).** The private-by-default disk, the
`MediaCollection` rules (disk, MIME allowlist, size cap) and short-lived signed
URLs minted only after `CourseAccess` grants are all in place.

**Direct-to-S3 presigned upload is not.** Uploads currently stream through the
API, which derives the real MIME from the bytes rather than trusting the
client. That is the safer default and fine at this scale; the presigned path is
still the intended answer for large video, and is a change to
`StoreUploadedMedia` plus one new endpoint, not a reshaping of the model.
Course content lands on a private disk. Delivery is a short-lived signed URL minted only
after `CourseAccess` grants. Public assets (thumbnails, avatars) live on a public disk.
Uploads go direct to S3/R2 via presigned multipart; the API only records metadata.

### ADR-10 — Translations live in a sidecar table
**Status: not built (P18).**
`translations(translatable_type, translatable_id, locale, field, value)` with a unique
index on all four. Base-locale values stay on the parent row so the common path needs no
join; a locale-aware query left-joins once. Chosen over per-entity JSON columns because
we need to search and filter translated titles.

### ADR-11 — AI is optional, abstracted, and audited
**Status: not built (P17).**
`AiProvider` interface (`complete`, `stream`, `embed`) with Anthropic/OpenAI/local
implementations; feature-specific `AiAction`s (`GenerateCourseOutline`) that own their
prompts and output schemas; every call recorded in `ai_generations` with tokens and cost.
The app boots and every feature works with **no** provider configured.

### ADR-12 — Extension without plugins
**Status: partly delivered.** The domain-event catalogue is real and load-
bearing — **50 events across 13 contexts**, with Analytics, Gamification,
Notification, Certification and Live all built entirely as listeners on
events their source contexts know nothing about. Progress does not know
certificates exist; it fires `CourseCompleted` and four contexts react.

That is the seam working: five phases were added without reopening a single
Action that should have fired.

**Outbound webhooks are built** (P16): fifteen topics, each one event from this
catalogue under a public name — see `WEBHOOKS.md`. A webhook is one more
listener, which is the whole reason extension does not need a plugin loader.
**Plan capability flags are still not built.**
Three seams: (1) a documented domain-event catalogue, (2) outbound webhooks subscribing to
those events, (3) capability flags per plan. No PHP plugin loader — that is the complexity
that made Tutor's codebase what it is.

---

---

### ADR-13 — One database per academy

**Decision.** Multi-tenancy is **schema-per-tenant** (`stancl/tenancy`), and
the tenant for a request is resolved from the **authenticated user's**
`tenant_id`. Users, plans, subscriptions and usage counters are central;
everything else — including roles and role assignments — lives in the
academy's own schema.

**Why not a `tenant_id` column.** Row-level tenancy makes isolation a property
of every query: one missing scope leaks another academy's data, and the only
defence is discipline plus review. Schema-per-tenant makes isolation
**structural** — the data is not on the connection, so a forgotten filter
returns nothing rather than someone else's rows. That trade buys correctness
at the cost of migrations running N times and cross-boundary joins being
impossible.

**Why identification by user, not by domain.** This follows the Orbito
product, and it is the decision with the widest blast radius, because it
removes the anonymous surface from every route that resolves its academy that
way: with no user there is no academy, so the catalogue, course pages,
previews and the player all became members-only. `is_preview` now means "try
before you *enrol*", not "try before you sign up".

**P16 added the one exception, and by PATH rather than by subdomain.** The
academy's public site (`/api/v1/public/{academy}/…`, `tenant.public`) takes
the academy's slug from the URL — the slug is already public, being in the
registration link an academy hands out — and serves only published,
deliberately-public data. That is what makes a path acceptable where a
subdomain was assumed necessary: the URL segment is not a credential and
nothing behind it is private, so guessing an academy grants exactly what
visiting its site grants. Per-academy DOMAINS are still a real change, and
still the right answer when academies want their own addresses; they are not
a prerequisite for a storefront.

**What it costs, concretely.**

- No FK can span schemas. `user_id` in an academy's tables is an unenforced
  reference, and the cascade that used to clean up after a deleted account is
  now `PurgeUserFromTenant`.
- `whereHas` and `orderBy(subquery)` cannot cross the boundary; ids are
  resolved on one side and passed to the other.
- Anything scheduled runs centrally and must walk the academies.
- A route with no authenticated user cannot resolve one, so the signed media
  download carries the tenant inside its signed payload. Phase 10 webhooks
  need the same.
- **Registration is the route this hurt most, and it took longest to notice.**
  A signup has no user, so it had no academy: it wrote a central row with a
  null `tenant_id` and put the Student role into whichever schema happened to
  be open. It is now TOLD — the academy's slug travels in the signup link the
  academy hands out. That is a third answer to "how does a route with no user
  find its academy", alongside the signed payload and the path segment, and it
  is the one that applies when the caller is a person rather than a machine.
- **Login and register also read tenant tables before the middleware could
  help**, because their payload is roles and permissions. `AuthenticatedAcademy`
  opens the academy first and loads the relations second; the reverse order is
  a 500 naming whichever tenant table it reached first.
- For a platform operator, `tenant_id` means "which academy have they
  ENTERED", not "where do they belong". They may be inside none, and every
  route behind the tenant middleware then answers 409 rather than 500 — except
  `/auth/me`, which is how the client discovers the state at all.

**Consequence for Phase 10.** Platform billing (academies paying us) and
course sales (learners paying an academy) are now two different systems on
two different connections. They must not share tables.

## 5. Cross-cutting concerns

Marked **✅ in place** or **planned** as of Phase 8.

**Validation.** ✅ Form Requests only. Shared rule objects for slugs, money, locale, timezone.
The frontend's Zod schemas mirror them; the server is authoritative.

**Errors.** ✅ One exception→response mapper. Envelope in `API.md` §Errors. Domain
exceptions extend `Support\Exceptions\DomainException` and carry a stable machine
`code` and status: `attempt_rejected` (409), `submission_rejected` (409),
`progress_rejected` (409), `content_locked` (423), `validation_failed` (422),
`platform_owner_protected` (422), `registration_not_open` (403),
`no_academy_selected` (409). Switch on the code, never the message.

**Idempotency.** Planned, with P10. Nothing today creates money. Where it
matters now it is achieved structurally instead: starting a quiz attempt
resumes an open one rather than creating a second, and the reorder endpoint
takes the whole tree so replaying it is a no-op.

**Caching.** Planned. Redis is wired (predis; the PHP extension is absent on
this host) and backs the cache store, sessions and queues, but **no read model
is cached yet** — the aggregates that would have needed it are
stored and event-maintained instead (ADR-02), which removed the reason. When it
does arrive: cache read models, never authorization decisions, and invalidate
by a course-id tag. `HasRoles` keeps a per-request memo of resolved permissions,
which is a memo and not a cache — it dies with the request.

**Queues.** Horizon is installed; the named queues (`media`, `mail`,
`analytics`, `certificates`, `webhooks`) arrive with the phases that need them —
everything currently runs on `default`. The rule holds either way: nothing
user-facing waits on a queue except where a job status endpoint exists.

**Scheduler.** ✅ Three commands today, none load-bearing for correctness —
expiry and progress are evaluated live on every request, so a missed run costs
tidiness, not truth:

| Command | When | Why |
|---|---|---|
| `quiz:sweep-expired` | every 5 min | resolve attempts nobody came back to |
| `progress:reconcile` | 03:10 daily | drift in a stored aggregate is a bug alert |
| `usage:reconcile` | 03:30 daily | same, for the plan-limit counters |

Drip notices, live-session reminders, analytics rollups, payment reconciliation
and leaderboard snapshots join them with their phases.

**Observability.** Partly. Structured logs carry a request id, and it is echoed
in every error envelope so a user can quote it. `/api/v1/health` reports
database, cache and queue. Sentry and Pulse are planned (P19).

**Testing.** ✅ Pest against MySQL, 642 backend tests. Every endpoint
gets three minimum: happy, forbidden, invalid. Domain logic (grading, progress
math, submission rules, access resolution) gets unit tests with tables of cases.
Larastan is at level 6; raising it to 8 is still the intention by Phase 10.
See `TESTING.md`.

---

## 6. Security architecture

| Threat | Control |
|---|---|
| Broken authz / IDOR | Policy on every route; tests assert the 403 path; no model binding without a policy |
| Privilege escalation | Permission registry; roles assigned only by `assignRole` permission holders; scope respected |
| Price tampering | Totals recomputed server-side from `products`; the client sends ids and quantities only |
| Fake payment success | Webhook + signature + amount/currency match; access granted only on `PaymentCaptured` |
| Quiz cheating | Correct answers never serialized to an in-progress attempt; server-side deadline; attempt IP/UA recorded with a retention policy |
| Content leeching | Private disk + short-lived signed URLs bound to the user; no permanent public media URL for paid content |
| Malicious upload | Extension + MIME sniff + size caps; stored outside webroot with a generated name; never served from an executable path |
| XSS | API returns data, not HTML; rich text sanitised server-side on write with an allowlist |
| CSRF | Sanctum cookie flow for the SPA (SameSite=Lax); bearer tokens for mobile carry no ambient authority |
| Brute force | Throttle login/register/reset per IP+identifier; exponential backoff; generic failure messages |
| Enumeration | Identical responses for "unknown email" and "email exists" on reset/registration probes |
| Mass assignment | DTOs from Form Requests; no `$request->all()` into a model |
| Secret leakage | No tokens/PII in logs; gateway payloads stored in a dedicated table, redacted |
| Data protection | Encrypted at rest for withdrawal/billing details; GDPR export + erase jobs |

---

## 7. Performance architecture

- **Indexes designed with the schema**, not added after a slow query (see `DATABASE.md`).
- **N+1**: `Model::preventLazyLoading()` in non-production; every Resource declares its
  `with()` in the Action or the Query object.
- **Pagination**: offset for admin tables (needs counts), **cursor** for feeds, activity,
  discussions, and analytics.
- **Read models**: `CurriculumTreeQuery`, `CourseCardQuery`, `LearnerDashboardQuery` —
  single purpose-built queries, cached, not composed from many relations.
- **Denormalised counters**: `courses.rating_avg`, `rating_count`, `enrollment_count`,
  `item_count`, `total_duration_seconds` — maintained by listeners, reconciled nightly.
- **Payload discipline**: list resources are thin; detail resources are separate.
  Never return the curriculum inside a course list item.
- **Frontend**: TanStack Query dedupes and caches; route-level code splitting; images
  served in modern formats at request-time sizes; CDN in front of public media.
- **Budgets** (enforced from Phase 2): p95 API < 200 ms for reads, < 500 ms for writes;
  a course page < 5 queries; SPA initial JS < 250 KB gzipped *(raised to 255 KB in
  Phase 16 — the shell had 0.04 KB of room left; see ROADMAP §Phase 16)*.

---

## 8. Deployment shape

Nginx → PHP-FPM (API) · Vite-built static SPA on a CDN · MySQL 8 (primary, read replica
later) · Redis (cache + queue + session) · Horizon workers · S3/R2 for media ·
scheduler on cron. Staging mirrors production. Migrations run before the new release
serves traffic; all migrations must be backward-compatible for one release.

---

## 9. Open risks and decisions needed

| # | Risk / decision | Recommendation |
|---|---|---|
| ~~R1~~ | Laravel version | **CLOSED (Phase 2).** Building on **Laravel 13.30.1**, PHP 8.4. |
| R2 | Course versioning (edit a published course safely) | Excluded from MVP. Mitigation held: `course_items` are soft-deleted rows, so a snapshot model can be added without reshaping progress. Sharper now that assessment is built — editing a live quiz cannot change a score in flight, because an attempt freezes its question order and point total at start. Revisit before Phase 16, which is where course versioning would first hurt: bundles and subscriptions both assume a course is a stable thing to sell. |
| R3 | Video hosting cost/complexity | **Default applied (P6), and the mitigation came out weaker than planned.** Self-hosted upload + YouTube/Vimeo ship today, but `VideoProvider` landed as an **enum**, not an interface: the player branches on it to build an embed URL. Adding Bunny/Mux therefore means a new case plus a URL builder, not a config change. Cheap to fix, and now overdue: P16 is next. |
| ~~R4~~ | Multi-tenancy | **REOPENED AND CLOSED THE OTHER WAY (post-Phase 9).** **One database per academy**, via `stancl/tenancy`, matching the Orbito product. This risk warned a tenant key "cannot be added cheaply after P4" — true of a `tenant_id` COLUMN, which would have meant 44 tables, global scopes and a leak audit of every query. Schema-per-tenant cost none of that: the domain migrations moved wholesale to `migrations/tenant/`, and the models, Actions and Policies were untouched. **The estimate was wrong because it assumed the wrong mechanism.** See ADR-13. |
| R5 | Regional gateways (bKash/Nagad/SSLCommerz) | **Deferred.** MVP ships **Stripe + PayPal** (decided Phase 2). Regional gateways become a post-MVP `PaymentGateway` implementation; start merchant-account procurement whenever that is scheduled. |
| R6 | Real-time (live class chat, presence) | Not in scope for 1.0, and P15 shipped live learning WITHOUT it — deliberately. The video provider owns the room; Orbito owns the schedule, roster and attendance. The one place a websocket was tempting was the leaderboard and the notification badge, and both are polled instead: one indexed count a minute is cheaper than a connection per signed-in tab. Laravel Reverb remains the path if a real-time feature ever justifies it. |
| R7 | Search | MySQL fulltext for MVP; Meilisearch/Scout behind a `CourseSearch` interface if catalogue growth demands it. |
