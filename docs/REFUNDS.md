# REFUNDS.md — Refunds

Money given back on an order — all of it or part of it. Built in Phase 16
(FEATURE_MATRIX J8). Deferred at Phase 10 because the MVP needed one money path
proven, not a refund engine.

---

## 1. Who, and how

`order.refund` — Admin and Super Admin. **Not the learner**, even on their own
order: a learner asks, the academy decides. On the order page (`/orders/:id`)
staff see a **Refund…** dialog; everybody sees the refunds already made.

Two ways the money moves (`RefundMethod`):

| Method | What happens |
|---|---|
| `gateway` | Sent back through the gateway that took the payment (`PaymentGateway::refund`) — or made in the provider's own dashboard and reported by its webhook (§6). ⚠ Stripe's refund call has never reached Stripe, like the rest of `StripeGateway`. |
| `external` | Already given back somewhere else — a bank transfer, cash — and **recorded** here so the books and the access match. Nothing is called. Not for a refund made in the provider's dashboard: that arrives by webhook, and recording it as well counts it twice. |

A free order (a coupon took it to zero) has nothing to refund. An order settled
by hand has no captured payment, so only `external` works on it.

---

## 2. Claim, move, complete

`RefundOrder` does three things, in this order:

1. **Claim** (`ClaimRefund`) — behind a lock on the ORDER row, count what is
   still refundable and write the refund `pending`, with its split (§4). A
   pending refund holds its amount, so two admins — or one double-click —
   cannot both give back the last of it. Written before anything leaves, the
   same order a payment row is written before the handoff.
2. **Move** — call the gateway, with the refund's own id as the idempotency
   key, or, for `external`, nothing.
3. **Complete** (`CompleteRefund`) — mark it `completed`, add it to
   `orders.refunded_minor`, and move the order to `partially_refunded` or
   `refunded`. Idempotent: completing twice changes nothing.

A provider that **refuses** leaves the refund `failed` — kept, with the
provider's reason, and its amount freed to refund again — and the admin gets
`503 gateway_unavailable`. One that **accepts without settling** (Stripe
reports some methods `pending`) leaves it `pending`, still holding its amount,
until the provider's refund webhook settles it (§6).

`422 refund_rejected` carries `meta.reason`: `not_paid`, `nothing_left`,
`too_much` (with `refundable_minor`), `no_payment`.

---

## 3. Access

**A full refund takes away what THAT order granted** — the enrolments it
created (`source_id` is the order, for a purchase and for every course of a
bundle) and the downloads it granted (`revoked_at`) — unless the admin unticks
"take away the courses and downloads" for a goodwill refund. It never touches
access from anywhere else: a seat an admin gave, a course bought separately, a
course the learner already had when a bundle's overlap was delivered
(`RevokeOrderAccess`).

**A partial refund never touches access.** Only the refund that empties the
order can revoke (`revokes_access` is stored false on any other), so a run of
partials that adds up to everything revokes on the last one, if it says to.

**A full refund made in the provider's dashboard revokes too**, as the
dialog's default does — nobody here was asked whether it was goodwill, and an
admin can enrol the learner again. A partial one, again, never touches access.

Revoked, not deleted: progress and grades stay, and buying again later works.

---

## 4. The money in the reports

A refund is split across the order's lines **by what each line has left** —
not by what it cost, because after one partial refund the lines no longer hold
money in their original proportions — and a bundle line's share across its
courses the same way (`RefundSplit`, largest remainder all the way down). A run
of partial refunds that adds up to the order lands on exactly zero on every
line and every bundle course.

Revenue reports take a refund off **on the day it completed**, never on the day
of the sale — an old report does not change. A day's `revenue_minor` is
therefore NET, and a quiet day with a refund can be negative (the rollup
columns are signed for it). Per course, per bundle share, per download line and
platform-wide, the same way — so courses plus downloads still equal the
platform total on every day, refunds included.

---

## 5. Coupons and events

- A **fully** refunded order is no longer a sale (`OrderStatus::sales()`), so
  it gives its coupon use back. A partly refunded one keeps it.
- `RefundIssued` fires when a refund COMPLETES, carrying whether it emptied the
  order. Its consumer is the `refund.issued` webhook (WEBHOOKS.md). Revenue does
  not listen: money comes from the ledger, never from an event.

---

## 6. Refunds the provider reports

Stripe reports refunds with `refund.created`, `refund.updated` and
`refund.failed` — one refund object each. (`charge.refunded` carries the
charge instead, and Stripe's own reference says to listen for these.) The
Payments screen lists them beside the endpoint's URL; setup is in
`RUNNING.md`. They arrive through the same endpoint and the same `HandleWebhook`
as payments — signature first, the event recorded once, the payment matched by
the id WE stored — and only then `ReconcileProviderRefund`.

- **Ours, settled later.** A refund asked for here carries its uuid to Stripe
  (`metadata[refund_uuid]`, and the idempotency key), so its report finds the
  row even if it lands before Stripe's id is stored on it. A pending one
  completes on `succeeded` and fails — freeing its amount — on `failed` or
  `canceled`.
- **Made in the dashboard.** A refund with no row here is claimed like any
  other — the same lock, the same split, the same "only what is left" — held
  while Stripe says pending and completed when it succeeds. `method` is
  `gateway`, `requested_by` is null, and the learner reads "Refunded through
  Stripe." A full one revokes (§3); a partial one does not.
- **Once, however many events.** One refund is described by several events in
  any order. A unique index on `refunds.external_id` makes "recorded once" a
  constraint, and a late `pending` after `succeeded` changes nothing.
- **Left for a person.** A report the books cannot absorb is recorded and not
  acted on — its webhook event stays unprocessed, with a warning in the log:
  more than the order has left (usually a dashboard refund that was *also*
  recorded by hand), a different amount or currency than the refund it names,
  money given back on a refund recorded here as failed (a gateway call that
  timed out after Stripe had acted), or a completed refund Stripe has since
  failed. Undoing either of the last two re-decides access and revenue, which
  is a person's call.
- **Refund reports** (`/admin/refund-reports`, `order.refund`) lists what is
  still open: what happened and what to check — both the server's words,
  from `RefundAttentionReason` — what the provider reported, and the order.
  **Mark resolved** records who looked and what they did, once; it changes no
  money and no access. The fix itself — a refund recorded, a learner refunded
  again — goes through the order's refund dialog like any other. An event
  about a payment we never issued is unprocessed too, and is not listed:
  noise, not work.

⚠ Written to Stripe's documented objects, like the rest of `StripeGateway`; no
real Stripe event has been through it.

---

## 7. Not built

- **Refunding a free order's access** — there is no money, so it is a revoke,
  not a refund. Staff suspend the enrolment instead.
- **Per-line refunds** ("refund just this course"). A refund is an amount; the
  split is proportional. Choosing lines is its own slice.
- **Tax and invoices** — a credit note belongs with invoices, which are not built.
