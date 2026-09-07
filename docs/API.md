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
    "request_id": "01JB8Q…"
  }
}
```

| Status | When |
|---|---|
| 400 | Malformed request |
| 401 | Missing/invalid credentials |
| 403 | Authenticated but not permitted (policy denial) |
| 404 | Not found **or** not visible to this user (never leak existence) |
| 409 | State conflict (`attempt_already_submitted`, `already_enrolled`) |
| 422 | Validation failure — `details[]` is field-keyed |
| 423 | Locked (drip not yet unlocked, prerequisite unmet) |
| 429 | Rate limited (`Retry-After` header) |
| 500 | Unhandled — `request_id` correlates to logs |

`error.code` is a **stable machine string**. The frontend switches on `code`, never on
`message`. Messages are localised; codes are not.

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
# live — public
GET    /courses                      public catalogue; filters below
GET    /courses/{slug}               public detail, incl. preview-aware curriculum
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
POST   /learn/courses/{course}/reset-progress

# planned
GET    /learn/courses/{course}/resources
```

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

### Enrollment & Commerce — planned (P9, P10)
Only `POST /courses/{course}/enroll` exists today; it is listed under Learning above.

```
GET    /enrollments                             mine
GET    /studio/courses/{id}/students
POST   /studio/courses/{id}/enrollments         manual enroll {user_id|email}
POST   /studio/courses/{id}/enrollments/bulk    CSV → queued job + job status URL
PATCH  /studio/enrollments/{id}                 suspend / extend / cancel

GET    /cart · POST /cart/items · DELETE /cart/items/{id}
POST   /cart/coupon · DELETE /cart/coupon
POST   /checkout                                → order (server-priced)
POST   /orders/{uuid}/pay                       {gateway} → {redirect_url|client_secret}
GET    /orders · GET /orders/{uuid}
GET    /orders/{uuid}/invoice
POST   /webhooks/payments/{gateway}             unauthenticated, signature-verified, idempotent
POST   /admin/orders/{uuid}/refund
GET    /admin/coupons · POST · PATCH · DELETE
GET    /studio/earnings · GET /studio/payouts · POST /studio/payouts
```

**Checkout invariants.** `POST /checkout` ignores any price in the request. It reprices
every line from `product_prices` in the cart's currency, re-evaluates the coupon,
recomputes tax, and stores the result. `POST /orders/{uuid}/pay` never marks anything
paid — only the webhook path does.

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

### Certification, Engagement, Analytics, Settings — planned
```
GET    /certificates                            mine
GET    /certificates/{uuid}                     · /download (signed PDF URL)
GET    /verify/{token}                          PUBLIC verification, no auth
GET    /admin/certificate-templates · POST · PATCH

POST   /courses/{id}/reviews · PATCH /reviews/{id} · DELETE
POST   /reviews/{id}/reply                      instructor
POST   /admin/reviews/{id}/moderate             {status}
GET    /courses/{id}/discussions · POST
GET    /discussions/{id}/replies · POST
POST   /discussions/{id}/resolve
GET    /courses/{id}/announcements · POST (studio)
GET    /wishlist · POST /wishlist/{courseId} · DELETE

GET    /analytics/overview?from=&to=
GET    /analytics/enrollments · /revenue · /courses/{id} · /instructors/{id}
GET    /analytics/courses/{id}/funnel           per-item drop-off
POST   /analytics/track                         client-side events, rate-limited

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
