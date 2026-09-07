# FEATURE_MATRIX.md — Complete Feature Matrix

Legend
- **Tutor**: `Core` = free core (verified in source) · `Pro` = Tutor Pro add-on
  (**not installed here — inferred**, see `TUTOR_AUDIT.md` §0) · `—` = absent
- **Klasio**: `Yes` · `Upcoming` (vendor-stated) · `—`
- **Orbito**: the phase it lands in. `M` = **MVP** (Phases 2–11).
- Phases are defined in `ROADMAP.md`.

**Multi-tenancy (ADR-13)** is not in this matrix because neither reference
product has it: Tutor is a WordPress plugin and Klasio is closed managed-SaaS.
Orbito runs one database per academy, which is a platform capability rather
than an LMS feature — see `ROADMAP.md` Phase T.

---

## A. Identity & Access

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| A1 | Registration (student) | Core | Yes | **P3 · M** | Email + password |
| A2 | Login / logout | Core | Yes | **P3 · M** | Sanctum; cookie for web, token for mobile |
| A3 | Email verification | Pro | Yes | **P3 · M** | Signed URL, queued mail |
| A4 | Password reset | Core | Yes | **P3 · M** | |
| A5 | Instructor registration + approval | Core | Yes | **P3 · M** | pending / approved / blocked |
| A6 | Session & device management | Pro | — | P19 | Token listing + revoke |
| A7 | Two-factor auth | Pro | — | P19 | TOTP |
| A8 | Social login | Pro | Yes | P16 | Socialite behind an interface |
| A9 | SSO / SAML | — | Enterprise | Post-1.0 | |
| A10 | Roles & permissions registry | Core (WP caps) | Partial | **P3 · M** | See `ROLES_PERMISSIONS.md` |
| A11 | Course-scoped roles (TA / reviewer / manager) | — | — | **P3 · M** | **Orbito differentiator** |
| A12 | Staff role (non-teaching operations) | — | Yes | P3 | |
| A13 | Profiles: avatar, bio, job title, socials, timezone, locale | Core | Yes | **P3 · M** | |
| A14 | "View as student" toggle for instructors | Core | — | P6 | Keep — good UX |
| A15 | Audit log of privileged actions | — | — | P19 | |
| A16 | GDPR consent + data export/erase | Core | — | P19 | Tutor has a decent model |

**Shipped in Phase 3:** A1–A5, A10, A11, A13. Course-scoped roles (A11) work
through `role_assignments(scope_type, scope_id)`; see ADR-07 and the correction
that came with it. A12 (staff role) exists in the registry but has no dedicated
screens yet.

**Still open:** A6, A7, A15, A16 (P19) · A8 (P16) · A9, A14 (post-1.0 / P6
backlog — the "view as student" toggle was not built).

## B. Catalog (Courses)

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| B1 | Course CRUD | Core | Yes | **P4 · M** | |
| B2 | Draft / In review / Published / Archived | Core (via post status) | Yes | **P4 · M** | Explicit state machine, not WP statuses |
| B3 | Categories (hierarchical) | Core | Yes | **P4 · M** | |
| B4 | Tags | Core | Yes | **P4 · M** | |
| B5 | Difficulty level | Core | Yes | **P4 · M** | |
| B6 | Language | — | Yes | **P4 · M** | |
| B7 | Duration | Core | Yes | **P4 · M** | Computed from curriculum + manual override |
| B8 | Thumbnail | Core | Yes | **P4 · M** | |
| B9 | Intro / trailer video | Core | Yes | **P4 · M** | |
| B10 | Description, objectives, requirements, target audience, materials | Core | Yes | **P4 · M** | |
| B11 | Primary instructor | Core | Yes | **P4 · M** | |
| B12 | Co-instructors | Pro | Yes | **P4 · M** | Real pivot table, not usermeta |
| B13 | Course-level attachments / resources | Core | Yes | **P4 · M** | |
| B14 | Completion mode: flexible vs strict | Core | — | **P4 · M** | Keep — good idea |
| B15 | "Coming soon" state | Core | — | P4 | |
| B16 | Course prerequisites | Pro | — | **P9 ✅** | Gates enrolment, never ongoing access; cycles rejected |
| B17 | Max students / seat limit | Pro | — | **P9 ✅** | Counted under a row lock; manual grants respect it too |
| B18 | Enrollment expiry period | Pro | Yes | **P9 ✅** | Evaluated live, so access never waits on the sweeper |
| B19 | Course SEO metadata | — | Yes | P16 | |
| B20 | Course versioning / draft-over-published | — | — | Post-1.0 | Flagged risk; see `ARCHITECTURE_PROPOSAL.md` |
| B21 | Course duplication | Pro | — | P5 | |
| B22 | Course archive / unlist | Core (trash) | Yes | **P4 · M** | Archive ≠ delete |

