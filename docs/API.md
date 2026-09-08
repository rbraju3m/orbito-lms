# API.md — Proposed API Architecture

Base: `/api/v1`. JSON only. No HTML is ever returned by the API.
The web SPA, the future mobile app, and third-party integrators use the **same** endpoints.

> **Status: proposal.** No routes exist yet.

---

## 1. Conventions

| Rule | Detail |
|---|---|
| Versioning | URI-versioned `/api/v1`. Additive changes never bump the version; breaking changes create `/v2` and `/v1` is supported for 12 months. |
| Naming | Plural, kebab-free, snake-free resource segments: `/courses`, `/quiz-attempts`. Nesting only one level deep: `/courses/{course}/sections`. Beyond that, use a filter. |
| Identifiers | Numeric ids internally; **UUIDs** in URLs for anything guessable-sensitive (orders, certificates, attempts, media). |
| Verbs | `GET` read · `POST` create/command · `PATCH` partial update · `PUT` full replace (rare) · `DELETE` remove. |
| Commands | Non-CRUD operations are sub-resources, not verbs in a query string: `POST /courses/{c}/publish`, `POST /quiz-attempts/{a}/submit`. |
| Casing | `snake_case` in JSON. Consistent everywhere. |
| Dates | ISO-8601 UTC with offset: `2026-09-07T10:35:00Z`. |
| Money | `{"amount_minor": 249900, "currency": "BDT", "formatted": "৳2,499.00"}`. |
| Empty | `null` for absent scalars; `[]` for absent collections. Never omit a documented key. |
| Locale | `Accept-Language` header selects translations; `?locale=` overrides. |

---

## 2. Envelopes

**Single resource**
```json
{ "data": { "id": 12, "type": "course", "title": "…" } }
```

**Collection (offset pagination)**
```json
{
  "data": [ … ],
  "meta": { "current_page": 2, "per_page": 20, "total": 137, "last_page": 7 },
  "links": { "first": "…", "prev": "…", "next": "…", "last": "…" }
}
```

**Collection (cursor pagination — feeds, activity, discussions, analytics)**
```json
{ "data": [ … ], "meta": { "per_page": 20, "next_cursor": "eyJpZCI6…", "has_more": true } }
```

**Error** — always this shape, always an HTTP status that matches.
```json
{
  "error": {
    "code": "course_not_publishable",
    "message": "This course cannot be published yet.",
    "details": [
      { "field": "curriculum", "code": "empty_section", "message": "Section \"Intro\" has no items." }
    ],
    "request_id": "01JB8Q…",

    "meta": {
      "unlocks_at": "2026-03-12T09:00:00Z"
    }
  }
}
```

`error.meta` is **optional and omitted when empty**. It carries what the caller
can *do* about the failure, never decoration: `unlocks_at` and
`blocked_by_title` on a drip lock, `prerequisites` on a refused enrolment,
`cover_ended_at` on a lapsed subscription. A 423 that cannot say how to get in
is a dead end.

| Status | When |
|---|---|
| 400 | Malformed request |
| 401 | Missing/invalid credentials |
| 403 | Authenticated but not permitted (policy denial) |
| 404 | Not found **or** not visible to this user (never leak existence) |
| 409 | State conflict (`attempt_already_submitted`, `already_enrolled`) |
| 422 | Validation failure — `details[]` is field-keyed |
| 402 | The academy's subscription has lapsed — WRITES only, reads are never gated |
| 423 | Locked (drip not yet unlocked, access expired, not yet started) |
| 429 | Rate limited (`Retry-After` header) |
| 500 | Unhandled — `request_id` correlates to logs |

`error.code` is a **stable machine string**. The frontend switches on `code`, never on
`message`. Messages are localised; codes are not.

---

## 2a. Tenancy — read this before reading any endpoint

**Every route below except auth and `/admin/*` runs inside ONE academy**,
resolved from the authenticated user (ADR-13). Two consequences change the
contract:

- **There is no anonymous surface.** `GET /courses`, `GET /courses/{slug}`,
  `GET /categories`, the player bootstrap and preview lessons all require
  authentication. A signed-out caller gets **401**, not a public storefront.
  `is_preview` means "try before you *enrol*".
