# BUNDLES.md — Phase 16, one slice

> **Status: BUILT**, backend and front. This document was the scope decided
> before the code and is kept as a record; §8 is what actually changed on the
> way, including a Phase 10 hole this work fell into.

A **bundle** is a purchasable that owns no content. It points at courses, and
buying it fans out into one enrolment per course. `SyncCourseProduct` has
anticipated it since Phase 10, in as many words:

> *"A course is not a product — it is a thing a product can point at. Keeping
> them separate is what lets a bundle (P16) sell three courses through the
> same checkout without any of them knowing."*

---

## 1. The three decisions

Each of these changes the shape of the work, and each was taken deliberately.

### Access: fan out at purchase, do not re-derive it

Buying a bundle creates one `Enrollment` per course with
`source = EnrollmentSource::Bundle`. **`CourseAccess` is not touched.**

The alternative — a fifth access source that asks "do they own a bundle
containing this course?" — was rejected. It reads well until you follow it:
there would be no enrolment row for progress, drip, a certificate or the
roster to hang on, and removing a course from a bundle would revoke access
somebody paid for. The codebase already has the rule this follows: **a
prerequisite gates ENTRY, not continued presence** (§ Phase 9). What you
bought, you keep.

The cost is stated rather than hidden: **a course added to a bundle after
somebody bought it does not reach them.** That needs an explicit "grant to
existing buyers" action, which is deliberately NOT in this slice — a silent
backfill that enrols hundreds of people is not something to trigger by editing
a form.

### Revenue: allocate across the courses at order time

`BuildDailyRollups::courseRevenue()` reads
`order_items WHERE purchasable_type = 'course'`. A bundle line is invisible to
it, so per-course and per-instructor revenue would silently drop — **an
instructor selling mainly through bundles would read £0 on their own
dashboard**, and report it as a bug.

So a bundle line is split across its courses by list price, largest remainder,
and stored. See §4.

### Partial ownership: sell it, and say what is new

A buyer who already owns 2 of 5 courses may buy the bundle, and is shown which
two before paying. The sale is refused only when they own **every** course in
it — that order has nothing to deliver.

Refusing on any overlap was rejected as hostile: a five-course bundle becomes
unbuyable because of one £10 purchase last year.

---

## 2. Schema

```
bundles(id, uuid, slug UNIQUE, title, subtitle NULL, description NULL,
        thumbnail_media_id NULL, status ENUM(draft,published,archived),
        published_at NULL, timestamps)
  INDEX (status, published_at)

bundle_items(id, bundle_id, course_id, position, timestamps)
  UNIQUE (bundle_id, course_id)     -- the same course twice is a pricing bug
  INDEX (bundle_id, position)
  INDEX (course_id)                 -- "which bundles contain this course?"

order_item_allocations(id, order_item_id, course_id, amount_minor)
  UNIQUE (order_item_id, course_id)
```

**`bundle_items` names `course_id`, not a morph** — which differs from the
sketch in `DATABASE.md`, on purpose. A morph there would anticipate bundles of
downloads, but a download has no enrolment to fan out to, so the grant branch
would still switch on type: the morph buys no polymorphism, only a table whose
columns are half-meaningless. It goes in when downloads land and it is real.

---

## 3. Build order

| # | Piece | Note |
|---|---|---|
| 1 | Migration | the three tables above, reversible |
| 2 | `Bundle`, `BundleItem`, `BundleStatus` | **and `'bundle' => Bundle::class` in the morph map** |
| 3 | `BundlePublishChecklist` | rendered by the studio AND enforced on publish (§10) |
| 4 | `ChangeBundleStatus` | one Action owns the transitions and the timestamps (§10) |
| 5 | `SyncBundleProduct` | the twin of `SyncCourseProduct`; idempotent |
| 6 | `ReconcileBundleSellability` | listener on `CourseStatusChanged` |
| 7 | `RevenueAllocator` | a pure function, unit-tested |
| 8 | `PlaceOrder` | allocate a bundle line, write `order_item_allocations` |
| 9 | `CapturePayment::grantAccess()` | the `bundle` branch |
| 10 | `EnrollmentIntent::bundle()` | bypasses payment AND prerequisites — see §5 |
| 11 | Policy, Requests, Resources, Controller, routes | studio writes, catalogue reads |
| 12 | `courseRevenue()` | union direct course lines with allocations |

---

## 4. The allocation

Weighted by each course's effective price in the order's currency, largest
remainder:

1. `exact_i = bundle_total × weight_i ÷ Σweight`
2. Floor each.
3. Hand the remainder — `bundle_total − Σfloors` — out one minor unit at a
   time, to the largest fractional parts first, **ties broken by `course_id`**
   so the result is deterministic and a test can assert it.

A free course in a bundle has no product, so weight 0, so it allocates 0. If
*every* weight is zero, split evenly.

