# MASTER_PLAN.md — Orbito LMS

The single entry point. Everything else hangs off this document.

---

## 1. What we are building

A production-grade Learning Management System that combines the **functional depth** of a
mature LMS with the **product coherence and business capability** of a modern SaaS
platform, on an architecture that is API-first, testable, and ready for mobile.

Not a Tutor LMS clone. Not a Klasio clone. Our own product, with better architecture,
UX, performance, extensibility and developer experience.

## 2. Stack

| Layer | Choice |
|---|---|
| Backend | **Laravel 13**, PHP 8.3+ (host has 8.4.7), API-only |
| Data | MySQL 8, Redis (cache · queue · session) |
| Async | Laravel Queue + Horizon, Laravel Scheduler |
| Frontend | React + TypeScript + Vite |
| UI | **Mantine v9** — the single component library |
| Server state | **TanStack Query v5** — the only owner of server state |
| Client state | Zustand, four small stores |
| Forms | React Hook Form + Zod |
| DnD | dnd-kit |
| Testing | Pest · Larastan · Vitest · Testing Library · MSW · Playwright |
| Storage | Local / S3 / Cloudflare R2 behind one abstraction |

## 3. The ten decisions that define this system

1. **Modular monolith with hard domain boundaries.** Contexts talk through events, never
   through each other's models.
2. **The curriculum is one ordered `course_items` spine**, not a tree of post types.
3. **Progress is stored and event-maintained**, never recomputed per request.
4. **One `CourseAccess` service** answers every "may they consume this?" question.
5. **Money is integer minor units + a currency code**, multi-currency from the schema up.
6. **Payment truth is the webhook**, verified and idempotent; the client is never believed.
7. **Roles carry an optional scope**, which is how TA / reviewer / course manager work.
8. **Analytics is an append-only event log plus rollups**; dashboards never read raw events.
9. **Media is private by default**, delivered by short-lived signed URLs gated on access.
10. **AI is optional and abstracted.** The product is complete with no provider configured.

## 4. Document map

| Document | Answers |
|---|---|
| `MASTER_PLAN.md` | What are we building and why, in one page |
| `PRODUCT_VISION.md` | Who it's for, what "good" means, what we refuse to build |
| `TUTOR_AUDIT.md` | What exists in Tutor LMS, how it works, what to keep and avoid |
| `KLASIO_REFERENCE.md` | Product/UX/business ideas and what the packaging implies |
| `FEATURE_MATRIX.md` | Every feature, its source, its phase, in or out of MVP |
| `ARCHITECTURE_PROPOSAL.md` | System shape, bounded contexts, ADRs, security, performance, risks |
| `DATABASE.md` | Proposed schema, indexes, growth and migration discipline |
| `API.md` | Endpoints, envelopes, errors, auth, rate limits, idempotency |
| `ROLES_PERMISSIONS.md` | Roles, permission registry, the full matrix, policies |
| `FRONTEND_ARCHITECTURE.md` | Routes, modules, TanStack Query usage, builder, player |
| `DESIGN_SYSTEM.md` | Tokens, components, interaction patterns, accessibility |
| `ROADMAP.md` | Dependency graph, 19 phases, MVP line |
| `../CLAUDE.md` | How to actually write code here |

## 5. Major findings from the audit

1. **Tutor LMS Pro is not installed** on this machine. Everything about premium features
   is inferred from the free core's extension seams and the vendor's own documentation,
   and is marked as such. Do not treat it as verified.
2. **Tutor's functional depth is real and worth learning from** — 15 quiz question types,
   three feedback modes, two completion modes, a cart-engine abstraction, a gateway
   abstraction, and marketplace economics (commission, fees, withdrawal maturity).
3. **Tutor's architecture is the anti-pattern.** `Utils.php` is 10,503 lines with 289
   public methods. Business logic, persistence and HTML rendering share classes.
4. **The real API is 137 untyped `wp_ajax_*` actions**, many returning HTML fragments.
   The public REST API covers roughly 10% of the product. A mobile app cannot be built
   on it — which is precisely why Orbito is API-first.
5. **Progress is the weakest subsystem.** One `usermeta` row per completed lesson, and
   course percentage recomputed on every read with a per-assignment query in a loop.
6. **Everything lives in `wp_posts`/`wp_postmeta`** — including enrollments, reviews, Q&A
   and assignment submissions. Course cards need multiple meta and comment joins to render.
7. **Klasio's lesson is coherence, not feature count.** Every feature on every plan, no
   plugins. Its pricing tiers reveal the metering dimensions any SaaS LMS must be able to
   count cheaply: students, courses, instructors, staff, storage, webinars, downloads.
8. **Neither product offers course-scoped delegation.** Course Manager / Reviewer / TA is
   a real differentiator and costs almost nothing if designed in from the start.

## 6. MVP in one sentence

