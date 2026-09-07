# KLASIO_REFERENCE.md — Product / UX / Business Reference

**Source:** publicly visible pages at https://klasio.com/ (home, pricing,
`/comparison/klasio-vs-tutor-lms`) plus third-party coverage, retrieved 2026-09-07.

**Use:** product ideas, packaging, and UX principles only.
**Not to be copied:** branding, wording, visual design, imagery, or implementation.

---

## 1. Positioning

Klasio positions as a **fully hosted, white-labelled course platform for creators,
coaches and educators** — "teach live and recorded courses, without the hassle."
It sells *absence of infrastructure*: no domain, no hosting, no plugins, no updates.

The strategic contrast with Tutor LMS is instructive:

| | Tutor LMS | Klasio |
|---|---|---|
| Delivery | WordPress plugin you host | Managed SaaS |
| Feature gating | Core free, most value in Pro add-ons | Every feature on every plan |
| Setup | DIY site build | "Done for you" course website |
| Mobile | 3rd-party only | Free white-label app included |
| Failure mode | Plugin conflicts, hosting, updates | Vendor lock-in, less extensibility |

**Lesson for Orbito:** the product's value is *coherence*, not feature count.
A learner-facing feature that requires an add-on, a plugin, and a settings page is
worth less than the same feature that just works. Orbito should ship a single coherent
product with capability flags, not an add-on marketplace.

---

## 2. Feature surface (as publicly presented)

Grouped by the domain they'd map to in Orbito:

**Learning delivery**
- Recorded courses
- Live courses / **Live Cohort** (scheduled group programmes)
- **Webinars** (registration, schedule, reminders, attendance)
- Live-session provider integration — Zoom, Google Meet ("bring your own provider")
- Drip content
- Mobile learning (native student app; custom-branded app on higher tiers)
- Secure/protected video hosting

**Assessment & engagement**
- Advanced quizzes, auto-graded, instant feedback
- Assignments
- **Progress heatmaps** — surfacing *where learners stall*, not just percent complete
- Certificates
- Course reviews
- Gamification, leaderboards, badges

**Commerce & business**
- Payments (Stripe, PayPal, and others), secure checkout
- Subscriptions (monthly / quarterly / annual / custom intervals)
- **Memberships** bundling courses + downloads + webinars into one recurring offer
- Product bundles
- Coupons
- Digital downloads (eBooks, templates, audio, code, any file type)
- Coaching (booked 1:1 sessions as a sellable product)
- Tax / invoicing
- Zero platform commission on subscription tiers; small commission on lifetime tiers

**Site & content**
- Course website / **page builder**
- Blogs
- Lead collection
- Multilingual (roadmapped)
- Custom domains

**Operations**
- Analytics
- Instructor management, **staff management** (distinct from instructors)
- Student enrollment (incl. manual)
- Third-party integrations
- **AI Assistant** for content generation

**Transparency surfaces:** public Documentation, FAQ, **Roadmap**, **Changelog**,
Community, and an "Example Academy" demo. These are product features in their own right.

---

## 3. Packaging model (what the tiers tell us)

Public tiers (monthly): Starter $49 · Growth $79 · Business $149 · Enterprise custom.
Lifetime: $399 / $699 / $999 / custom.

Metering dimensions — every one of these is a **counter Orbito must be able to
compute cheaply**:

| Dimension | Range across tiers |
|---|---|
| Staff seats | 1 → unlimited |
| Instructor seats | 10 → unlimited |
| Students (enrolled learners) | 2,500 → unlimited |
| Courses | 25 → unlimited |
| Video storage | 100 GB → unlimited (fair use) |
| Webinars | 100 → unlimited |
| Digital downloads | 25 → unlimited |
| Platform commission | 3% → 0% |

**Architectural implications for Orbito:**
1. **Tenancy/plan limits must be first-class.** A `plan`, `plan_limits`, and a
   `UsageCounter` service that can answer "how many active students / GB / courses" in
   O(1). Retrofitting quotas is painful — design the counters in Phase 1 even if the
   billing product ships in Phase 16.
2. **Staff ≠ instructor.** Two distinct role families with different permission shapes.
   Our role model already separates Staff, Course Manager, Reviewer, TA.
3. **Storage is a billable resource** → the media domain needs per-owner byte accounting.
4. **Commission is a per-plan rate**, not a global setting.

---

## 4. UX principles worth adopting

1. **Time-to-first-course is the product metric.** A new instructor should publish
   without documentation. Wizard-first course creation, sensible defaults, no empty
   settings screens.
2. **One place for live + recorded + downloads.** Do not build three parallel
   product types with three parallel checkouts. One `Product` abstraction, many
   purchasable kinds.
3. **Bring-your-own live provider.** Zoom/Meet as pluggable providers behind a
   `LiveSessionProvider` interface — do not couple the schedule model to Zoom.
4. **Diagnostic analytics, not vanity analytics.** "Where do learners stall?" beats
   "how many enrolled". Design the analytics event stream so per-item drop-off is a
   first-class query (item-level `started`/`completed` events with timestamps).
5. **Bundle economics.** Subscriptions and memberships change access resolution:
   access can come from purchase, subscription, membership, bundle, or manual grant.
   Access must be resolved by one service, not by five call sites.
6. **Public transparency surfaces** (roadmap, changelog, docs) build trust cheaply.
7. **The mobile app is not a port of the web app.** It consumes the same API.
   That is only true if the API is genuinely UI-agnostic from day one.

---

## 5. What Orbito should do differently

Klasio's model is managed-SaaS and closed. Orbito's differentiators should be:

- **API-first and self-hostable.** The same versioned REST API serves web, mobile,
  and third parties. Klasio's app is a black box; ours is a documented contract.
- **Proper multi-currency** (BDT/USD/EUR/GBP) and regional gateways
  (SSLCommerz, bKash, Nagad) — a genuine gap for the South Asian market.
- **Real multilingual content**, not just UI locale — course content translated per locale.
- **Extensibility without plugins** — capability flags, domain events, and a documented
  webhook surface, so integrators never need to fork.
- **Course-scoped roles** (TA, reviewer, course manager) — neither Tutor nor Klasio
  publicly offers granular per-course delegation.

---

## 6. Sources

- https://klasio.com/
- https://klasio.com/pricing
- https://klasio.com/comparison/klasio-vs-tutor-lms
- https://klasio.com/subscriptions
- https://klasio.com/features/live-cohort
- https://elearningindustry.com/directory/elearning-software/klasio
