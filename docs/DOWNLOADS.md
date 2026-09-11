# DOWNLOADS.md — Phase 16, one slice

> **Status: BUILT**, backend and front. This was the scope decided before the
> code and is kept as a record; §7 is what changed on the way — including two
> bugs the bundles slice shipped, fixed here.

A **digital download** is a file an academy sells. Buying one grants the right
to fetch a file, never an enrolment. Unlike a bundle, it owns content: one
`Media` row in a private collection.

---

## 1. The three decisions

### The academy owns downloads

One `download.manage` permission, the same shape as `bundle.manage`. No
`.own` variant and no instructor earnings. Download revenue is counted in the
platform total and reported as its own line — it never reaches a course or an
instructor dashboard, because a download has no course.

Instructor-owned downloads were rejected for this slice: instructors would
expect their earnings on their dashboard, which means extending the analytics
rollups beyond anything else here.

### Unlimited re-downloads, a fresh link each time

Every fetch mints a new 15-minute signed URL — exactly what certificates do
(ADR-09). The file is the buyer's; a shared link dies in fifteen minutes.

This means **no second delivery mechanism.** The `DATABASE.md` sketch drew
a per-purchase `token` with a `downloads_count` and an `expires_at`. That
would run beside signed media URLs, and it could not even count what it
claims to: `media.download` streams on the signature alone, with no user, so
the only countable event is a link being MINTED, not a file being fetched. A
cap on mints spends a download every time a transfer fails halfway.

### Bundles stay courses-only

`bundle_items` names `course_id`. Teaching bundles about downloads means a
morph there, a grant path that switches on type, and an allocation target
that is not a course. That is its own slice.

---

## 2. Schema

```
downloads(id, uuid, slug UNIQUE, title, subtitle NULL, description NULL,
          media_id, thumbnail_media_id NULL,
          pricing_model ENUM(free, one_time), status ENUM(draft,published,archived),
          published_at NULL, timestamps)
  INDEX (status, published_at)

download_grants(id, download_id, user_id, source ENUM(purchase, free, manual),
                order_id NULL, granted_at, revoked_at NULL, timestamps)
  UNIQUE (download_id, user_id)       -- owning it twice is a refund request
  INDEX (user_id, revoked_at)

analytics_daily_platform  + download_revenue_minor
```

Where this differs from the `DATABASE.md` sketch:

- **No token, limit or expiry** — delivery is signed media URLs (§1).
- **`download_grants`, not `download_deliveries`.** The row is the
  ENTITLEMENT, not a delivery.
- **A full refund revokes the grant** (P16, `RevokeOrderAccess`) — sets
  `revoked_at` rather than deleting the record of the sale, unless the admin
  opts out for a goodwill refund. A partial refund leaves it alone. Buying the
  download again afterwards is a new grant (REFUNDS.md §3).
- **Free downloads exist**, with no product, exactly like a free course.
  Because nothing is anonymous yet (§ Multi-tenancy), "free" means free to
  signed-in members of the academy.

---

## 3. Build order

| # | Piece | Note |
|---|---|---|
| 1 | Migration | two tables plus the rollup column |
| 2 | `MediaCollection::Download` | private disk, broad allowlist, never executables |
| 3 | `Download`, `DownloadGrant`, `DownloadStatus` | **and `'download'` in the morph map** |
| 4 | `DownloadPublishChecklist` | rendered by the studio AND enforced on publish (§10) |
| 5 | `ChangeDownloadStatus` | one Action owns the lifecycle |
| 6 | `SyncDownloadProduct` | wired into `SyncProductForPurchasable`; prices via `SetProductPrice` |
| 7 | `GrantDownload` | the ONLY way a grant row comes to exist; catches the unique violation |
| 8 | `DownloadAccess` | the one answer to "may they fetch this?" |
| 9 | `GET /downloads/{slug}/file` | checks `DownloadAccess`, returns `{url, expires_at}` |
| 10 | `CapturePayment`, `PlaceOrder` | the `download` branch of each |
| 11 | `UsageMetric::Downloads` | `max_downloads`, enforced — publishing one is the academy's own decision |
| 12 | Policy, Requests, Resources, Controllers, routes | studio, catalogue, library |

---

## 4. Traps, all specific to this codebase

1. **`CapturePayment` ends in `default => null`.** A paid download line would
   today take the money and grant nothing, silently. A test asserts that a
   paid download is actually granted.
2. **`PlaceOrder::assertNotAlreadyOwned()` knows courses and bundles**, so a
   download could be sold twice.
3. **`media.download` streams on the signature alone**, so access is checked
   only at MINT time. The fetch endpoint is the only place a buyer's link is
   made. `DownloadResource` never carries a URL — a list would otherwise mint
   one per row.