**Shipped in Phase 4:** B1–B14 and B22. The status machine (B2) lives in one
Action, `ChangeCourseStatus`, and `PublishChecklist` is both rendered by the UI
and enforced on publish so the two cannot drift.

**Still open:** B15 (`coming_soon_at` is in the schema but nothing reads it) ·
B16–B18 ✅ (P9) · B19 (P16) · **B21 course duplication, which the matrix put in P5
and which was not built** — section and item duplication were (C10) · B20
post-1.0.

## C. Curriculum

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| C1 | Sections (topics) | Core | Yes | **P5 · M** | |
| C2 | Lessons | Core | Yes | **P5 · M** | |
| C3 | Quizzes in curriculum | Core | Yes | **P5 · M** | |
| C4 | Assignments in curriculum | Pro | Yes | **P5 · M** | Orbito ships it free |
| C5 | Resources / downloadable items | Core (attachments) | Yes | **P5 · M** | |
| C6 | Live session as a curriculum item | Pro | Yes | P15 | Same `course_items` spine |
| C7 | Drag-and-drop reordering | Core | Yes | **P5 · M** | Single `course_items` table |
| C8 | Cross-section item move | Core | Yes | **P5 · M** | |
| C9 | Inline rename | Partial | Yes | **P5 · M** | |
| C10 | Duplicate section / item | Pro | — | P5 | |
| C11 | Collapse / expand, autosave | Core | Yes | **P5 · M** | |
| C12 | Preview flag on an item | Core | Yes | **P5 · M** | |
| C13 | Drip content (by date / by days / after previous) | Pro | Yes (free) | **P9 ✅** | Server-enforced on both read and write paths |
| C14 | Content bank (reusable items across courses) | Pro | — | P16 | |
| C15 | Curriculum validation before publish | Partial | — | **P5 · M** | e.g. no empty sections |

**Shipped in Phase 5:** C1, C2, C5, C7–C12, C15. C3 and C4 became real in P7
and P8 respectively — the spine accepted both without changing shape, which
was the point of ADR-01.

**Still open:** C6 (P15) · C14 (P16). C13 drip shipped in P9.

## D. Lessons & Media

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| D1 | Rich text lesson body | Core | Yes | **P6 · M** | Tiptap via `@mantine/tiptap` |
| D2 | Self-hosted video | Core | Yes | **P6 · M** | |
| D3 | YouTube / Vimeo | Core | Yes | **P6 · M** | |
| D4 | External URL / embed | Core | Yes | **P6 · M** | |
| D5 | Audio lesson | — | Yes | P6 | |
| D6 | PDF / document lesson | Partial (attachment) | Yes | **P6 · M** | |
| D7 | Per-lesson attachments | Core | Yes | **P6 · M** | |

**Shipped in Phase 6:** D2, D3, D4 and video playback with resume, plus a
lesson body that is **sanitised on write** against an allowlist. D6 is stored
(`document_media_id`) but has no viewer yet.

