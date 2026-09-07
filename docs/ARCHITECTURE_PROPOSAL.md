# ARCHITECTURE_PROPOSAL.md — System Architecture

Companion documents: `DATABASE.md`, `API.md`, `ROLES_PERMISSIONS.md`,
`FRONTEND_ARCHITECTURE.md`, `DESIGN_SYSTEM.md`, `ROADMAP.md`.

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

| Context | Owns | Key events emitted |
|---|---|---|
| **Identity** | users, roles, permissions, sessions, profiles | `UserRegistered`, `InstructorApproved` |
| **Catalog** | courses, categories, tags, instructors-on-course | `CoursePublished`, `CourseArchived` |
| **Curriculum** | sections, course_items, lessons, resources | `CurriculumChanged`, `ItemPublished` |
| **Assessment** | quizzes, questions, attempts, assignments, submissions | `QuizAttemptSubmitted`, `QuizPassed`, `AssignmentGraded` |
| **Enrollment** | enrollments, access grants, access resolution | `CourseEnrolled`, `EnrollmentRevoked` |
| **Progress** | item_progress, course_progress, watch state | `ItemCompleted`, `CourseCompleted` |
| **Commerce** | products, cart, orders, payments, refunds, coupons, tax | `OrderPlaced`, `PaymentCaptured`, `RefundIssued` |
| **Certification** | templates, certificates, verification | `CertificateIssued` |
| **Engagement** | reviews, discussions, announcements, wishlist | `ReviewPublished`, `QuestionAnswered` |
| **Notification** | channels, preferences, delivery | — (listener-heavy) |
| **Media** | media, variants, storage accounting, signed delivery | `MediaUploaded`, `MediaProcessed` |
| **Analytics** | events, rollups, reports | — (listener-heavy) |
| **Gamification** | rules, points, badges, streaks, leaderboards | `BadgeAwarded` |
| **Live** *(P15)* | sessions, cohorts, webinars, attendance | `SessionScheduled`, `AttendanceRecorded` |
| **Content** *(P16)* | blog, pages, blocks, leads | — |
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
**Problem.** Access can come from: free enrollment, a paid order, a subscription, a
membership, a bundle, an admin grant, being the instructor, or an item being marked
preview. Tutor checks these in many places and they disagree.

**Decision.** `Enrollment\Queries\CourseAccess::for(User, Course): AccessDecision`.
Every gate — API, player, media signing, download — calls it. It returns a value object
with `granted`, `reason`, `source`, `expires_at`. Media signing and drip both consume it.

### ADR-04 — Money is integer minor units + currency
`amount_minor BIGINT` + `currency CHAR(3)`. Never float, never a bare decimal without a
currency. A `Money` value object handles arithmetic and formatting; the API returns
`{"amount_minor": 249900, "currency": "BDT", "formatted": "৳2,499.00"}` so clients never
format money themselves and locale rules stay server-side.

### ADR-05 — Payment truth is server-side and idempotent
The client never reports success. Flow:
`CreateOrder` (server prices from DB) → `InitiatePayment` (gateway) → redirect →
**webhook** → `VerifyPayment` (signature + amount + currency + order match) →
`CapturePayment` → `PaymentCaptured` → `GrantAccess`.
Webhooks are stored in `payment_events` with a unique `(gateway, external_id)` so
replays are no-ops. A reconciliation job polls pending orders as a safety net.

### ADR-06 — Quiz answers never leave the server during an attempt
The attempt API returns questions **without** `is_correct` and without explanations.
The attempt has a server-recorded `expires_at`; submissions after it are graded per the
quiz's expiry policy regardless of client clocks. Grading happens entirely in
`Assessment\Actions\GradeAttempt`.

### ADR-07 — Roles carry an optional scope
`role_assignments(user_id, role_id, scope_type NULL, scope_id NULL)`. A global admin has
`scope_type = NULL`; a TA on course 42 has `scope_type = 'course', scope_id = 42`.
Policies compute effective permissions as `global ∪ scoped-for-this-resource`.
This is how Course Manager / Reviewer / TA work without new tables per role.

### ADR-08 — Analytics is an append-only event log + rollups
`analytics_events` is written by queued listeners and never read by a dashboard.
Nightly (and hourly for today) jobs build `analytics_daily_*` rollups. Dashboards read
only rollups. This keeps the write path cheap and the read path O(rows in range).