**The allocations must sum to the line's `total_minor` exactly.** This is the
failure `CLAUDE.md` predicted for coupons, arriving early through bundles: the
moment the parts stop summing to the whole, the platform total and the
per-course figures disagree, and a dashboard shows two different numbers for
one fact.

Allocation happens in `PlaceOrder`, not at capture, because that is where
prices are already read and because an allocation is a snapshot for the same
reason `title_snapshot` is — editing a course's price later must not rewrite
what somebody was charged. Allocations on an unpaid order are harmless:
analytics filters on `orders.paid_at`.

---

## 5. Traps, all of them specific to this codebase

1. **`PlaceOrder::assertNotAlreadyOwned()` early-returns for any non-course
   type**, so a bundle skips the check entirely today. It needs the §1 rule.
2. **`EnrollmentIntent::purchase()` does not bypass prerequisites**, and a
   curated path — course 3 requires course 2 — is the most natural bundle
   there is. Buying it would fail to grant course 3. `bundle()` therefore
   bypasses prerequisites as well as payment: the academy asserting a sequence
   is not a reason to refuse the sequence. The comment must say why it differs
   from `purchase()`, which sits three lines away.
3. **Nothing bypasses the seat limit, deliberately.** A bundle purchase can
   hit one course's `max_students` after the money has moved.
   `grantAccess()` already logs and continues rather than rolling back — right,
   and bundles make it likelier.
4. **`EnrollmentSource::Bundle` has existed since Phase 9 and nothing sets
   it.** This is what sets it.
5. **A bundle is not a course** and must not count against `max_courses`.
   `max_bundles` is a later decision.
6. **The morph map is enforced.** `'bundle'` becomes both
   `products.purchasable_type` and `order_items.purchasable_type`; forgetting
   to register it is a 500 on the write path that caused it. Two phases running
   this was the bug.
7. A course leaving `published` must deactivate every bundle containing it.
   Selling access to something nobody can open is worse than a lost sale, and
   the author gets it back through the checklist rather than a support ticket.

---

## 6. Acceptance criteria

These are the test names.

**Feature**
- publishing is refused with each checklist reason, named
- publishing succeeds and the product becomes sellable
- unpublishing a member course deactivates the bundle
- buying grants every course, with `source = bundle`
- a course whose prerequisite is inside the same bundle is still granted
- owning some of the courses allows the sale; owning all of them refuses it
- allocations sum exactly to the bundle line's total
- a free course in a bundle allocates zero
- per-course revenue includes bundle allocations
- one failed grant is logged without rolling back the payment
- 403 for a learner on every studio route
- 422 on each validation rule

**Unit** — `RevenueAllocator`: remainder distribution, tie-breaking by
`course_id`, all-zero weights, a single course.

**Frontend** — the four list states, the checklist, the already-owned notice,
the price-against-the-parts comparison, dark mode, 360px.

---

## 7. Deliberately out of this slice

- Bundles of **downloads or webinars** — neither exists yet.
- **Coupons** on bundles.
- Bundles delivered by **subscription**.
- A **`max_bundles`** plan limit.
- **Granting a course added after purchase.** It needs an explicit action with
  its own confirmation, not a side effect of editing a form.

---

## 8. What changed on the way

**Nothing in the product could set a price.** Discovered while building this,
because a bundle cannot be published without one:

- `SyncCourseProduct` was written in Phase 10 and **wired to nothing**. No
  `Product` row was ever created outside a factory.
- There was **no endpoint that could write a price**. `product_prices` existed
  only in `ProductFactory::pricedAt()`.
- `PublishChecklist` passed `price_configured` only when the course was FREE,
  under a comment reading *"Pricing lands in Phase 10; until then only free
  courses can satisfy this."* It did, and this was never updated — so **a paid
  course could not be published at all**, and the whole paid path was
  unreachable through the API.

The suite could not see any of it: every commerce test starts from
`Product::factory()`, which mints the row the application never mints.

Closed here rather than worked around, because a bundle needs the same
machinery: `SetProductPrice` is now the single write path for
`product_prices`, `SyncProductForPurchasable` wires Catalog's events to it,
and `price_configured` asks whether there is a real price in the accounting
currency. Course pricing got its own permission (`course.price.own` /
`.any`) rather than riding on `update`, because what a course EARNS is a
different decision from what it says.

**Three smaller things the build settled:**

- **`EnrollmentIntent::bundle()` bypasses prerequisites**, three lines below
  `purchase()`, which deliberately does not. A curated path is the most
  natural bundle there is, and enforcing prerequisites would leave a buyer
  paid-up and locked out of the second half of what they bought.
- **`PriceView` is the catalogue's answer and returns null for anything not
  sellable** — which a draft course's product always is. The pricing endpoint
  therefore returns `ProductPriceResource`, the AUTHOR's view: what is stored,
  on sale or not. Two audiences, two resources (ADR-06).
- **A course leaving `published` takes its bundles back to draft**, one way
  only. Re-publishing the course does not re-publish the bundle: the author
  may have removed a course or changed the price since, and a lifecycle change
  elsewhere must not put something back on sale.
