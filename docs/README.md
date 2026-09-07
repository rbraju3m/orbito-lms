# Orbito LMS — Documentation

**Status:** Phases 0–5 complete. Audit, architecture, foundation, identity,
course management, and the curriculum builder. Phase 6 — the learning
experience — is next.

Start here → [`MASTER_PLAN.md`](MASTER_PLAN.md)

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

13. [`TESTING.md`](TESTING.md) — test strategy, tooling, CI

**Before writing any code** → [`../CLAUDE.md`](../CLAUDE.md)

## Documents planned for later phases

`SECURITY.md` (P19) · `PERFORMANCE.md` (P19) · `DEPLOYMENT.md` (P19) ·
`UI_UX.md` (folded into `DESIGN_SYSTEM.md` + `FRONTEND_ARCHITECTURE.md` for now) ·
`EVENTS.md` (P2, the domain event catalogue).

## Reference installations — read only

- Tutor LMS 4.0.7: `/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`
  — **never modify this plugin or its database.**