- **A 402 gates writes.** `GET`/`HEAD`/`OPTIONS` always pass; everything else
  returns `subscription_lapsed` when the academy's subscription has expired or
  been cancelled. Reading and exporting never stop. `POST /auth/logout` is
  exempt — nobody should be trapped in a lapsed academy.

The one route with no authenticated user is the signed media download, which
carries its academy inside the signed payload. Phase 10 webhooks will do the
same.

---

## 3. Authentication

| Client | Mechanism |
|---|---|
| Web SPA (same site) | Laravel Sanctum **cookie** session — `GET /sanctum/csrf-cookie`, then `POST /api/v1/auth/login`. SameSite=Lax, HttpOnly. |
| Mobile / third-party | Sanctum **personal access token**, `Authorization: Bearer <token>`, with abilities. |

```
# live
POST   /auth/register            {name,email,password,role_intent?}
POST   /auth/login               {email,password,device_name?}   → user + (token for mobile)
POST   /auth/logout
POST   /auth/forgot-password     {email}
POST   /auth/reset-password      {token,email,password}
POST   /auth/email/verify        {id,hash}
POST   /auth/email/resend
GET    /auth/me                                                  → user + effective permissions

# planned
POST   /auth/refresh                                             (token rotation, mobile)
GET    /auth/devices             · DELETE /auth/devices/{id}
POST   /auth/two-factor/enable   · /confirm · /disable            (P19)
```

`device_name` is **required** when the request has no session — a token client must
name itself, and a stateful SPA must not be handed a bearer token it never asked for.

`GET /auth/me` returns the user **and their resolved permission keys**, so the SPA can
hide UI it may not use. The server still enforces every one of them independently.

---

## 4. Domain surface

Blocks are marked **live** (implemented, tested, in the route table as of Phase 8)
or **planned** (design intent for a later phase). A planned path is not a promise
about its final shape — see `ROADMAP.md` for when each lands.

### Catalog
```
# live — MEMBERS-ONLY (see §2a; these were public before tenancy)
GET    /courses                      the academy's catalogue; filters below
GET    /courses/{slug}               detail, incl. preview-aware curriculum,
                                     prerequisites with per-course is_met,
                                     and seats_remaining (null = uncapped)
GET    /categories · GET /categories/{category}
GET    /tags

# live — authoring
POST   /studio/courses
GET    /studio/courses                 my/managed courses
GET    /studio/courses/{course}        full authoring payload + publish checklist
PATCH  /studio/courses/{course}
DELETE /studio/courses/{course}
PATCH  /studio/courses/{course}/settings
POST   /studio/courses/{course}/publish     · /unpublish · /archive
POST   /studio/courses/{course}/submit-review
POST   /studio/courses/{course}/approve-review · /reject-review
POST   /studio/courses/{course}/instructors · DELETE /…/instructors/{user}

# planned
GET    /courses/{course}/instructors
GET    /courses/{course}/reviews             (P12)
POST   /studio/courses/{course}/duplicate
```

Course status moves only through `ChangeCourseStatus`, which owns the legal
transitions; an author cannot approve their own submitted course.

Catalogue filters (documented, stable, all optional):
`?q=&category=&tags[]=&level=&language=&price=free|paid&min_rating=&instructor=&sort=popular|newest|rating|price_asc|price_desc&page=&per_page=`

### Curriculum
```
# live
GET    /studio/courses/{course}/curriculum      the whole tree, sections + items
POST   /studio/courses/{course}/sections        · PATCH /sections/{section} · DELETE
POST   /studio/sections/{section}/duplicate
POST   /studio/courses/{course}/items           {section_id, type, title}
GET    /studio/items/{item} · PATCH · DELETE    rename, preview flag, publish flag
POST   /studio/items/{item}/duplicate
PATCH  /studio/items/{item}/lesson              lesson body, video, attachments
PATCH  /studio/courses/{course}/curriculum/order   ← the ONLY reorder endpoint

# planned
drip fields on PATCH /studio/items/{item}       (P9)
```

Item types available today: `lesson`, `resource`, `quiz`, `assignment`.
`live_session` is declared on the spine but not yet creatable (P15).

