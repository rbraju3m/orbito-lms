# WEBHOOKS.md — Outbound webhooks

An academy can tell other systems — a CRM, a mailing list, an automation tool
like Zapier or Make, its own back office — what happens inside it, as it
happens. This is the integrator's reference: what arrives, how to check it came
from Orbito, and what to expect when things go wrong.

Design record: ADR-12 in `ARCHITECTURE_PROPOSAL.md`. Webhooks subscribe to the
domain-event catalogue (`EVENTS.md`) and invent no vocabulary of their own —
every topic below is exactly one domain event.

---

## 1. Who sets them up

The academy's **Super Admin** — `webhook.manage` is a system permission, held
by nobody else by default. An endpoint receives learners' names and email
addresses, so choosing where one points is deliberately not an everyday admin
task. Screen: **Admin → Webhooks** (`/admin/webhooks`). API: `docs/API.md` §
Webhooks.

An endpoint is a URL plus the topics it wants. The **signing secret** is shown
once, when the endpoint is created or its secret is rotated, and never again.

---

## 2. What arrives

A `POST` with a JSON body, the same envelope for every topic:

```json
{
  "id": "01a08c40-9cbd-7270-b80a-2541138aad04",
  "type": "enrollment.created",
  "created_at": "2026-09-10T09:14:03Z",
  "academy": { "id": "01j9…", "name": "Dhaka Writing School" },
  "data": { … }
}
```

| Header | Meaning |
|---|---|
| `Orbito-Signature` | `t=<unix seconds>,v1=<hex HMAC-SHA256>` — see §3 |
| `Orbito-Event` | the topic, same as `type` |
| `Orbito-Event-Id` | the event's id, same as `id` |
| `Orbito-Delivery` | this delivery's id — different on every redelivery |
| `User-Agent` | `Orbito-Webhooks/1` |

`id` is the **event's** id. Every endpoint that receives one event gets the
same id, and a redelivery reuses it — deduplicate on it.

---

## 3. Verifying a delivery

The signature is an HMAC-SHA256, keyed with the endpoint's secret, over the
timestamp, a dot, and the **raw** request body — hash the bytes you received,
before any JSON parsing.

1. Split `Orbito-Signature` into `t` and `v1`.
2. Reject if `t` is more than five minutes from your clock. The timestamp is
   inside the signed string, so a captured request cannot be replayed later
   with a fresh one.
3. Compute `HMAC-SHA256(secret, t + "." + body)` as lowercase hex.
4. Compare it to `v1` in constant time.

```php
[$t, $v1] = sscanf($_SERVER['HTTP_ORBITO_SIGNATURE'], 't=%d,v1=%s');
$body = file_get_contents('php://input');

$fresh = abs(time() - $t) <= 300;
$valid = hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $v1);
```

```js
const [, t, v1] = req.get('Orbito-Signature').match(/^t=(\d+),v1=([0-9a-f]{64})$/);
const expected = crypto.createHmac('sha256', secret).update(`${t}.${rawBody}`).digest('hex');

const fresh = Math.abs(Date.now() / 1000 - Number(t)) <= 300;
const valid = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(v1));
```

`v1` names the scheme, so a future `v2` can be sent beside it during a
migration. Ignore schemes you do not recognise.

**Rotating the secret takes effect at once** — there is no window in which both
work, so update your receiver immediately. Deliveries still queued are signed
when they are sent, with the new one.

---

## 4. Delivery

- **Answer 2xx within ten seconds.** Anything else — a 4xx, a 5xx, a timeout,
  a refused connection — is a failed attempt. Acknowledge quickly and do the
  work afterwards.
- **Redirects are not followed.** A 3xx is a failure. Point the endpoint at the
  final URL.
- **Retries:** eight attempts in all, waiting 1 min, 5 min, 30 min, 2 h, 6 h,
  12 h and 24 h between them — about 45 hours.
- **At least once, not exactly once.** A retry after a timeout can arrive after
  you processed the first try. Deduplicate on `id`.
- **Order is not guaranteed.** A retried `enrollment.created` can arrive after
  the `item.completed` that followed it. Use `created_at` when order matters.
- **An endpoint that keeps failing switches itself off.** After five deliveries
  in a row that each used up every attempt, it is disabled and the admin screen
  says why. Switching it back on resets the count. Nothing is sent to a
  switched-off endpoint, including retries already queued.
