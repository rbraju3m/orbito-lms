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

Phases 0–8 are complete. An instructor can build and publish a course, write
quizzes across ten question types, set assignments, and work through one queue
of everything waiting to be marked; a student can find the course, enrol, learn
through a real player with video resume and notes, take a timed quiz, hand in
written and uploaded work, read their feedback and hand in again — with
progress **stored** (ADR-02), access answered by a **single service** (ADR-03),
and correct answers that never leave the server during an attempt (ADR-06).
Phase 9 (enrollment and access) is next — see `ROADMAP.md`.

## 8. Decisions taken

| # | Question | Decision | Status |
|---|---|---|---|
| 1 | Laravel 12 or 13? | **Laravel 13** (13.30.1 installed) | ✅ settled |
| 2 | Single-tenant or multi-tenant? | **Single tenant per deployment.** Recorded in code as `config('orbito.multi_tenant') === false` so the assumption is visible, not implicit. Risk R4 is closed. | ✅ settled |
| 3 | MVP payment gateways | **Stripe + PayPal** in Phase 10. Regional gateways (SSLCommerz / bKash / Nagad) deferred to a later phase. | ✅ settled |
| 4 | Base currency and launch locales | **Base BDT, USD enabled**; locales **en + bn**. Set in `api/.env` (`ORBITO_BASE_CURRENCY`, `ORBITO_SUPPORTED_LOCALES`). Nothing prices anything yet, so this is still costless to change — but it stops being so the moment Phase 10 writes an order. | ⚠️ default still unconfirmed; **last cheap moment is before Phase 10** |
| 5 | Video hosting | **Self-hosted upload + YouTube/Vimeo**, shipped in Phase 6. The planned `VideoProvider` *interface* came out as an **enum** the player branches on, so adding Bunny/Mux is a new case plus a URL builder rather than a config change (risk R3). | ✅ settled; the seam is weaker than planned |