`PATCH …/curriculum/order` body:
```json
{ "sections": [ { "id": 3, "position": 0, "item_ids": [11, 9, 14] } ] }
```
Validated as a permutation of the course's current set; applied in one transaction;
returns the new tree. Idempotent.

### Learning (the player)
```
# live
POST   /courses/{course}/enroll             free courses only
GET    /learn/courses                       my enrolled courses + progress
GET    /learn/continue                      "continue learning" — ONE indexed read
GET    /learn/courses/{course}              player bootstrap: course, curriculum, progress, access
GET    /learn/items/{item}                  item content — 423 if locked, with the reason
POST   /learn/items/{item}/complete
DELETE /learn/items/{item}/complete         un-complete (flexible mode)
POST   /learn/items/{item}/watch            {position_seconds}  throttled to 1/15s
GET    /learn/items/{item}/notes · POST · DELETE /learn/notes/{note}
POST   /learn/courses/{course}/complete
POST   /learn/courses/{course}/reset-progress   reset, or RETAKE if they finished

# planned
GET    /learn/courses/{course}/resources
```

Every route here requires authentication (§2a). The bootstrap and item
endpoints once served anonymous visitors a preview; they cannot now, because
an anonymous request belongs to no academy.

Each curriculum item carries `is_locked`, `unlocks_at` and `blocked_by`. A
**locked item is still listed** — hiding it would make the course look shorter
than it is and turn "10 lessons" on the sales page into a lie. Opening one
returns 423 with `error.meta` naming the date or the blocking item.

`reset-progress` is reset *or* retake depending on whether the learner had
finished, gated by `reset_progress_allowed` / `retake_allowed` respectively.
It clears only `isSelfMarkable()` items: a passed quiz and a graded assignment
are earned facts, and wiping them can leave an item permanently uncompletable
once attempts are spent.

Prev/next arrive **inside** the item payload (`previous_id`, `next_id`) rather than
from their own endpoint: the player needs them on every item anyway, and a second
round trip to learn where "next" is would be one per page turn.

`complete` refuses an item whose completion is *earned* rather than declared —
a quiz or an assignment answers 409 `progress_rejected`. See `is_self_markable`
on every curriculum item.

### Assessment — implemented in Phase 7
A quiz is reached through the curriculum item that owns it, not by its own id.
The item is the thing the learner navigates to and the thing authorization is
answered about, so making it the identifier keeps one gate instead of two.

```
# authoring — returns the correct answers, authorized against the course
GET    /studio/items/{item}/quiz                → {settings, questions}
PATCH  /studio/items/{item}/quiz                quiz settings
POST   /studio/items/{item}/quiz/questions
PATCH  /studio/items/{item}/quiz/questions/{question}
DELETE /studio/items/{item}/quiz/questions/{question}
PATCH  /studio/items/{item}/quiz/questions/order   {question_ids: [uuid, …]}

# taking — questions WITHOUT answers (ADR-06); the deadline is the server's
GET    /learn/items/{item}/quiz/attempts        history + attempts remaining
POST   /learn/items/{item}/quiz/attempts        starts, or resumes an open attempt
GET    /learn/quiz-attempts/{uuid}              {attempt, questions, answers}
PATCH  /learn/quiz-attempts/{uuid}/answers      autosave one answer
POST   /learn/quiz-attempts/{uuid}/submit       → graded, or awaiting_review
GET    /learn/quiz-attempts/{uuid}/result       respects show_correct_answers_after

# grading
GET    /studio/courses/{course}/grading?status=awaiting_review
GET    /studio/grading/{attempt}
POST   /studio/grading/{attempt}                {grades:[{question_id,points,feedback}]}
```

Answer payloads, by question type — the only shapes the API accepts:

```
single_choice / true_false / image_choice   {"option_id": 12}
multiple_choice                             {"option_ids": [1, 3]}
short_answer / long_answer                  {"text": "…"}
fill_blank                                  {"blanks": ["a", "b"]}
matching / image_matching                   {"pairs": {"<optionId>": "<matchKey>"}}
ordering                                    {"option_ids": [3, 1, 2]}
```

### Assignments — implemented in Phase 8
Reached through the curriculum item, the same as a quiz.

