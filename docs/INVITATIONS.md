# INVITATIONS.md — Inviting people by email (A18)

An academy's staff type an address and choose **Student** or **Instructor**.
The address gets a link; following it, the person chooses a name and a
password and arrives signed in. The account starts verified, and it has the
role the invitation named.

This is what `RegistrationMode::Invite` was declared for in the tenancy
retrofit. That mode is now selectable at `/admin/academy`.

Code: `app/Domain/Identity` (`Invitation`, `SendInvitation`,
`ResendInvitation`, `RevokeInvitation`, `AcceptInvitation`,
`InvitationMailer`), `Identity\InvitationController`,
`PublicSite\InvitationController`, `Auth\AcceptInvitationController`,
`web/src/features/admin/routes/InvitationsRoute.tsx`,
`web/src/features/auth/routes/AcceptInvitationRoute.tsx`.

---

## 1. Decisions

| | |
|---|---|
| **Who invites** | Staff holding `invitation.manage` — Admin and Super Admin. It is one key for the list and every write. Seeing who has been invited and inviting somebody need the same trust, because either one tells you the other |
| **What it grants** | `student` or `instructor`, and nothing else. Admin and Staff are powers somebody should be given by a person looking at their account, from the roles screen. A link can be forwarded, so it should not carry those powers |
| **In which modes** | **Every mode.** An invitation is the academy's own deliberate act. *Invitation only* means signing up *without* one is refused. *Closed* still honours invitations, since besides an admin creating the account it is the only way in |
| **An address that already has an account** | Refused when staff try to invite it, as a 422 on `email`. One account belongs to one academy (§ Multi-tenancy), and a link that fails in the invitee's hands is worse. This tells the inviter the address is registered on the platform, but only staff holding the permission can ask |
| **An instructor's plan seat** | An open instructor invitation **holds** a seat, so a plan with one seat left cannot hand out five links and let all five in. The seat is checked when the invitation is sent, not when it is accepted: the invitee cannot change the academy's plan (§ Patterns established in Phase 16, *cap the party who can do something about it*). Accepting goes through `ReviewInstructorApplication` with `seatHeld: true`, which is still the one place the Instructor role is granted, so the seat counter moves like any approval |

---

## 2. The link

- **The token is never stored.** Only its SHA-256 is (`token_hash`). The row
  is read by staff, and a column holding the credential would give every
  admin a working link to sign up as somebody else. The plain token exists
  once, in the mail `InvitationMailer` queues.
- **It rides in a GET only to reach the SPA.** The mail link is
  `/invite?academy=…&token=…`. The SPA POSTs the token both to read what the
  link is for and to accept it. The API never accepts a credential in a URL,
  because proxies and access logs record URLs.
- **One open invitation per address**, enforced by a constraint:
  `pending_email` is UNIQUE while an invitation is open and NULL once it is
  settled. Inviting the address again **re-issues** the open row with a new
  role, link and expiry. It does not add a second row: two live links would
  each grant a role, and revoking one would leave the other working.
- **Re-sending rotates the token.** The old link stops working in the same
  save, which covers both "I lost the mail" and "I forwarded it by mistake".
- **Expiry comes from the clock.** Links last `orbito.invitations.ttl_days`
  (14). The status is derived from the row and the clock
  (`Invitation::status()`, with `scopeWithStatus` written as the same rule),
  so nothing has to sweep expired invitations.

---

## 3. Accepting

`AcceptInvitation` does not make the account itself. `RegisterUser` does, as
for every account, and what an invitation adds runs as its `$first` step,
inside the academy and under the same rollback:

1. **Claim** the row with a conditional UPDATE (`accepted_at IS NULL AND
   revoked_at IS NULL AND expires_at > now`). If two tabs follow one link,
   exactly one claims it; the other's account is deleted by `RegisterUser`
   when the claim throws. A revoke that lands first wins the same way.
