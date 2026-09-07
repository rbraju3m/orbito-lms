# ROADMAP.md — Phases, Dependency Graph, MVP Scope

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

### Phase 3 — Authentication & Users
Register, login, logout, verification, reset · Sanctum cookie + token · permission
registry + seeder + `permissions:sync` · roles including course-scoped · policies base ·
profiles · instructor application and approval · `GET /auth/me` with permissions ·
auth UI, guards, and the account area.
**Exit:** all four role families can log in and see only what they may.

### Phase 4 — Course Management
Categories, tags · course CRUD · draft→review→published→archived state machine ·
co-instructors · settings and details tables · media foundation (upload URL, private
disk, signed URLs) · catalogue endpoints with filters · Studio course list + basics tab +
creation wizard step 1 · **usage counters**.
**Exit:** an instructor can create and publish a course with no curriculum.

### Phase 5 — Curriculum Builder
Sections · `course_items` · lessons · the reorder endpoint · duplicate · preview flags ·
validation rules for publish · the builder UI with dnd-kit, inline edit, autosave,
collapse, and the publish checklist.
**Exit:** an instructor builds a 3-section, 10-item course entirely by drag and drop, and
the publish checklist blocks a broken course.

### Phase 6 — Learning Experience
`enrollments` (free path only) · `item_progress` + `course_progress` · the player shell ·
video with resume · text/PDF items · notes · resources · prev/next · mark complete ·
"continue learning" · the progress reconciliation command.
**Exit:** a student completes a course end to end and the percentage is correct and cheap.

### Phase 7 — Quiz Engine
Quizzes, questions (10 types), options, question banks, quiz→question pivot · attempt
lifecycle with a server deadline · autosave answers · auto-grading · manual grading queue ·
feedback modes · results · the quiz builder and the quiz runner.
**Exit:** every question type is authored, taken, graded, and reviewed; expiry is
enforced regardless of the client clock.

### Phase 8 — Assignments
Assignment authoring · submission with files and text · late policy · grading and
feedback · re-submission · the grading queue shared with quizzes.
**Exit:** submit → grade → feedback → resubmit works, with file validation enforced.

### Phase 9 — Enrollment & Access
`CourseAccess` service · manual and bulk enrollment · expiry, suspension, revocation ·
drip (date / days / sequential) · prerequisites · seat limits · course completion in both
modes · reset and retake.
**Exit:** one service answers every access question; drip and expiry are enforced server-side.

### Phase 10 — Commerce
Products + prices + currencies · cart · checkout with server-side repricing · orders ·
`PaymentGateway` interface with Stripe and PayPal · webhooks (verified, idempotent) ·
refunds · coupons · tax · invoices · instructor earnings and payouts · access granted only
on `PaymentCaptured`. Regional gateways (SSLCommerz/bKash/Nagad) land here if merchant
accounts are ready.
**Exit:** a paid enrollment completes through a real sandbox webhook, and a forged
client-side "success" grants nothing.

### Phase 11 — Certificates
Templates · queued PDF generation · numbering · QR · public verification page · revocation.
**Exit:** completing a course issues a verifiable certificate without blocking the request.

### Phase 12 — Reviews / Discussion / Notifications
Reviews with moderation and aggregate maintenance · threaded Q&A with resolve ·
announcements · wishlist · in-app + email notifications with preferences.
**Exit:** rating averages are columns, not `AVG()` on every card. **MVP complete.**

### Phase 13 — Analytics
Event ingestion · rollup jobs · admin/instructor/course dashboards · per-item funnel
(the Klasio-inspired stall heatmap) · CSV export.

### Phase 14 — Gamification
Rule engine on the event stream · points · badges · achievements · streaks · leaderboards.

### Phase 15 — Live Learning
Cohorts · `LiveSessionProvider` with Zoom and Google Meet · sessions as curriculum items ·
attendance · webinars with registration · reminders · calendar.

### Phase 16 — Advanced Business
Subscriptions and memberships · bundles · digital downloads · coaching/booking · blog ·
page builder (blocks) · multilingual content · RTL · plan limits and billing · webhooks out.

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