```
# authoring
GET    /studio/items/{item}/assignment
PATCH  /studio/items/{item}/assignment          settings + attachment_media_ids[]

# handing work in
GET    /learn/items/{item}/assignment           → {assignment, submissions, rules}
POST   /learn/items/{item}/assignment/submissions   {body?, media_ids[]}
```

`rules` is the object the submit form renders AND the submit Action enforces:
`can_submit`, `reason` (`no_attempts_left` | `past_due`), `attempts_used`,
`attempts_allowed`, `attempts_left`, `is_past_due`, `will_be_late`,
`late_penalty_percent`. File rules (`max_files`, `allowed_extensions`,
`max_file_size_kb`) fail as 422 with field details; attempts and the deadline
fail as 409 `submission_rejected`.

`media_ids` are the numeric `ref` from an upload, and must belong to the
caller in the `submission` collection — an id that merely exists is refused.

### Grading — one queue, both kinds
```
GET    /studio/courses/{course}/grading?status=awaiting_review|all
       → paginated rows: {kind: 'quiz'|'assignment', id, status, awaiting_review,
                          submitted_at, learner:{id,name}, item:{id,title}}
         filtered by what THIS grader may open, so no row 403s when clicked

GET    /studio/grading/quiz/{attempt}
POST   /studio/grading/quiz/{attempt}           {grades:[{question_id,points,feedback}]}
GET    /studio/grading/assignment/{submission}
POST   /studio/grading/assignment/{submission}  {points, feedback?}
POST   /studio/grading/assignment/{submission}/return   {feedback}
```

Returning hands work back without a mark and does **not** consume an attempt.
The late penalty is applied server-side on grading, from the `is_late` frozen
onto the submission — the grader always marks out of the full total.

### Assessment — later phases
```
GET    /studio/question-banks · POST · /questions
POST   /studio/items/{item}/quiz/questions/import-from-bank
POST   /learn/quiz-attempts/{uuid}/abandon
```

### Enrollment & access — live (P9)
```
GET    /studio/courses/{course}/students        roster: ?status= ?search= ?per_page=
POST   /studio/courses/{course}/enrollments     grant one seat {user_id|email, starts_at?, expires_at?}
POST   /studio/courses/{course}/enrollments/bulk  {emails: [...]}  ≤200, throttled
PATCH  /studio/enrollments/{enrollment}         {action: suspend|reinstate|extend|revoke}
PUT    /studio/courses/{course}/prerequisites   {course_ids: []}   whole set, never a delta
       drip fields on PATCH /studio/items/{item}
```

Bulk enrolment is **synchronous and bounded**, returning a verdict per row
(`enrolled` / `skipped` / `not_found`) rather than a job id: the useful answer
is which three addresses were typos, immediately. A queued CSV import belongs
with the rest of the import tooling.

`GET /enrollments` was dropped — `GET /learn/courses` already is that list.

**Sorting the roster by learner name is deliberately unavailable.** The name is
a central column and the rows are per-academy, so ordering by it cannot be one
query. Search works, scoped to the academy.

### Platform administration — live
```
GET    /admin/tenants                           ?status= ?search=
POST   /admin/tenants                           provision {slug, name, owner_*, plan?}
GET    /admin/tenants/{tenant}
PATCH  /admin/tenants/{tenant}                  {action: approve|reject|suspend|reactivate}
PUT    /admin/tenants/{tenant}/plan             {plan, period_ends_at?}  — also RENEWS
```

Central-DB only, behind `super_admin`, and deliberately **outside** both the
`tenant` and `subscription` middleware: a suspended or lapsed academy is
exactly the one an operator needs to reach, and renewing is the action that
unblocks it.

### Commerce — live (P10)

The money path is reachable. Coupons, refunds, tax, invoices and the
earnings/payout surface are **not** — see `ROADMAP.md` Phase 10 for why each
was deferred rather than half-built.

