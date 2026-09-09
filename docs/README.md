# Orbito LMS — Documentation

**Status: Phases 0–15 complete**, front and back, plus a **multi-tenancy
retrofit** (ADR-13) that reversed the single-tenant decision partway through.

1,043 backend tests / 3,418 assertions · 249 frontend tests · Pint, PHPStan
level 6, oxlint, tsc and build all clean.

Audit, architecture, foundation, identity, course management, the curriculum
builder, the learning experience, quizzes, assignments, enrolment & access,
one database per academy, commerce, certificates, engagement & notifications,
analytics, gamification, and live learning.

Since Phase 15 the **platform operator's own surface** landed on top of that:
a permanent owner account that cannot be locked out, the academy registry at
`/platform/academies` — provision, approve, suspend, plan, and step inside an
academy — and a signup that finally knows which academy it is writing into.

**Two things are built but UNPROVEN against the outside world**, and both say
so wherever they appear:

- `StripeGateway` (P10) has never contacted Stripe. Commerce is complete and
  tested against `FakeGateway`; no real money has moved.
- `ZoomProvider` and `GoogleMeetProvider` (P15) have never contacted either
  service. `ManualProvider` — the host pastes a link — works and is tested.

Both need credentials, not code. Neither is called done.

> **If you read one thing before writing code, read `CLAUDE.md` — the
> Multi-tenancy section.** Tenancy changes how every query and every model
> behaves, and the mistakes it produces do not look like tenancy mistakes.
>
> Sections in `CLAUDE.md` are cited BY NAME, not by number: the numbering has
> shifted five times as phases added their own pattern sections.

Start here → [`MASTER_PLAN.md`](MASTER_PLAN.md)
Picking the work back up → [`ROADMAP.md`](ROADMAP.md), which opens with exactly
where things stand and what each finished phase actually delivered.

## How to read these while the build is in progress

Some of these documents were written before the code and are now partly a
record and partly still a plan. Where the two differ, the difference is stated
rather than quietly edited away — a doc that silently rewrites its own history
is worth less than one that says "this came out differently, and here is why".

| Document | How much of it is built |
|---|---|
| `ROADMAP.md` | Phases 0–15 and Phase T carry delivery notes; each retro opens with what the phase decided and closes with what it deliberately left |
| `ARCHITECTURE_PROPOSAL.md` | every ADR carries a delivery status; ADR-13 records the tenancy reversal, and risk R4 says why its own estimate was wrong |
| `API.md` | endpoints are marked **live** or **planned**; §2a explains why nothing is public any more |
| `DATABASE.md` | §0 is the central/tenant boundary and is authoritative — including what lives in `tenants.data` rather than a column; every context matches the migrations, and each records where the built schema DIFFERS from the sketch and why |
| `FEATURE_MATRIX.md` | shipped rows are bolded and carry the decision that shaped them; ⚠ marks the two unproven integrations |
| `FRONTEND_ARCHITECTURE.md` | routes and feature folders that exist are marked ✅; the header explains what tenancy removed |
| `DESIGN_SYSTEM.md` | tokens and patterns hold; the component inventory is partly still a wish list, and says so |
| `EVENTS.md` | current — 39 events across 13 contexts, with who listens, and §4 names the operator actions that deliberately fire nothing yet |
| `TESTING.md` | current, including the Playwright gap and why the suite has its own tenant prefix |
| `ROLES_PERMISSIONS.md` | current — the policy table, the two unrelated "super admins", and §7 on the permanent owner |
| `TUTOR_AUDIT.md`, `KLASIO_REFERENCE.md` | research, dated 2026-09-07; snapshots of external products, deliberately never updated |
| `PRODUCT_VISION.md` | intent, mostly unchanged by implementation — two rows now say where reality diverged |

## Reading order

**To understand the product**
1. [`MASTER_PLAN.md`](MASTER_PLAN.md) — what we're building, the ten defining decisions
2. [`PRODUCT_VISION.md`](PRODUCT_VISION.md) — who it's for, what we refuse to build
3. [`FEATURE_MATRIX.md`](FEATURE_MATRIX.md) — every feature, its phase, in or out of MVP

**To understand the research**
4. [`TUTOR_AUDIT.md`](TUTOR_AUDIT.md) — what Tutor LMS does, how, and what to avoid
5. [`KLASIO_REFERENCE.md`](KLASIO_REFERENCE.md) — product and packaging lessons

**To build it**
6. [`ARCHITECTURE_PROPOSAL.md`](ARCHITECTURE_PROPOSAL.md) — contexts, ADRs, security, performance, risks
7. [`DATABASE.md`](DATABASE.md) — proposed schema and indexes
8. [`API.md`](API.md) — endpoints, envelopes, errors, auth
9. [`ROLES_PERMISSIONS.md`](ROLES_PERMISSIONS.md) — roles, permissions, policies
10. [`FRONTEND_ARCHITECTURE.md`](FRONTEND_ARCHITECTURE.md) — routes, modules, TanStack Query
11. [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) — tokens, components, patterns, a11y
12. [`ROADMAP.md`](ROADMAP.md) — dependency graph, 19 phases, and a retro per shipped phase

13. [`EVENTS.md`](EVENTS.md) — the domain event catalogue and who listens
14. [`TESTING.md`](TESTING.md) — test strategy, tooling, CI

**Before writing any code** → [`../CLAUDE.md`](../CLAUDE.md)

## Documents planned for later phases

`SECURITY.md` (P19) · `PERFORMANCE.md` (P19) · `DEPLOYMENT.md` (P19) ·
`UI_UX.md` (folded into `DESIGN_SYSTEM.md` + `FRONTEND_ARCHITECTURE.md` for now).

## Reference installations — read only

- Tutor LMS 4.0.7: `/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`
  — **never modify this plugin or its database.**