**Still open, and the matrix was optimistic here:** D1 — the rich text
*editor* was never built. Lesson bodies are authored in a plain textarea and
rendered as sanitised HTML; `@mantine/tiptap` is not installed. D5 (audio) is
a schema column with no code behind it. D7 per-lesson attachments have no
endpoint. All three are small, and all three are unfinished.
| D8 | Video watch-position resume | Core | Yes | **P6 · M** | Throttled heartbeat |
| D9 | Video completion threshold (e.g. 90 %) | — | — | P6 | Server-side rule |
| D10 | Protected/signed media delivery | Pro | Yes | **P6 · M** | Private disk + signed URLs |
| D11 | HLS / adaptive streaming | — | Yes | P16 | Provider-backed (Bunny/Mux) |
| D12 | Central media library | Partial (WP) | Yes | **P6 · M** | |
| D13 | Direct-to-S3 / R2 upload | — | Yes | **P6 · M** | Presigned multipart |
| D14 | Storage quota accounting | — | Yes (billed) | P16 | Bytes per owner |
| D15 | Lesson notes (student) | Pro | — | P6 · M | |
| D16 | Lesson comments | Core | — | P12 | |

## E. Assessment — Quiz

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| E1 | Single choice | Core | Yes | **P7 · M** | |
| E2 | Multiple choice | Core | Yes | **P7 · M** | |
| E3 | True / false | Core | Yes | **P7 · M** | |
| E4 | Short answer | Core | Yes | **P7 · M** | Manual or keyword grading |
| E5 | Long answer / open ended | Core | Yes | **P7 · M** | Manual grading |
| E6 | Fill in the blank | Core | Yes | **P7 · M** | |
| E7 | Matching | Core | Yes | **P7 · M** | |
| E8 | Image matching | Core | — | P7 | |
| E9 | Ordering | Core | Yes | **P7 · M** | |
| E10 | Image answering | Core | — | P7 | |
| E11 | Interactive: draw / pin / scale / coordinates / puzzle | Core | — | Post-1.0 | Low ROI, high build cost |
| E12 | Question bank / reusable questions | Pro | — | P7 | Orbito ships it free |
| E13 | Random question selection | Pro | — | P7 | |
| E14 | Question shuffle | Core | Yes | **P7 · M** | |
| E15 | Answer shuffle | Core | Yes | **P7 · M** | |
| E16 | Timer + "when time expires" policy | Core | Yes | **P7 · M** | Server-authoritative deadline |
| E17 | Attempt limit | Core | Yes | **P7 · M** | |
| E18 | Passing score | Core | Yes | **P7 · M** | |
| E19 | Negative marking | Core | — | P7 | |
| E20 | Auto grading | Core | Yes | **P7 · M** | |
| E21 | Manual grading queue | Core | Yes | **P7 · M** | |
| E22 | Feedback modes (default / reveal / retry) | Core | Yes | **P7 · M** | Keep |
| E23 | Per-question explanation | Core | Yes | **P7 · M** | |
| E24 | Final grade calculation policy (first/last/best/avg) | Core | — | P7 | |
| E25 | Per-question analytics (difficulty, discrimination) | — | — | P13 | |
| E26 | Anti-cheat: server-held answers, IP/UA log, tab-blur signal | Partial | — | **P7 · M** | Answers never leave the server mid-attempt |

**Shipped in Phase 7:** E1–E10, E14–E23, and the server-held half of E26 (the
attempt records IP and user agent at start; the tab-blur signal is not built).

**Still open in this section:** E11 (post-1.0 by decision), E12 and E13 — the
`question_banks` table and `questions_per_attempt` both exist and the random
subset is drawn at attempt start, but there is no bank UI or import-from-bank
endpoint yet — E24, where `grading_policy` is stored and editable but nothing
consumes it until the gradebook (P13), and E25 (P13).

## F. Assessment — Assignment

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| F1 | Assignment creation + instructions | Pro | Yes | **P8 · M** | |
| F2 | Attachments on the assignment | Pro | Yes | **P8 · M** | |
| F3 | Deadline (+ late policy) | Pro | Yes | **P8 · M** | |
| F4 | File upload submission | Pro | Yes | **P8 · M** | Type/size/AV validated |
| F5 | Text submission | Pro | Yes | **P8 · M** | |
| F6 | Grading + points | Pro | Yes | **P8 · M** | |
| F7 | Instructor feedback | Pro | Yes | **P8 · M** | |
| F8 | Re-submission policy | Pro | Yes | **P8 · M** | |
| F9 | Grading rubric | — | — | Post-1.0 | |
| F10 | Plagiarism / similarity | — | — | Post-1.0 | |