```
# live
GET    /cart                                    the caller's basket; an unmade one reads as empty
POST   /cart/items                              {product_id} (uuid)
DELETE /cart/items/{cartItem}                   404 for a line that is not yours
DELETE /cart                                    empties without deleting the basket
POST   /checkout                                → order, priced by the SERVER
POST   /orders/{order}/pay                      {gateway} → {redirect_url|client_secret}
GET    /orders                                  own orders, or all with `order.view.any`
GET    /orders/{order}
POST   /webhooks/payments/{gateway}/{tenant}    unauthenticated · signature-verified · idempotent
GET    /admin/payment-gateways                  every supported gateway, connected or not
PUT    /admin/payment-gateways/{gateway}        partial; omitted secrets are KEPT
DELETE /admin/payment-gateways/{gateway}        disconnect — deletes the row

# planned
POST   /cart/coupon · DELETE /cart/coupon
GET    /orders/{uuid}/invoice
POST   /admin/orders/{uuid}/refund
GET    /admin/coupons · POST · PATCH · DELETE
```

**The webhook is the only unauthenticated write in the system**, and three
omissions from its middleware are each load-bearing:

- no `auth:sanctum` — the caller is a payment provider with no account;
- no `tenant` — with no user there is nothing to resolve an academy from, so
  the academy is in the PATH and `tenant.webhook` opens it (the same problem
  the signed media download solved in §2a, with a different answer);
- no `subscription` — the money has already moved. Refusing a capture because
  the academy's own bill is overdue would take a learner's payment and grant
  them nothing.

`{tenant}` is attacker-controllable and that is fine: resolving it only opens a
connection, and everything downstream is gated on the signature verifying
against **that academy's** own secret. Naming somebody else's academy means
being checked against a key you do not hold. Unknown and closed academies both
404, identically, so the route cannot be used to enumerate academy ids.

**There is deliberately no client-callable "confirm payment" endpoint.**
A redirect back from a provider proves nothing, so the API offers no way to say
it happened. `POST /orders/{order}/pay` moves the order to `awaiting_payment`
and returns somewhere to send the learner; it grants nothing. Access arrives
only through the webhook (ADR-05), and `CheckoutApiTest` asserts that
`/confirm`, `/complete`, `/success` and `/capture` all 404.

**Checkout invariants.** `POST /cart/items` and `POST /checkout` ignore any
price in the request. Every line is re-read from `product_prices` in the
basket's currency at the moment the order is placed — which is why `cart_items`
stores no price. The basket's `estimated_total_minor` is labelled an estimate
because it is: the figure that charges is the one written onto the order.

**Currency.** A basket takes the platform's base currency when it is created
and never changes it. A product with no price in that currency cannot be
added — a 409, not a silent conversion. Multi-currency checkout is deferred.

**Gateway credentials are write-only.** No response ever contains them; the API
says only whether a gateway `is_connected` and `has_webhook_secret`. A partial
`PUT` keeps what it does not send, so toggling test mode cannot silently
disconnect a gateway.

### Media — live
```
POST   /media                                   multipart upload; server derives the real
                                                MIME from the bytes, not the filename
                                                → {id: uuid, ref: numeric, url, …}
GET    /media/{media}/url                       short-lived signed URL (access-checked)
GET    /media/{media}/download                  signed link; the signature IS the credential
DELETE /media/{media}
```

Every endpoint that *references* a file speaks in the numeric `ref`; the UUID
addresses the file itself. Direct-to-S3 presigned upload (ADR-09) is **not** built —
uploads currently stream through the API.

### Identity & Admin — live
```
GET    /account/profile · PATCH                 · POST /account/password
GET    /account/instructor-application · POST
GET    /admin/users · GET /admin/users/{user}
POST   /admin/users/{user}/suspension
GET    /admin/users/{user}/roles · POST · DELETE /…/roles/{role:key}
GET    /admin/instructors?status=pending
POST   /admin/instructors/{instructorProfile}/review   {decision, reason?}
GET    /admin/roles · GET /admin/permissions
GET    /health
```

### Certification — live (P11)
```
GET    /certificates                            mine
GET    /certificates/{uuid}                     · /download (signed PDF URL)
GET    /verify/{tenant}/{token}                 PUBLIC verification, no auth
GET    /admin/certificate-templates · POST · PATCH
```

