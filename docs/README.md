# Orbito LMS — Documentation

**Status:** Phases 0–8 complete. Audit, architecture, foundation, identity,
course management, curriculum builder, the learning experience, the quiz
engine, and assignments. Phase 9 — enrollment and access — is next.

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
| `ROADMAP.md` | Phases 0–8 carry delivery notes and verified transcripts |
| `ARCHITECTURE_PROPOSAL.md` | every ADR carries a delivery status; ADR-09 is partly done and says so |
| `API.md` | endpoints are marked **live** or **planned** |
| `DATABASE.md` | assessment and assignment tables match the migrations; later contexts are still design |
| `FEATURE_MATRIX.md` | each shipped section carries a "shipped / still open" note |
| `FRONTEND_ARCHITECTURE.md` | routes and feature folders that exist are marked ✅ |
| `DESIGN_SYSTEM.md` | tokens and patterns hold; the component inventory is mostly still a wish list, and says so |
| `EVENTS.md` | current — 20 events, and it says which have no listener yet |
| `TESTING.md` | current, including the Playwright gap |
| `TUTOR_AUDIT.md`, `KLASIO_REFERENCE.md`, `PRODUCT_VISION.md` | research and intent; unchanged by implementation |

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
12. [`ROADMAP.md`](ROADMAP.md) — dependency graph and 19 phases

13. [`EVENTS.md`](EVENTS.md) — the domain event catalogue and who listens
14. [`TESTING.md`](TESTING.md) — test strategy, tooling, CI

**Before writing any code** → [`../CLAUDE.md`](../CLAUDE.md)

## Documents planned for later phases

`SECURITY.md` (P19) · `PERFORMANCE.md` (P19) · `DEPLOYMENT.md` (P19) ·
`UI_UX.md` (folded into `DESIGN_SYSTEM.md` + `FRONTEND_ARCHITECTURE.md` for now).

## Reference installations — read only

- Tutor LMS 4.0.7: `/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`
  — **never modify this plugin or its database.**