**Shipped in Phase 8:** F1–F8. Grading and feedback share one queue with
quizzes (`GET /studio/courses/{course}/grading`) rather than living in a second
screen. Re-submission (F8) also covers handing work *back*: a returned
submission re-opens the assignment and does not consume an attempt.

**Still open:** F9 and F10, both post-1.0 by decision.

## G. Enrollment & Access

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| G1 | Free enrollment | Core | Yes | **P9 · M** | |
| G2 | Paid enrollment after verified payment | Core | Yes | ⚠️ P10 part-built | Path written end to end; never executed |
| G3 | Manual enrollment (single) | Pro | Yes | **P9 · M** | |
| G4 | Bulk enrollment / CSV | Pro | Yes | P9 | |
| G5 | Enrollment expiry | Pro | Yes | **P9 ✅** | Suspend / reinstate / extend / revoke |
| G6 | Suspension / revoke | Partial | Yes | **P9 · M** | |
| G7 | Access via subscription | Pro | Yes | P16 | |
| G8 | Access via bundle | Pro | Yes | P16 | |
| G9 | Access via membership | Pro | Yes | P16 | |
| G10 | Single access-resolution service | — | — | **P9 · M** | **One** `CourseAccess` service, all sources |
| G11 | Guest / preview access to preview items | Core | Yes | **P9 · M** | |
| G12 | Waitlist | — | — | Post-1.0 | |

## H. Progress & Completion

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| H1 | Item completion tracking | Core (usermeta) | Yes | **P6 · M** | `item_progress` table |
| H2 | Course % progress | Core (recomputed) | Yes | **P6 · M** | Denormalised aggregate |
| H3 | Video watch progress | Core | Yes | **P6 · M** | |
| H4 | Last activity / continue learning | Core | Yes | **P6 · M** | |
| H5 | Course completion (flexible/strict) | Core | Yes | **P9 · M** | |
| H6 | Reset progress | Core | — | P9 | |
| H7 | Course retake | Core | — | P9 | |
| H8 | Progress heatmap / stall detection | — | Yes | P13 | Klasio-inspired |
| H9 | Gradebook across items | Pro | — | P13 | |

**Shipped in Phase 6:** H1–H4, and H5–H6 arrived early — course completion in
both modes and `POST /learn/courses/{course}/reset-progress` are both live,
ahead of the P9 the matrix predicted. Progress is **stored** and event-
maintained (ADR-02), never recomputed on a read path, and reconciled nightly.

**Still open:** H7 retake (P9) · H8, H9 (P13). H9 is the first consumer of
`quizzes.grading_policy`, which is stored and editable today but which nothing
reads yet.

## I. Certificates

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| I1 | Certificate templates | Pro | Yes | **P11 · M** | |
| I2 | Dynamic fields (name, course, date, score) | Pro | Yes | **P11 · M** | |
| I3 | PDF generation (queued) | Pro | Yes | **P11 · M** | |
| I4 | Unique certificate number | Pro | Yes | **P11 · M** | |
| I5 | Public verification page | Pro | Yes | **P11 · M** | |
| I6 | QR code on certificate | Pro | — | **P11 · M** | |
| I7 | Drag-and-drop certificate builder | Pro | — | Post-1.0 | Use fixed templates first |
| I8 | Revocation | — | — | P11 | Status column |