2. For an instructor, create the profile (`application_source =
   invitation`) and approve it through `ReviewInstructorApplication`, with
   the inviter as reviewer.

The address comes from the **invitation**, never from the request body.
Following the link proved that mailbox, which is also why the account starts
verified and no verification mail is sent.

The refusals are told apart. On the lead form that would be an oracle; here
it is safe, because only the holder of the token can ask, and what they
should do depends on the reason:

| Code | Status | Meaning, and what the page offers |
|---|---|---|
| `invitation_invalid` | 404 | No such link. Check the whole link was opened |
| `invitation_expired` | 410 | Ask the academy for a new one |
| `invitation_revoked` | 410 | Withdrawn. Contact the academy |
| `invitation_accepted` | 410 | Already used. Sign in |
| `account_exists` | 409 | The address signed up since it was invited. Sign in |
| `registration_not_open` | 403 | No such academy, or it is not open. The **mode** is not checked (§1) |

---

## 4. Endpoints

### Staff — `/api/v1/admin` (`auth:sanctum`, `tenant`, `subscription`)

| Method | Path | Notes |
|---|---|---|
| `GET` | `invitations?status=&q=&page=` | Newest first (`created_at`, then `id`). `status` is `pending` \| `expired` \| `accepted` \| `revoked`, derived |
| `POST` | `invitations` | `{email, role}` → 201. Re-issues an open invitation for the same address. 422 when the address has an account; 402 `plan_limit_reached` when an instructor invitation has no seat. `throttle:invitations` |
| `POST` | `invitations/{uuid}/resend` | A new link. 409 `invitation_closed` once accepted or revoked, or when the address has since made an account. `throttle:invitations` |
| `POST` | `invitations/{uuid}/revoke` | A POST, not a DELETE: the row stays as the record of who was asked, and the address is freed to be invited again |

Each invitation carries `can_resend` / `can_revoke`, computed from the same
`status()` the actions check, so the screen never draws a button that
would 409. It never carries the token or the hash.

`throttle:invitations` allows `orbito.invitations.per_hour` (60) per member
of staff, because each invitation is a mail to an address somebody typed.

### The invitee

**`POST /api/v1/public/{academy}/invitations/show`** `{token}` →
`{email, role, role_label, academy_name, expires_at}`. It is on the public
surface because the reader has no account yet, and without the token it
gives a stranger nothing: the token is the credential, as on the guest-place
pages. It uses a different resource from the staff side (ADR-06), with
nothing about who sent it or how often.

**`POST /api/v1/auth/invitations/accept`** `{academy, token, name, password,
password_confirmation, device_name?}` → 201 with the same session payload
as `register`. It sits beside register under `throttle:auth`, and the
academy is resolved by `ResolveSignupAcademy::forInvitation()`, which checks
that it exists and is open but ignores its signup mode.

---

## 5. Events

`InvitationSent` (on send and re-send), `InvitationRevoked`, and
`InvitationAccepted`, which fires alongside the `UserRegistered` that every
new account fires. No listeners yet, and no webhook topic.

---

## 6. Known limits, deliberately left

- **No bulk invite.** One address per request. A CSV upload is its own
  slice, with a rate story of its own: a hundred mails from one click.
- **No personal message** in the mail. The text is fixed; a free-text box
  mailed to an address somebody typed would make the academy's mail
  reputation a relay.
- **No `member.invited` webhook.** Add a topic if a CRM asks for one.
- **An invited instructor does not get a course-scoped role.** Inviting
  somebody as a TA on one course is a different feature. It would need a
  scope on the invitation, and the roles screen already grants scoped
  roles to existing accounts.
- **Existing academies need the new key synced.** `permissions:sync` works
  on the schema it runs against, so reaching every academy is
  `php artisan tenants:run permissions:sync`. Until then an academy's Admin
  does not hold `invitation.manage`, but Super Admin still does, through
  `Gate::before`. New academies get the key from `TenantDatabaseSeeder`.