### Engagement — live (P12)
```
GET    /courses/{id}/reviews · POST             one per learner: write OR replace
DELETE /reviews/{id} · POST /reviews/{id}/reply
GET    /admin/reviews · POST /admin/reviews/{id}/moderate

GET    /courses/{id}/discussions · POST
GET    /discussions/{id} · POST /discussions/{id}/replies
POST   /discussions/{id}/accept                 the asker, or course staff
PATCH  /discussions/{id}/moderate               hide · unhide · pin
DELETE /discussion-replies/{id}

GET    /courses/{id}/announcements · POST
PATCH  /announcements/{id} · DELETE
POST   /announcements/{id}/publish · DELETE     publishing is its own verb
GET    /wishlist · POST /wishlist/{courseId} · DELETE
```

There is deliberately no `PATCH /reviews/{id}`: one review per learner per
course means `POST` writes or replaces, and a second edit endpoint would be a
second thing to keep in step with that rule.

**Every engagement list says what the reader may DO with it**, computed from
the same rule the write endpoint enforces — so a page renders a form or an
explanation, never a button that 403s:

| Endpoint | `meta` |
|---|---|
| `GET /courses/{id}/reviews` | `can_review` — reviews enabled, and an enrolment of any status |
| `GET /courses/{id}/discussions` | `can_ask`, `can_moderate` |
| `GET /courses/{id}/announcements` | `can_manage` |

`GET /discussions/{id}` additionally carries a `viewer` block —
`can_reply`, `can_accept`, `can_moderate` — on the THREAD only. Each key is a
policy call resolving `CourseAccess`; a page of thirty threads would be ninety
of them, which is why the list answers once in its `meta` instead.

`GET /courses/{slug}` carries `is_wishlisted` for the same reason and with the
same limit: the detail resource only. On the catalogue list it would be a
query per card.

### Notifications — live (P12)
```
GET    /notifications?unread=1                  meta.unread_count rides along
GET    /notifications/unread-count              the polled badge, on its own
POST   /notifications/{uuid}/read               idempotent
POST   /notifications/read-all
DELETE /notifications/{uuid}

GET    /notification-preferences                the whole matrix, grouped
PUT    /notification-preferences                a LIST of changes, not the matrix
```

Four things about this surface are decisions rather than shape:

- **`action_path` is relative.** The SPA routes on it internally, and a stored
  absolute URL would rot the day an academy changes address.
- **A stranger's notification 404s**, never 403s. Every query starts from the
  caller's own id, so "this exists but is not yours" is a fact about somebody
  else's inbox.
- **`PUT /notification-preferences` takes only what moved.** Sending the whole
  matrix back makes every save a race between two open tabs.
- **The in-app channel cannot be switched off** — 422, not silently ignored.
  It is returned in the matrix as `locked: true` so the UI can render a
  disabled switch rather than a gap.

### Analytics — live (P13)
```
POST   /analytics/track                         a BATCH of client events, rate-limited

GET    /analytics/overview?from=&to=            KPIs + dense series + top courses
GET    /analytics/courses/{course}              one course over time
GET    /analytics/courses/{course}/funnel       per-item drop-off — no date range
GET    /analytics/instructors/{user}            own, or anybody's for a platform reader

GET    /analytics/export/platform               CSV
GET    /analytics/export/courses                CSV, scoped to what the caller may open
GET    /analytics/courses/{course}/export       CSV of the funnel
```

**There is deliberately no endpoint over `analytics_events`.** The log is a
write path and a rebuild source (ADR-08); every read here comes from a rollup.
Exposing the log would let one screen ask a question the rollups cannot
answer, which is two definitions of one metric a release later.

Six things about this surface are decisions rather than shape:

- **`POST /analytics/track` accepts only four names** — `course_viewed`,
  `item_started`, `search_performed`, `cart_abandoned` — and 422s everything
  else. That allowlist is the security boundary of the endpoint: a client that
  could post `payment_completed` would be writing revenue into the dashboards
  without paying anybody. Every other name is raised by a queued listener on a
  domain event, where it cannot be lied about.
- **It takes a batch, always**, so a beacon fired after a spell offline can
  carry each event's own `occurred_at`. That timestamp is **clamped**: anything
  in the future, or older than a day, becomes now. A device with a wrong year
  must not write into next month's report.