## J. Commerce

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| J1 | Product abstraction (course/bundle/download/plan/coaching) | Partial | Yes | ⚠️ P10 part-built | Model + migration only; untested |
| J2 | Cart | Core | Yes | ⚠️ P10 part-built | Models only; no endpoints |
| J3 | Checkout | Core | Yes | ⚠️ P10 part-built | `PlaceOrder` written, untested, unreachable |
| J4 | Guest checkout | Core | Yes | P10 | |
| J5 | Orders + order items with price snapshot | Core | Yes | ⚠️ P10 part-built | Snapshots designed in; untested |
| J6 | Payments table + gateway events | Partial | Yes | ⚠️ P10 part-built | Not a LONGTEXT column; untested |
| J7 | Server-side payment verification / webhooks | Core | Yes | ⚠️ P10 part-built | `HandleWebhook` written; NO test, NO route |
| J8 | Refunds (full + partial) | Core | Yes | **P10 · M** | |
| J9 | Coupons (code + automatic, scoped, limits) | Core | Yes | **P10 · M** | |
| J10 | Tax rules by country/state | Core | Yes | P10 | |
| J11 | Invoices (PDF, sequential numbering) | Pro | Yes | P10 | |
| J12 | Multi-currency | — | — | **P10 · M** (model) | Integer minor units + FX; UI in P16 |
| J13 | Stripe | Pro | Yes | ⚠️ P10 adapter written | Never contacted Stripe; unverified |
| J14 | PayPal | Core | Yes | **P10 · M** | |
| J15 | SSLCommerz / bKash / Nagad | — | — | P10 | **Orbito differentiator** |
| J16 | Instructor earnings + commission split | Core | n/a | Reconsider | Academy is the merchant, so this is an academy-internal ledger, not a platform one |
| J17 | Withdrawals + maturity days | Core | n/a | Reconsider | See J16 — the platform holds no funds to withdraw |
| J18 | Subscriptions / recurring | Pro | Yes | P16 | |
| J19 | Memberships | Pro | Yes | P16 | |
| J20 | Product bundles | Pro | Yes | P16 | |
| J21 | Digital downloads as products | — | Yes | P16 | |
| J22 | Coaching / bookable sessions | — | Yes | P16 | |
| J23 | Gift a course | Core | — | Post-1.0 | |

## K. Engagement

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| K1 | Course reviews + rating | Core | Yes | **P12 · M** | Aggregate columns on `courses` |
| K2 | Review moderation | Core | — | **P12 · M** | |
| K3 | Course Q&A (threaded) | Core | — | **P12 · M** | |
| K4 | Instructor answer + resolved state | Core | — | **P12 · M** | |
| K5 | Announcements | Core | — | P12 | |
| K6 | Wishlist | Core | — | P12 | |
| K7 | Notifications (in-app) | Pro | Yes | **P12 · M** | Laravel Notifications |
| K8 | Notifications (email, templated) | Pro | Yes | **P12 · M** | |
| K9 | Notification preferences | Pro | — | P12 | |
| K10 | Push notifications (mobile) | — | Yes | P18 | |

## L. Analytics

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| L1 | Event stream (append-only) | — | — | **P13 · M** | Foundation for everything below |
| L2 | Admin dashboard KPIs | Pro | Yes | **P13 · M** | From rollups, never raw |
| L3 | Enrollment & revenue trends | Pro | Yes | **P13 · M** | |
| L4 | Course performance | Pro | Yes | **P13 · M** | |
| L5 | Instructor performance | Pro | Yes | P13 | |
| L6 | Student activity | Pro | Yes | P13 | |
| L7 | Per-item drop-off / heatmap | — | Yes | P13 | Klasio-inspired |
| L8 | Exportable reports (CSV) | Pro | Yes | P13 | |

## M. Gamification

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| M1 | Points | — | Yes | P14 | Event-driven rules |
| M2 | Badges | — | Yes | P14 | |
| M3 | Achievements / milestones | — | Yes | P14 | |
| M4 | Streaks | — | — | P14 | |
| M5 | Leaderboards | — | Yes | P14 | Snapshot tables |
| M6 | Completion rewards | — | Yes | P14 | |

## N. Live Learning

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| N1 | Live session model + schedule | Pro | Yes | P15 | Provider-agnostic |
| N2 | Zoom integration | Pro | Yes | P15 | |
| N3 | Google Meet integration | Pro | Yes | P15 | |
| N4 | Cohorts (a scheduled run of a course) | — | Yes | P15 | |
| N5 | Attendance | Pro | Yes | P15 | |
| N6 | Webinars (standalone, registration) | — | Yes | P15 | |
| N7 | Reminders | Pro | Yes | P15 | Scheduler + queue |
| N8 | Recording linkage | Pro | Yes | P15 | |
| N9 | Calendar view | Pro | Yes | P15 | |