- **Redeliver** any delivery from the admin screen: the same event id and the
  same bytes, as a new delivery.
- **Send test event** delivers a `ping` — signed and logged like a real one.

Each academy's delivery log keeps the payload, the attempts and the receiver's
answer (truncated) for **30 days**, then it is deleted (`webhooks:prune`).

---

## 5. Where requests can go

Only to **https** URLs that resolve to **public** addresses. Private ranges,
loopback, link-local (including cloud metadata at 169.254.169.254),
carrier-grade NAT and reserved blocks are refused — when the endpoint is saved,
and again before every delivery, because a host name can be re-pointed after it
was approved. The connection is then pinned to the address that was checked, so
DNS cannot change the answer in between (`WebhookTarget`). Credentials in the
URL are refused; verify the signature instead.

`WEBHOOKS_ALLOW_PRIVATE_TARGETS=true` lets a developer point an endpoint at
their own machine, over http. It is ignored in production.

---

## 6. Topics

A person appears as `{"id", "name", "email"}`; a course as `{"id", "slug",
"title"}`; a curriculum item as `{"id", "type", "title"}`. Money is integer
minor units plus an ISO currency code, as everywhere in the API. Ids are UUIDs.

Fields are **added** over time, never renamed or removed. Ignore fields you do
not recognise.

| Topic | Domain event | `data` |
|---|---|---|
| `enrollment.created` | `CourseEnrolled` | `enrollment`, `learner`, `course` |
| `enrollment.suspended` | `EnrollmentSuspended` | as above, plus `reason` |
| `enrollment.reinstated` | `EnrollmentReinstated` | as `enrollment.created` |
| `enrollment.revoked` | `EnrollmentRevoked` | as `enrollment.created` |
| `enrollment.expired` | `EnrollmentExpired` | as `enrollment.created` — see §7 |
| `item.completed` | `ItemCompleted` | `enrollment_id`, `learner`, `course`, `item` |
| `course.completed` | `CourseCompleted` | as `enrollment.created` |
| `course.status_changed` | `CourseStatusChanged` | `course`, `from`, `to` (draft, pending, published, archived…) |
| `quiz.graded` | `QuizAttemptGraded` | `attempt_id`, `attempt_number`, `learner`, `course`, `item`, `passed`, `score {earned, total, percent}`, `submitted_at` |
| `assignment.submitted` | `AssignmentSubmitted` | `submission_id`, `attempt_number`, `is_late`, `submitted_at`, `learner`, `course`, `item` |
| `assignment.graded` | `AssignmentGraded` | as above, plus `passed`, `points_earned`, `late_penalty_points`, `graded_at` |
| `payment.captured` | `PaymentCaptured` | `order {id, number, total_minor, currency, items[]}`, `payment {id, gateway, amount_minor, currency, captured_at}`, `learner` |
| `certificate.issued` | `CertificateIssued` | `certificate {id, number, issued_at, expires_at}`, `learner`, `course` |
| `download.granted` | `DownloadGranted` | `download {id, slug, title}`, `learner`, `source`, `granted_at` |
| `review.published` | `ReviewPublished` | `review {id, rating, title, body, published_at}`, `learner`, `course` |

`enrollment` is `{id, status, source, enrolled_at, expires_at, completed_at}`.

The payload describes the moment the event fired. It is built then, stored, and
sent unchanged on every attempt — a retry does not re-read the database.

**Not offered, on purpose.** `CurriculumChanged`, `MediaUploaded` and the
gamification events are internal plumbing; `UserLoggedIn` would stream
everybody's sessions to a third party. A topic is added when somebody has a use
for it — as a `WebhookTopic` case, a method on `SendWebhooks`, a line in
`EventServiceProvider::$webhooks` and a row above.

---

## 7. Known limits

- **`enrollment.expired` comes from the nightly sweeper**, which fires its event
  inside a scheduled command walking every academy. `EVENTS.md` records that
  the test harness cannot observe listeners there, so this topic is the one
  whose delivery is not covered by a test end to end.
- **No secret overlap on rotation** (§3). Two valid secrets for a grace period
  is the obvious next step if an academy needs zero-downtime rotation.
- **No notification when an endpoint switches itself off.** The admin screen
  shows it; an in-app notification to the Super Admin would be the natural
  follow-up.
- **Not a plan feature yet.** Every academy can create endpoints. If webhooks
  become a paid tier, the cap belongs in `PlanLimits`, not here.
