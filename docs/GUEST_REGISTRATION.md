# GUEST_REGISTRATION.md — A place at a webinar with no account

A visitor on an academy's public site can hold a place at a FREE webinar with
an email address and nothing else. They confirm it from their inbox, and every
email afterwards carries a link to a page where they can see the place, join
the meeting and give the place up.

It is the **second anonymous write** in the product, after the lead form
(`LEADS.md`), and it is harder than the first for one reason: it has to send
mail to the address a stranger typed — exactly the abuse `LEADS.md` §2 refuses.
The design below is built around that.

Code: `app/Domain/Live/Actions/{RequestGuestRegistration, ConfirmGuestRegistration,
FindGuestPlace, CancelGuestPlace, JoinAsGuest}`, `Live/Support/{GuestToken,
GuestLinks}`, `Live/Notifications/GuestMail`, `PublicSite\GuestRegistrationController`,
`web/src/features/publicsite/{components/GuestRegistrationForm, routes/GuestConfirmRoute,
routes/GuestPlaceRoute}`.

---

## 1. The flow

```
event page ──POST guest-registrations──▶ 202 "check your inbox"     (writes NOTHING)
                                          └─ mail: "Confirm your place" + confirmation link
inbox ──link──▶ /a/{academy}/webinars/{slug}/confirm?token=…
               ──POST guest-registrations/confirm {token}──▶ 201 place   (holds the place)
                                          └─ mail: "You are registered" + manage link
reminder / called off ──mail──▶ manage link
manage link ──▶ /a/{academy}/webinars/{slug}/place?token=…
               ──POST guest-places/show | cancel | join {token}
```

- **Free events only.** A paid place is bought, buying needs an account, and
  the request says so plainly — whether a place is sold is on the public page
  already (409 `webinar_guest_not_allowed`).
- **Confirming is registering.** `RegisterForWebinar::forGuest()` takes the
  same transaction and the same lock on the webinar as a member's registration,
  so a guest and a member racing for the last place cannot both have it, and
  the closed/full refusals are the member's refusals.
- **One place per person.** Registrations are unique on (webinar, email). When
  somebody who held a place as a guest later signs in with that address and
  registers, the existing row becomes theirs (`user_id` is attached) rather
  than a second place being taken.

---

## 2. The abuse story

| Layer | What it stops | Where |
|---|---|---|
| **The mailbox is the write** | Asking writes NOTHING. A place exists only once somebody who can read the mailbox follows the link. A stranger cannot book a place in somebody else's name, fill the table, or put a person on an event's roster — and so on its reminder and cancellation mail | `RequestGuestRegistration` |
| **One answer for every request** | A new address, one already holding a place, one over its mail cap and a tripped trap all get `202 {"data":{"received":true}}`. Anything more specific tells a stranger whose address holds a place | `GuestRegistrationController::store` |
| **A silent mail cap per address** | No more than 3 mails to one address per academy per hour, whatever the IP. SILENT, because a 429 on an address would confirm it had been asked for — and would let a stranger lock the real owner out by asking first | `orbito.guest_registration.mails_per_address`, `mail_window_minutes` |
| **Per-IP limits** | 5/min and 30/day | the `guest-registrations` limiter |
| **The public form token and the honeypot** | The same layers as the lead form: fetch first (`GET form-token`), wait, one academy's token only; an off-screen `website` field | `PublicFormToken`, `HoneypotField` |
| **Encrypted, purpose-bound tokens** | A confirmation token cannot be replayed as a manage token or used on another academy's site; neither says anything readable. A confirmation lasts 24 h; a manage link until a day after the event ends | `GuestToken` |
| **Tokens never ride in a GET the API accepts** | Mail links carry the token to the SPA in a query string; the SPA POSTs it. Proxies and access logs record URLs | `GuestTokenRequest` |
| **Plain answers only behind a token** | Full, closed, expired and not-yet-joinable are said plainly — but only to somebody holding a token from the mail, who is the address's owner | the confirm and place endpoints |

What a stranger CAN still do is make an academy send up to three emails an hour
to an address — each saying nothing was booked and nothing more will come
unless they click. That is the irreducible cost of a form that confirms by
email; the cap is what bounds it.

---

## 3. Endpoints — `/api/v1/public/{academy}` (`tenant.public`)

| Method | Path | Body | Answer |
|---|---|---|---|
| `GET` | `form-token` | — | `{token}`, `Cache-Control: no-store` |
| `POST` | `webinars/{slug}/guest-registrations` | `email`, `name?`, `form_token`, `website` (honeypot) | `202 {received: true}` always. `404` unpublished; `409 webinar_guest_not_allowed` paid; `422` a bad field or a stale form token |
| `POST` | `guest-registrations/confirm` | `token` | `201` a place. `422 guest_link_invalid`; `409 webinar_full` / `webinar_closed` |
| `POST` | `guest-places/show` | `token` | `200` a place |
| `POST` | `guest-places/cancel` | `token` | `200` a place, cancelled. `409 webinar_place_purchased` for a bought place |
| `POST` | `guest-places/join` | `token` | `200 {join_url}`. `422 live_session_not_joinable` outside the window or once given up |

A place:

```json
{
  "status": "registered", "email": "ada@example.test", "name": "Ada",
  "can_cancel": true, "can_join": false,
  "token": "…the manage token…",
  "webinar": { "…": "the same WebinarResource the public event page renders" }
}
```

`can_cancel` and `can_join` come from the rules `CancelGuestPlace` and
`JoinAsGuest` enforce, so the manage page never draws a button that would be
refused.

---

## 4. What a guest is told, and how

A guest has no account, so no bell and no preferences. Every message is a
`GuestMail`, sent on demand to the address. `DomainNotification` is not used:
its `via()` asks preferences keyed on a user id a guest does not have.

| When | Mail | Link |
|---|---|---|
| They ask | "Confirm your place at …" — or, if the address already holds one, "Your place at …" | confirmation / manage |
| They confirm (the first time) | "You are registered for …" | manage |
| Shortly before it starts (`live:remind`) | "… starts soon" | manage — which is how they join |
| It is called off (`NotifyOnWebinarCancelled`) | "… has been called off" | none: there is nothing left to manage |

The manage link is how a guest gets out. Every mail about a held place carries
it.

`SessionAudience::guestsForSession()` answers who those guests are, beside
`forSession()` and under the same rules — nobody at a course session is a
guest, and nobody is expected at an event that was called off — so the
reminder and the cancellation notice cannot disagree with the roster about who
is coming.

---

## 5. Joining

The same window a member gets (`LiveSession::isJoinable()`, fifteen minutes
early) and the same refusal outside it. The manage page fetches the meeting
link on request and shows it as a link to open; it is never embedded in a mail.

---

## 6. Known limits, deliberately left

- **A guest's attendance is not recorded.** `session_attendance` is keyed on a
  central user id and a guest has none. The roster counts members only.
- **A place is adopted by an account only when that member registers** for the
  same event. No `UserRegistered` listener walks a new account's address across
  webinars to attach earlier guest places.
- **Paid places need an account.** Buying one as a guest would mean a checkout
  with no account behind it and an order nobody can be refunded through.
- **No CAPTCHA provider**, as with leads (`LEADS.md` §6).
- **The mail cap is per academy.** One address can receive mail from several
  academies that each allow three an hour.
- **A reminder goes to guests and members in the same sweep**, claimed by the
  same `reminder_sent_at`. A crash between the member notices and the guest
  mails under-notifies the guests — the same trade the reminder already makes.