## O. Content & Site

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| O1 | Blog (posts, categories, tags, authors, SEO) | — (WP native) | Yes | P16 | |
| O2 | Page builder (block-based) | Via WP builders | Yes | P16 | Architecture only in P1 |
| O3 | Landing / course sales page templates | Themes | Yes | P16 | |
| O4 | Lead capture forms | — | Yes | P16 | |
| O5 | Multilingual UI | Core (i18n) | Upcoming | **P4 · M** (foundation) | i18n from day one |
| O6 | Multilingual content (course translations) | Requires plugin | Upcoming | P16 | Model designed in P1 |
| O7 | Light / dark / system theme | Core | — | **P2 · M** | |
| O8 | RTL | Core | — | P16 | |

## P. AI

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| P1 | Provider abstraction | Pro (AI Studio) | Yes | P17 | Never a core dependency |
| P2 | Generate course outline | Pro | Yes | P17 | |
| P3 | Generate lesson draft | Pro | Yes | P17 | |
| P4 | Generate quiz questions | Pro | Yes | P17 | |
| P5 | Generate description / objectives | Pro | Yes | P17 | |
| P6 | Summarise lesson | — | Yes | P17 | |
| P7 | Generate blog post | — | Yes | P17 | |
| P8 | Image prompt / thumbnail | Pro | Yes | P17 | |
| P9 | Generation audit log + cost accounting | — | — | P17 | |

## Q. Platform / Operations

| # | Feature | Tutor | Klasio | Orbito | Notes |
|---|---|---|---|---|---|
| Q1 | Versioned REST API `/api/v1` | Partial (12 routes) | Internal | **P2 · M** | |
| Q2 | Consistent error envelope | — | n/a | **P2 · M** | |
| Q3 | Pagination contract (offset + cursor) | Partial | n/a | **P2 · M** | |
| Q4 | Mobile-ready token auth | Pro | Yes | **P3 · M** | |
| Q5 | Webhooks out (integrations) | — | Yes | P16 | |
| Q6 | Rate limiting | WP-level | Yes | **P2 · M** | |
| Q7 | Queues + scheduler | WP-Cron + custom table | n/a | **P2 · M** | Redis + Horizon |
| Q8 | Redis caching | Object cache | n/a | **P2 · M** | |
| Q9 | Structured logging + error tracking | WP debug | n/a | **P2 · M** | |
| Q10 | Test suite (unit/feature/API) | Minimal | n/a | **P2 · M** | Pest |
| Q11 | Static analysis | PHPCS | n/a | **P2 · M** | Larastan |
| Q12 | CI/CD | — | n/a | P19 | |
| Q13 | Plan limits / usage counters | — | Yes | P16 (counters **P4**) | Design early, bill later |
| Q14 | Public roadmap / changelog | Yes | Yes | P19 | |

---

## MVP definition (Phases 2–11)

**In:** auth + roles + course-scoped roles · course CRUD & publishing · curriculum builder
with drag/drop and autosave · lesson player with video, progress and notes · complete quiz
engine (10 question types) · complete assignment workflow · enrollment and a single access
resolver · commerce with Stripe + PayPal, coupons, refunds, verified webhooks ·
certificates with public verification · reviews and Q&A · light/dark, responsive, a11y ·
tests + static analysis + CI-ready.

**Out of MVP (deliberately):** subscriptions, memberships, bundles, digital downloads,
coaching, live classes, webinars, cohorts, gamification, AI, page builder, blog,
content bank, interactive quiz types, certificate builder, course versioning, SSO.

Rationale: the MVP is the smallest product an instructor can build, sell, and deliver a
course on, end to end, with money changing hands and a certificate at the end. Everything
excluded is additive, not structural — and every excluded item has its extension seam
designed in Phase 1 so adding it later is not a rewrite.
