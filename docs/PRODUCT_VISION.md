# PRODUCT_VISION.md

## 1. Who it's for

| Audience | What they need |
|---|---|
| **Independent instructors** | Publish a first course today, without documentation, and get paid. |
| **Academies and training businesses** | Multiple instructors, delegated staff, real reporting, invoices and tax. |
| **Students** | Find a course, learn on a phone during a commute, know exactly where they left off. |
| **Platform operators** | Run a marketplace: approve instructors, moderate, refund, split revenue, see the numbers. |

## 2. What "good" means here

**For the instructor** — time-to-first-published-course under 30 minutes, with no manual,
no plugin, and no theme decisions. The course builder is the product; everything else
supports it.

**For the student** — the player is fast, the progress is honest, the next thing to do is
always obvious, and it all works on a 360 px screen over a slow connection.

**For the operator** — the dashboard answers "is this working?" in five seconds, and
every number can be traced back to an event.

**For the developer** — a new feature has an obvious home, the tests tell you when you
broke something, and no file is 10,000 lines long.

## 3. Product principles

1. **Coherence beats feature count.** One assignment system that works everywhere beats
   an add-on that works in one place.
2. **The API is the product.** Web, mobile and integrators are peers. If a capability
   only exists in the web UI, it doesn't exist.
3. **Defaults over settings.** Every setting we add is a decision we failed to make.
   New courses must be publishable without opening a settings screen.
4. **Never lie about progress.** A percentage that is wrong is worse than no percentage.
5. **Diagnostic over decorative.** "Where do learners stall?" beats "how many enrolled".
6. **Mobile is the primary target**, not the responsive afterthought.
7. **Money and access are always server truth.** No exceptions, ever.
8. **Extensible without forking.** Events, webhooks and capability flags — not a plugin loader.

## 4. What we refuse to build

- A plugin architecture that lets third-party PHP run in-process. This is what made the
  reference product's codebase what it is.
- A page builder in the MVP. The architecture allows one; the first release does not need one.
- A second design system, a second data-fetching layer, or a second way to do forms.
- Feature flags on core learning functionality to create upsell tiers. Assignments,
  certificates and drip are core, not premium.
- Anything that requires the client to be trusted.
- "Interactive" quiz types (draw/pin/puzzle) before the ten standard types are excellent.

## 5. How we'll know it worked

| Signal | Target |
|---|---|
| Time from instructor signup to first published course | < 30 min, no docs opened |
| Course page render | ≤ 5 DB queries, p95 < 200 ms |
| "Continue learning" load | 1 indexed query |
| Student course completion rate vs baseline | measurably higher — the funnel tells us where it isn't |
| Mobile LCP on mid-tier Android over 4G | < 2.5 s |
| Payment disputes caused by access errors | zero |
| Test suite runtime | fast enough that nobody skips it |
