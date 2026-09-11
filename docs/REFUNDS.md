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
| `gateway` | Sent back through the gateway that took the payment (`PaymentGateway::refund`). ⚠ Stripe's refund call has never reached Stripe, like the rest of `StripeGateway`. |
| `external` | Already given back somewhere else — the provider's dashboard, a bank transfer, cash — and **recorded** here so the books and the access match. Nothing is called. |

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
reports some methods `pending`) leaves it `pending`, still holding its amount
(§6).

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

## 6. Not built

- **Provider refund webhooks.** A refund made in Stripe's dashboard is invisible
  here until it is recorded as `external`, and a gateway refund Stripe reports
  `pending` stays pending until an operator records the outcome. A
  `charge.refunded` handler that arrives at `CompleteRefund` is the fix.
- **Refunding a free order's access** — there is no money, so it is a revoke,
  not a refund. Staff suspend the enrolment instead.
- **Per-line refunds** ("refund just this course"). A refund is an amount; the
  split is proportional. Choosing lines is its own slice.
- **Tax and invoices** — a credit note belongs with invoices, which are not built.