4. **The file behind a published download must not be deletable**, or a
   buyer loses what they paid for. The foreign key restricts, and `DeleteMedia`
   refuses with a reason.
5. **A download is a LIVE file; the order line is the snapshot.** Replacing
   the file — v2 of an eBook — reaches every buyer on their next fetch. That is
   deliberate, and the opposite of `title_snapshot`.
6. **The platform total stops equalling the sum of course revenue** the day a
   download sells, and the rollup's own comment says it equals. With
   `download_revenue_minor` the invariant becomes "courses + downloads =
   platform", and it is tested.
7. **A non-buyer gets 423, not 403** (§ Phase 6) — they could legitimately
   get in, and `error.meta` says how.
8. **A lapsed academy's buyers keep their files.** Fetching is a GET and 402
   gates writes only — the same principle as certificates.
9. **The morph map is enforced.** `'download'` becomes both
   `products.purchasable_type` and `order_items.purchasable_type`.

---

## 5. Acceptance criteria

These are the test names.

**Feature**
- publishing is refused with no file, with a file not ready, or paid with no price — each named
- publishing succeeds and the product becomes sellable
- buying grants it with `source = purchase`; buying it twice is refused
- claiming a free download grants it; claiming again returns the same grant
- a paid download cannot be claimed free
- the fetch endpoint mints a signed URL for a holder, 423s for a non-buyer, refuses a revoked grant
- the signed URL streams the file; an expired or altered one is refused
- a file in use by a published download cannot be deleted
- a lapsed academy's buyers can still fetch
- creating past `max_downloads` is 402
- `download_revenue_minor` is rolled up, and courses + downloads = the platform total
- 403 for an instructor on every studio route; 422 on each validation rule

**Frontend** — the four list states, the right button for free / paid / owned,
fetching opens a fresh link, dark mode, 360px.

---

## 6. Deliberately out of this slice

- **Bundles containing downloads** (§1).
- ~~Refunds~~ — built in P16; a full refund sets `revoked_at` (REFUNDS.md).
- **Upload scanning** — none exists for any upload today. The allowlist
  without executables is this slice's defence.
- **Direct-to-storage uploads for large files** — what `MediaStatus::Pending`
  was declared for and never built. Every byte goes through PHP, which caps
  the file size.
- **Versioning** — buyers get the current file.
- **Download caps**, and **instructor-owned downloads**.
- **Public lead-magnet downloads** — they wait on the anonymous-surface
  decision.

---

## 7. What changed on the way

**No foreign key could guard a buyer's file.** §4 planned a RESTRICT on
`downloads.media_id`. It would have been false comfort: `DeleteMedia`
SOFT-deletes the row — which no foreign key sees — and deletes the bytes
*before* it. The guard lives in `DeleteMedia` itself, before anything is
removed, and refuses for ANY download that uses the file, not only published
ones: an archived download still has owners. The column is `nullOnDelete`
for the hard-delete case that never happens in practice.

**`MediaCollection::uploadPermission()` had never been called.** Declared in
Phase 4, it returned `media.upload` for every collection and nothing read it,
so any uploader — a student, for their submissions — could write into any
collection, including the new `download` one. `StoreMediaRequest` now enforces
it: `download` needs `download.manage`, and `certificate` refuses everybody,
because a certificate a user could upload is one a user could forge. The
authoring collections are unchanged, which means `ROLES_PERMISSIONS.md`
footnote ⁴ — "only into `submission` and `avatar`" — is still not true. That
is recorded as debt rather than fixed here, because choosing a permission per
authoring collection is a decision of its own. *(Closed later in Phase 16:
every collection now names who may write into it — `ROLES_PERMISSIONS.md`.)*

**Two bugs the bundles slice shipped, both fixed with regression tests:**

- **Deleting a published bundle left its product ACTIVE.** A basket still
  holding it could check out, capture the payment, and grant nothing —
  `grantBundle` only logged that the bundle was gone. `DeleteBundle` now fires
  `BundleDeleted`, and Commerce retires the product. Downloads got the same
  treatment from the start (`DownloadDeleted`).
- **The bundle requests checked `thumbnail_media_id` with `exists` alone** —
  exactly what §10 forbids. `ValidatesOwnedMedia` is now a shared trait: the
  cover must be the caller's own image. `UpdateCourseRequest` and
  `UpsertLessonRequest` still carry their private copies from before it was
  shared.

**Smaller:** the fetch hook uses `location.assign`, not `window.open` — a popup
opened after an `await` is exactly what browsers block, and the response is an
attachment, so the page stays put. `formatBytes` moved to `shared/lib` rather
than being copied a second time.