### ADR-09 — Media is private by default
Course content lands on a private disk. Delivery is a short-lived signed URL minted only
after `CourseAccess` grants. Public assets (thumbnails, avatars) live on a public disk.
Uploads go direct to S3/R2 via presigned multipart; the API only records metadata.

### ADR-10 — Translations live in a sidecar table
`translations(translatable_type, translatable_id, locale, field, value)` with a unique
index on all four. Base-locale values stay on the parent row so the common path needs no
join; a locale-aware query left-joins once. Chosen over per-entity JSON columns because
we need to search and filter translated titles.

### ADR-11 — AI is optional, abstracted, and audited
`AiProvider` interface (`complete`, `stream`, `embed`) with Anthropic/OpenAI/local
implementations; feature-specific `AiAction`s (`GenerateCourseOutline`) that own their
prompts and output schemas; every call recorded in `ai_generations` with tokens and cost.
The app boots and every feature works with **no** provider configured.

### ADR-12 — Extension without plugins
Three seams: (1) a documented domain-event catalogue, (2) outbound webhooks subscribing to
those events, (3) capability flags per plan. No PHP plugin loader — that is the complexity
that made Tutor's codebase what it is.

---

## 5. Cross-cutting concerns

**Validation.** Form Requests only. Shared rule objects for slugs, money, locale, timezone.
The frontend's Zod schemas mirror them; the server is authoritative.

**Errors.** One exception→response mapper. Envelope in `API.md` §Errors. Domain
exceptions (`CourseNotPublishable`, `AttemptExpired`) carry a stable machine `code`.

**Idempotency.** Mutating endpoints that create money or access accept an
`Idempotency-Key` header stored in `idempotency_keys` with the response hash.

**Caching.** Redis. Cache read models (curriculum tree, course card, category tree),
never authorization decisions. Tag-based invalidation keyed by course id; every
Action that changes a course fires an event that flushes its tag.

**Queues.** `default`, `media`, `mail`, `analytics`, `certificates`, `webhooks`.
Horizon for visibility. Nothing user-facing waits on a queue except where a job status
endpoint exists (video processing, certificate generation, bulk enrollment).

**Scheduler.** drip unlock notices, live-session reminders, analytics rollups, expiry
sweeps, payment reconciliation, leaderboard snapshots, progress reconciliation.

**Observability.** Structured JSON logs with a request id; Sentry (or equivalent) for
exceptions; Laravel Pulse for slow queries and queue depth; a `/api/v1/health` endpoint.

**Testing.** Pest. Feature tests hit real routes against a transactional SQLite/MySQL.
Every endpoint gets three tests minimum: happy, forbidden, invalid. Domain logic
(grading, progress math, pricing, access resolution) gets unit tests with tables of cases.
Larastan level 6 at Phase 2, raised to 8 by Phase 10.

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
  a course page < 5 queries; SPA initial JS < 250 KB gzipped.

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
| R2 | Course versioning (edit a published course safely) | Excluded from MVP. Mitigation: `course_items` are immutable-ish rows with soft deletes, so a snapshot model can be added without reshaping progress. Revisit before Phase 10. |
| R3 | Video hosting cost/complexity (default applied, confirm before Phase 6) | MVP: self-hosted + YouTube/Vimeo. Introduce a `VideoProvider` interface in P6 so Bunny/Mux is a config change in P16. |
| ~~R4~~ | Multi-tenancy | **CLOSED (Phase 2).** **Single tenant per deployment.** Asserted in `config/orbito.php` as `multi_tenant => false`. Plan-limit counters still land in Phase 4; tenant isolation is out of scope for 1.0. |
| R5 | Regional gateways (bKash/Nagad/SSLCommerz) | **Deferred.** MVP ships **Stripe + PayPal** (decided Phase 2). Regional gateways become a post-MVP `PaymentGateway` implementation; start merchant-account procurement whenever that is scheduled. |
| R6 | Real-time (live class chat, presence) | Not in scope for 1.0. Laravel Reverb is the intended path; keep it out of the MVP. |
| R7 | Search | MySQL fulltext for MVP; Meilisearch/Scout behind a `CourseSearch` interface if catalogue growth demands it. |
