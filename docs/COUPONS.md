# COUPONS.md — Coupons

Codes an academy hands out for money off: a launch week, a partner, a
returning student. Built in Phase 16 (FEATURE_MATRIX J9). Tutor's coupon model
was the functional reference; its mistakes are named in §6.

---

## 1. What a coupon is

| Field | Meaning |
|---|---|
| `code` | What a learner types. Stored uppercase; matched however it is typed. Letters, digits, `-`, `_`. |
| `discount_type` | `percent` (`percent_off`, 1–100) or `fixed` (`amount_off_minor` in `currency`) |
| `currency` | Required for a fixed amount or a minimum spend — money without a currency is a number. A percent coupon with no minimum works in any currency. |
| `applies_to_all` | Or only the chosen products (`coupon_products`) — courses, bundles, downloads |
| `min_subtotal_minor` | Spend needed on what it applies to — not on the whole basket |
| `max_redemptions` / `max_redemptions_per_user` | Blank for no limit |
| `starts_at` / `ends_at` | The window it can be used in |
| `is_active` | Switched off, it is refused everywhere |

One coupon per order. Code-entered only; automatic discounts are not built —
a sale price already covers "everybody gets it".

Managed by `coupon.manage` — Admin and Super Admin. A coupon is a decision
about the academy's prices, so an instructor cannot discount their own course.
Screen: **Admin → Coupons** (`/admin/coupons`).

---

## 2. One set of rules, asked twice

`CouponRules` answers "does this coupon apply to this basket, for this person,
now — and for how much?". The basket asks it on every read, for a preview and
a reason; `PlaceOrder` asks it again at checkout, under a lock, to enforce. One
class, so the page and the order cannot disagree — the same shape as
`PublishChecklist` and `SubmissionRules`.

The cart stores only a POINTER to the coupon. A coupon that stops applying
while it sits there (it expired, a line was removed, the last use went) shows
its reason, turns `is_checkoutable` false, and checkout refuses with
`422 coupon_rejected` rather than charge a price the learner was not shown.

`error.meta.reason` is one of `not_found`, `inactive`, `not_started`,
`expired`, `wrong_currency`, `nothing_eligible`, `below_minimum`, `exhausted`,
`already_used`.

---

## 3. The money

- **Computed once, on what it applies to**, then **split across those lines**
  by largest remainder, weighted by each line's amount (`CouponDiscount`,
  over `RevenueAllocator`). The shares sum to the discount exactly.
- **Percent rounds down, once** — on the total, never per line. A fixed amount
  never exceeds what it applies to: a 20-off coupon on a 15 basket makes it
  free, not a 5 credit.
- **Every order line is net of its share.** `order_items.discount_minor` holds
  the share; `total_minor` is `unit_amount_minor − discount_minor`. Every
  revenue figure sums lines — per course, bundle allocations, the downloads
  line — so they still equal the order total to the minor unit
  (`CouponRevenueTest`). A discounted bundle's courses share its NET line.
- **Frozen at checkout.** The order keeps `coupon_code` as typed and every
  line's discount. Editing or deleting the coupon later changes no receipt.

---

## 4. Limits, and when a use counts

A redemption row is written when the order is PLACED. It counts towards a limit
while its order is **paid**, or **unpaid and younger than the reservation
window** (`COUPON_RESERVATION_MINUTES`, 60). A cancelled, failed or abandoned
checkout gives its use back simply by being those things — derived from the
clock, never swept.

The count and the insert happen in one transaction, behind `lockForUpdate()`
on the coupon's row, so two learners racing for the last use cannot both get
it. The one overshoot allowed: an order paid AFTER its window, once somebody
else has taken the freed use. The money has moved by then, so it is honoured.

The admin list's `times_used` counts **paid** orders only — the figure an
academy reports on. `state` (active, scheduled, expired, used up, switched
off) is derived the same way, never stored.

---

## 5. Free orders

An order a coupon takes to zero completes at checkout, with no gateway
(`CompleteFreeOrder`). ADR-05 says access waits for a verified webhook because
the client must never be believed about money; here there is none — the
SERVER priced it at zero, under a lock. It is delivered by `GrantOrderAccess`,
the same code a captured payment uses. No `PaymentCaptured` fires: nothing was
captured, and revenue analytics and the `payment.captured` webhook would both
report a payment of nothing. The enrolments fire their own events.

---

## 6. What Tutor does and we do not

- **Tutor keys coupon usage on the CODE** (`wp_tutor_coupon_usages.coupon_code`),
  a mutable business key — rename a coupon and its history is orphaned. Every
  reference here is the coupon's id; the code is snapshotted onto the order.
- **A used coupon cannot be deleted** (`409 coupon_in_use`) — it is part of an
  order's record, so it is switched off instead. A RESTRICT foreign key on
  `coupon_redemptions` is the floor under that check.

---

## 7. API

```
POST   /cart/coupon {code}          apply — 422 coupon_rejected with meta.reason; throttled 10/min
DELETE /cart/coupon                 remove
GET    /admin/coupons               list, with times_used and state (coupon.manage)
POST   /admin/coupons               create
GET    /admin/coupons/products      what a coupon can be scoped to: everything for sale
GET    /admin/coupons/{coupon}
PUT    /admin/coupons/{coupon}      REPLACE — the form sends the whole coupon
DELETE /admin/coupons/{coupon}      only if nobody has used it
```

The cart gains `estimated_subtotal_minor`, `estimated_discount_minor`,
`coupon {code, description, applies, reason, message}` and a `discount_minor`
per line; an order gains `coupon_code` and a `discount_minor` per line.

---

## 8. Not built

Automatic discounts; more than one coupon per order; coupons scoped by
category; refunds — so a refunded order's redemption still counts once refunds
exist, which is the question that slice will have to answer.