An instructor signs up, is approved, builds a course with lessons, quizzes and
assignments, prices it and publishes it; a student buys it with a verified payment,
learns through a good player, is graded, completes it, and downloads a verifiable
certificate — on a phone, in dark mode, with a keyboard.

## 7. Current status

**Phases 0–15 are complete, front and back**, plus a multi-tenancy retrofit.
1,043 backend tests / 3,418 assertions · 249 frontend tests.

An instructor can build and publish a course, write quizzes across ten
question types, set assignments, schedule live sessions and cohorts, announce
things, answer questions, and work through one queue of everything waiting to
be marked. A learner can find the course, buy it, enrol, learn through a real
player with video resume and notes, take a timed quiz, hand in written and
uploaded work, read their feedback and hand in again, attend a live class,
ask a question, review the course, earn points and badges, download a
verifiable certificate — and see all of it in a calendar, an inbox and a
dashboard. Academy staff get analytics built from an append-only event log.

Underneath: progress **stored** (ADR-02), access answered by a **single
service** (ADR-03), correct answers that never leave the server during an
attempt (ADR-06), analytics as a log plus rollups (ADR-08), and **one database
per academy** (ADR-13), which made the catalogue members-only.

**The platform operator now has a surface of their own.** A permanent owner
account exists in every environment, cannot be deleted, suspended or demoted,
and holds both super-admin answers — the central flag that opens the academy
registry, and the Super Admin role inside every academy. `/platform/academies`
provisions, approves, suspends, prices and renews them, and lets an operator
step INSIDE one to use its own screens. Registration was the last piece: it
now takes the academy's slug from the link an academy hands out, so an account
belongs somewhere. Each academy chooses whether it accepts sign-ups at all.

**Two integrations are written and UNPROVEN**, and neither is called done:
`StripeGateway` has never contacted Stripe, and `ZoomProvider` /
`GoogleMeetProvider` have never contacted either service. Both need
credentials, not code. Commerce works end to end against `FakeGateway`; live
learning works end to end with the manual provider.

**Phase 16 (Advanced Business) has started.** **Plan limits are enforced** —
the oldest open item in the codebase, counted since P4 and read by nothing
until now. `PlanLimits` answers "has this academy's plan room for one more?",
and the answer is both rendered (`/admin/plan`) and enforced (402
`plan_limit_reached`). Courses and instructor seats block at the cap; students
and storage are counted and surfaced but never block, because a learner
enrolling cannot change their academy's plan. See `ROADMAP.md` §Phase 16.

**Bundles have shipped**, and closed a Phase 10 hole on the way: nothing in
the product could set a price, so no paid course was publishable and the whole
paid path was unreachable. See `ROADMAP.md` §Phase 16 and `BUNDLES.md`.

Still ahead in the phase: subscriptions and memberships, downloads,
coaching, the blog and page builder, multilingual and RTL, and outbound
webhooks. It is markedly larger than the phases before it, and it is where the
public marketing surface finally arrives — which is what webinar registration
and lead capture have both been waiting for.

## 8. Decisions taken

| # | Question | Decision | Status |
|---|---|---|---|
| 1 | Laravel 12 or 13? | **Laravel 13** (13.30.1 installed) | ✅ settled |
| 2 | Single-tenant or multi-tenant? | ~~Single tenant per deployment.~~ **REVERSED after Phase 9: one database per academy**, via `stancl/tenancy`, matching the Orbito product. `config('orbito.multi_tenant')` is now `true`. R4 warned this could not be added cheaply after P4 — that was true of a `tenant_id` column and wrong about schema-per-tenant, which moved the migrations wholesale. See ADR-13. | ✅ settled the other way |
| 3 | MVP payment gateways | **Stripe + PayPal** in Phase 10. Regional gateways (SSLCommerz / bKash / Nagad) deferred to a later phase. | ✅ settled |
| 4 | Base currency and launch locales | **Base BDT, USD enabled**; locales **en + bn**. Set in `api/.env` (`ORBITO_BASE_CURRENCY`, `ORBITO_SUPPORTED_LOCALES`). No longer cheap to change: a basket takes the base currency at creation and orders have been written against it. Changing it now means a data migration, not an env edit. | ✅ settled by use (P10) |
| 6 | Who is the merchant for a course sale? | **The academy.** It connects its own gateway credentials, encrypted per tenant; the platform never touches learner money and takes on no money-transmitter exposure. Supersedes `DATABASE.md` §6, which assumed one merchant — `instructor_earnings`/`payouts` become an academy's internal ledger. | ✅ settled (P10) |
| 7 | Does platform billing get real payments in P10? | **No.** `AssignPlan` stays the operator's manual lever; Stripe Billing is a separate integration from one-off checkout. | ✅ settled (P10) |
| 5 | Video hosting | **Self-hosted upload + YouTube/Vimeo**, shipped in Phase 6. The planned `VideoProvider` *interface* came out as an **enum** the player branches on, so adding Bunny/Mux is a new case plus a URL builder rather than a config change (risk R3). | ✅ settled; the seam is weaker than planned |