- **A day is a UTC day**, and every response says so in `range.timezone`
  rather than leaving a reader to assume their own.
- **Series are dense.** A day with no rollup row comes back as zeros, not
  missing, or a chart draws a straight line across the gap and reports
  activity that never happened.
- **`peak_daily_active` is not a sum.** Distinct people cannot be added across
  days without counting a regular five times over, so the API reports the
  busiest single day and names the field for what it is.
- **The range is capped at 366 days.** A dashboard that can ask for all of
  history can ask for a table scan, and nobody means to.

The three CSV endpoints are **the only responses in the API that are not
`{data: …}`** — a spreadsheet cannot unwrap an envelope. They stream, carry a
UTF-8 BOM (Excel on Windows reads a BOM-less file as the local codepage, which
turns every Bengali title into mojibake), and scope their rows to what the
caller may open rather than to a query parameter.

### Gamification — live (P14)
```
GET    /achievements                            your own points, badges, streak
PATCH  /achievements/ranking                    {is_ranked} — leaderboard opt-out

GET    /leaderboard?period=weekly|monthly|all_time
GET    /courses/{course}/leaderboard?period=…   enrolled learners only
```

**There is deliberately no endpoint for somebody else's profile.** The
leaderboard is the only place another person's points appear, and only for
people who did not opt out.

Five things about this surface are decisions rather than shape:

- **The board is a SNAPSHOT**, rebuilt hourly. `computed_at` is in the payload
  because a reader has to know how stale it is. Computing one on request is a
  sum over the whole ledger per page load, and it would reshuffle under
  somebody while they read it.
- **`me` is returned separately**, even when the caller is off the bottom of
  the board. "You are 137th" is the only thing on that screen useful to
  somebody outside the top fifty, and it cannot be worked out client-side when
  they are not in the payload.
- **Opting out excludes at the SOURCE**, so the ranks close up. Filtering a
  rendered board would leave a visible gap at position 4, which tells everybody
  exactly who opted out.
- **Weekly is the default period.** An all-time board nobody new can appear on
  stops being a competition and becomes a list of who joined early.
- **Unheld badges are returned with their requirement.** A shelf of only what
  you already hold is a trophy cabinet; hiding the requirement makes it a
  lottery.

### Settings — planned
```
GET    /admin/settings · PATCH /admin/settings
```

---

## 5. Rate limiting

| Group | Limit |
|---|---|
| `auth:login`, `auth:register`, `auth:forgot-password` | 5/min per IP **and** per identifier |
| `webhooks` | 300/min per gateway IP, signature-gated |
| `learn:watch` heartbeat | 1 per 15 s per item |
| `analytics:track` | 60/min per user |
| Authenticated default | 120/min per user |
| Unauthenticated default | 60/min per IP |

`429` returns `Retry-After` and `X-RateLimit-*`.

---

## 6. Idempotency

`POST /checkout`, `POST /orders/{uuid}/pay`, `POST /learn/quizzes/{id}/attempts`,
`POST /learn/assignments/{id}/submissions` accept `Idempotency-Key`. A repeat with the
same key and same body hash replays the stored response. A repeat with a different body
hash returns `409 idempotency_key_reused`.

---

## 7. What we are deliberately doing differently from Tutor

| Tutor | Orbito |
|---|---|
| 137 `wp_ajax_*` POST actions, many returning HTML | Versioned REST, JSON only, typed |
| No error contract | One envelope, stable machine codes |
| Nonce-coupled, cookie-only | Sanctum cookie *or* bearer token — mobile is first-class |
| No pagination contract | Offset + cursor, both documented |
| `menu_order` writes scattered across handlers | One reorder endpoint, one transaction |
| Quiz answers reachable before submission | Correct answers never serialised into an attempt |
| REST covers ~10 % of the product | Every product capability has an endpoint |

---

## 8. Documentation & contract testing

- OpenAPI 3.1 spec generated from routes + Form Requests + Resources; served at
  `/api/v1/openapi.json`, rendered at `/docs`.
- The spec is a **CI artifact**: a diff that removes or renames a field without a version
  bump fails the build.
- Postman/Bruno collection generated from the spec for manual testing.
- Every endpoint has at least three feature tests (200 / 403 / 422).
