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
POST   /auth/register            {name,email,password,role_intent?}
POST   /auth/login               {email,password,device_name?}   → user + (token for mobile)
POST   /auth/logout
POST   /auth/refresh                                             (token rotation, mobile)
POST   /auth/forgot-password     {email}
POST   /auth/reset-password      {token,email,password}
POST   /auth/email/verify/{id}/{hash}
POST   /auth/email/resend
GET    /auth/me                                                  → user + effective permissions
GET    /auth/devices             · DELETE /auth/devices/{id}
POST   /auth/two-factor/enable   · /confirm · /disable            (P19)
```

`GET /auth/me` returns the user **and their resolved permission keys**, so the SPA can
hide UI it may not use. The server still enforces every one of them independently.

---

## 4. Domain surface

### Catalog
```
GET    /courses                      public catalogue; filters below
GET    /courses/{slug}               public detail (marketing view)
GET    /courses/{id}/curriculum      preview-aware; locked items return metadata only
GET    /courses/{id}/instructors
GET    /courses/{id}/reviews
GET    /categories · GET /categories/{slug}
GET    /tags

# authoring
POST   /studio/courses
GET    /studio/courses                 my/managed courses
GET    /studio/courses/{id}            full authoring payload
PATCH  /studio/courses/{id}
DELETE /studio/courses/{id}
POST   /studio/courses/{id}/publish
POST   /studio/courses/{id}/submit-review
POST   /studio/courses/{id}/archive
POST   /studio/courses/{id}/duplicate
PATCH  /studio/courses/{id}/settings
POST   /studio/courses/{id}/instructors      · DELETE /…/instructors/{userId}
```

Catalogue filters (documented, stable, all optional):
`?q=&category=&tags[]=&level=&language=&price=free|paid&min_rating=&instructor=&sort=popular|newest|rating|price_asc|price_desc&page=&per_page=`

### Curriculum
```
GET    /studio/courses/{id}/sections
POST   /studio/courses/{id}/sections            · PATCH /sections/{id} · DELETE /sections/{id}
POST   /studio/courses/{id}/items               {section_id, type, title}
PATCH  /studio/items/{id}                       inline rename, preview flag, drip
DELETE /studio/items/{id}
POST   /studio/items/{id}/duplicate
PATCH  /studio/courses/{id}/curriculum/order    ← the ONLY reorder endpoint
GET    /studio/lessons/{id} · PATCH /studio/lessons/{id}
POST   /studio/items/{id}/attachments · DELETE /studio/attachments/{id}
```

`PATCH …/curriculum/order` body:
```json
{ "sections": [ { "id": 3, "position": 0, "item_ids": [11, 9, 14] } ] }
```
Validated as a permutation of the course's current set; applied in one transaction;
returns the new tree. Idempotent.

### Learning (the player)
```
GET    /learn/courses                       my enrolled courses + progress
GET    /learn/courses/{id}                  player bootstrap: course, curriculum, progress, access
GET    /learn/items/{id}                    item content — 403/423 if not accessible
POST   /learn/items/{id}/complete
DELETE /learn/items/{id}/complete           un-complete (flexible mode)
POST   /learn/items/{id}/watch              {position_seconds, max_seconds}  throttled
GET    /learn/items/{id}/next               resolves prev/next across sections
GET    /learn/courses/{id}/notes  · POST · PATCH /learn/notes/{id} · DELETE
GET    /learn/courses/{id}/resources
POST   /learn/courses/{id}/complete
POST   /learn/courses/{id}/reset-progress
```

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

### Assessment — later phases
```
GET    /studio/question-banks · POST · /questions
POST   /studio/items/{item}/quiz/questions/import-from-bank
POST   /learn/quiz-attempts/{uuid}/abandon

# assignments
GET/POST/PATCH/DELETE  /studio/assignments/{id}
GET    /learn/assignments/{id}
POST   /learn/assignments/{id}/submissions      {body?, media_ids[]}
GET    /learn/submissions/{uuid}
GET    /studio/submissions?status=&course_id=
POST   /studio/submissions/{uuid}/grade         {points, feedback}
POST   /studio/submissions/{uuid}/return
```

### Enrollment & Commerce
```
POST   /courses/{id}/enroll                     free courses only; 402 if paid
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

### Certification, Engagement, Media, Analytics, Admin
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

POST   /media/upload-url                        presigned direct upload
POST   /media                                   confirm + record metadata
GET    /media/{uuid}/url                        short-lived signed URL (access-checked)
DELETE /media/{uuid}

GET    /analytics/overview?from=&to=
GET    /analytics/enrollments · /revenue · /courses/{id} · /instructors/{id}
GET    /analytics/courses/{id}/funnel           per-item drop-off
POST   /analytics/track                         client-side events, rate-limited

GET    /admin/users · PATCH /admin/users/{id}
POST   /admin/users/{id}/roles · DELETE /admin/users/{id}/roles/{roleId}
GET    /admin/instructors?status=pending
POST   /admin/instructors/{id}/approve · /reject · /block
GET    /admin/roles · /permissions
GET    /admin/settings · PATCH /admin/settings
GET    /health
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
