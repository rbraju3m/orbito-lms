# ROLES_PERMISSIONS.md — Role & Permission Architecture

**Principle:** no role name ever appears in a conditional. Code asks
`$user->can('course.publish', $course)`; the answer comes from a policy that consults
permissions, which come from roles, which may be **global or scoped to a resource**.

---

## 1. Model

```
users ──< role_assignments >── roles ──< permission_role >── permissions
              │
              └─ scope_type / scope_id  (NULL = global; 'course' + id = course-scoped)
```

- **Permission** — an atomic verb on a noun: `course.publish`, `quiz.grade`.
- **Role** — a named bundle of permissions with a `scope_kind` of `global` or `course`.
- **Role assignment** — a user holding a role, optionally scoped to one resource,
  optionally with an `expires_at`.

**Effective permissions for (user, resource)** =
`permissions of the user's global roles` ∪ `permissions of the user's roles scoped to that resource`.

A Policy method is the only place this is evaluated. Resolution is memoised per request.

Custom roles are supported from day one: a role is a row, not a class. Only `is_system`
roles are undeletable.

---

## 2. Roles

| Role | Scope | Purpose |
|---|---|---|
| **Super Admin** | global | Everything, including role and permission management. Bypasses policies via a single explicit `Gate::before`. At least one must always exist. |
| **Admin** | global | Runs the platform: users, courses, orders, refunds, settings, moderation. Cannot alter Super Admins or the permission registry. |
| **Staff** | global | Operational support: view users/orders, answer discussions, manual enrollment. No money movement, no settings, no publishing. |
| **Instructor** | global | Creates and owns courses; manages *their own* courses, students, grading and earnings. |
| **Student** | global | Default role for every registered user. Learns, buys, reviews, asks. |
| **Course Manager** | course | Full authoring + student management on the assigned course(s), including publishing. Cannot see money. |
| **Course Reviewer** | course | Read the full course including unpublished content; approve or reject a submitted course. No editing. |
| **Teaching Assistant** | course | Grade quizzes and assignments, answer discussions, view students. No authoring, no publishing, no money. |

Every user holds **Student** implicitly. Instructors also hold Student — the "view as
student" toggle is a UI mode, not a role change.

---

## 3. Permission registry

Keys are `<group>.<action>`. `.own` suffix means "restricted to resources the user owns
or is assigned to"; policies resolve ownership.

**users** — `user.view`, `user.create`, `user.update`, `user.delete`, `user.suspend`,
`user.impersonate`, `user.export`
**roles** — `role.view`, `role.create`, `role.update`, `role.delete`, `role.assign`,
`role.assign.course`
**instructors** — `instructor.view`, `instructor.approve`, `instructor.block`,
`instructor.commission.manage`
**courses** — `course.view.any`, `course.view.unpublished`, `course.create`,
`course.update.own`, `course.update.any`, `course.delete.own`, `course.delete.any`,
`course.publish.own`, `course.publish.any`, `course.review.submit`, `course.review.approve`,
`course.archive`, `course.duplicate`, `course.instructors.manage`, `course.settings.manage`
**curriculum** — `curriculum.view.unpublished`, `curriculum.manage.own`, `curriculum.manage.any`,
`curriculum.reorder`
**assessment** — `quiz.manage.own`, `quiz.manage.any`, `quiz.grade.own`, `quiz.grade.any`,
`quiz.attempt.view.any`, `quiz.attempt.delete`, `questionbank.manage`,
`assignment.manage.own`, `assignment.manage.any`, `assignment.grade.own`, `assignment.grade.any`,
`assignment.submission.view.any`
**enrollment** — `enrollment.view.own`, `enrollment.view.any`, `enrollment.create`,
`enrollment.bulk`, `enrollment.suspend`, `enrollment.delete`
**progress** — `progress.view.own`, `progress.view.any`, `progress.reset`
**commerce** — `order.view.own`, `order.view.any`, `order.refund`, `order.update`,
`coupon.manage`, `product.manage`, `bundle.manage`, `download.manage`, `tax.manage`, `payout.request`,
`payout.approve`, `earning.view.own`, `earning.view.any`, `gateway.manage`
**certification** — `certificate.view.own`, `certificate.view.any`, `certificate.issue`,
`certificate.revoke`, `certificate.template.manage`
**engagement** — `review.create`, `review.moderate`, `review.reply.own`, `review.delete`,
`discussion.create`, `discussion.reply`, `discussion.moderate`, `announcement.manage`
**live** — `live.manage.own`, `live.manage.any`, `webinar.manage`, `attendance.mark`
**media** — `media.upload`, `media.delete.own`, `media.delete.any`, `media.library.view.any`
**analytics** — `analytics.view.own`, `analytics.view.platform`, `analytics.export`
**settings** — `settings.view`, `settings.update`, `settings.payment`, `settings.email`
**system** — `audit.view`, `queue.manage`, `webhook.manage`, `ai.use`, `ai.configure`

---

## 4. Role → permission matrix

`✔` granted · `own` granted for owned/assigned resources only · `—` denied

| Permission group | Super Admin | Admin | Staff | Instructor | Student | Course Mgr | Reviewer | TA |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| user.view | ✔ | ✔ | ✔ | — | — | — | — | — |
| user.create / update / delete | ✔ | ✔ | — | — | — | — | — | — |
| user.suspend | ✔ | ✔ | — | — | — | — | — | — |
| user.impersonate | ✔ | — | — | — | — | — | — | — |
| role.view | ✔ | ✔ | — | — | — | — | — | — |
| role.create / update / delete | ✔ | — | — | — | — | — | — | — |
| role.assign | ✔ | ✔ | — | — | — | — | — | — |
| role.assign.course | ✔ | ✔ | — | own | — | own | — | — |
| instructor.approve / block | ✔ | ✔ | — | — | — | — | — | — |
| course.create | ✔ | ✔ | — | ✔ | — | — | — | — |
| course.view.unpublished | ✔ | ✔ | ✔ | own | — | own | own | own |
| course.update | ✔ | any | — | own | — | own | — | — |
| course.publish | ✔ | any | — | own¹ | — | own | — | — |
| course.review.submit | ✔ | ✔ | — | own | — | own | — | — |
| course.review.approve | ✔ | ✔ | — | — | — | — | own | — |
| course.delete / archive | ✔ | any | — | own | — | — | — | — |
| course.instructors.manage | ✔ | any | — | own | — | own | — | — |
| curriculum.manage | ✔ | any | — | own | — | own | — | — |
| quiz.manage | ✔ | any | — | own | — | own | — | — |
| quiz.grade | ✔ | any | — | own | — | own | — | own |
| assignment.manage | ✔ | any | — | own | — | own | — | — |
| assignment.grade | ✔ | any | — | own | — | own | — | own |
| questionbank.manage | ✔ | ✔ | — | own | — | own | — | — |
| enrollment.view | ✔ | any | any | own | own | own | own | own |
| enrollment.create / suspend | ✔ | ✔ | ✔ | own | — | own | — | — |
| enrollment.bulk | ✔ | ✔ | — | own | — | own | — | — |
| progress.view | ✔ | any | any | own | own | own | own | own |
| progress.reset | ✔ | ✔ | — | own | own² | own | — | — |
| order.view | ✔ | any | any | — | own | — | — | — |
| order.refund | ✔ | ✔ | — | — | — | — | — | — |
| coupon.manage / product.manage / tax.manage | ✔ | ✔ | — | — | — | — | — | — |
| course.price | ✔ | any | — | own | — | —⁶ | — | — |
| bundle.manage | ✔ | ✔ | — | — | — | — | — | — |
| download.manage | ✔ | ✔ | — | — | — | — | — | — |
| earning.view | ✔ | any | — | own | — | — | — | — |
| payout.request | — | — | — | ✔ | — | — | — | — |
| payout.approve | ✔ | ✔ | — | — | — | — | — | — |
| gateway.manage / settings.payment | ✔ | ✔ | — | — | — | — | — | — |
| certificate.issue / revoke | ✔ | ✔ | — | own | — | own | — | — |
| certificate.template.manage | ✔ | ✔ | — | — | — | — | — | — |
| review.create | — | — | — | — | ✔³ | — | — | — |
| review.moderate / delete | ✔ | ✔ | ✔ | — | — | — | — | — |
| review.reply.own | ✔ | — | — | own | — | own | — | — |
| discussion.create / reply | ✔ | ✔ | ✔ | ✔ | ✔³ | ✔ | ✔ | ✔ |
| discussion.moderate | ✔ | ✔ | ✔ | own | — | own | — | own |
| announcement.manage | ✔ | any | — | own | — | own | — | — |
| media.upload | ✔ | ✔ | ✔ | ✔ | ✔⁴ | ✔ | — | ✔ |
| media.delete.any / library.view.any | ✔ | ✔ | — | — | — | — | — | — |
| live.manage.own | ✔ | any | — | own | — | own | — | — |
| webinar.manage | ✔ | ✔ | — | — | — | — | — | — |
| attendance.mark | ✔ | ✔ | — | own | — | own | — | — |
| analytics.view.own | ✔ | ✔ | — | ✔ | — | ✔ | — | — |
| analytics.view.platform | ✔ | ✔ | — | — | — | — | — | — |
| analytics.export | ✔ | ✔ | — | ✔⁵ | — | ✔⁵ | — | — |
| settings.view / update | ✔ | ✔ | — | — | — | — | — | — |
| audit.view / queue.manage / webhook.manage | ✔ | — | — | — | — | — | — | — |
| ai.use | ✔ | ✔ | — | ✔ | — | ✔ | — | — |
| ai.configure | ✔ | ✔ | — | — | — | — | — | — |

¹ Gated by the platform setting "instructors may publish directly"; otherwise
`course.review.submit` only.
² Only if the course allows self-reset.
³ Only for a course the student is enrolled in.
⁴ Only into `avatar` and `submission`. Each media collection names who may
write into it — see *Upload permissions per collection* below.
⁵ Export sits beside view rather than above it: somebody who can see a figure
and not save it will copy it out by hand. The ROWS are scoped to what the
caller may open, so an instructor exports their own courses and nobody else's.
⁶ A Course Manager runs a course and does not touch its money — the role's own
description says so. The consequence is deliberate and worth knowing: they
hold `course.publish.own`, but a PAID course cannot be published until it has
a price, so the owner prices it and the manager runs it.

### Upload permissions per collection

Who may put bytes into each collection, from `MediaCollection::uploadPermissions()`.
Any one of the listed permissions is enough, **held anywhere** —
`holdsPermissionAnywhere()`, academy-wide or on any course — because an upload
happens before the file is attached to anything, so there is no course to ask
about. Attaching it is where the real check happens: the file must be the
caller's own and of the right collection (`ValidatesOwnedMedia`), and the
resource's own policy still applies.

| Collection | Any of | Who that means |
|---|---|---|
| `avatar`, `submission` | `media.upload` | everybody |
| `course_thumbnail` | `course.update.own/.any`, `bundle.manage`, `download.manage` | course authors; bundle and download covers |
| `course_intro_video` | `course.update.own/.any` | course authors |
| `lesson_video` | `curriculum.manage.own/.any` | curriculum authors, Course Managers |
| `lesson_attachment` | `curriculum.manage.own/.any`, `assignment.manage.own/.any` | lesson and assignment authors |
| `category_image` | `settings.update` | whoever manages categories |
| `download` | `download.manage` | the academy's shop |
| `certificate` | — | nobody: certificates are generated |

The lists hold both `.own` and `.any` because admins hold the `.any` keys and
not the `.own` ones. Until Phase 16 none of this was checked: every collection
was open to any `media.upload` holder, and a student could put a 2 GB lesson
video on the academy's storage bill.

**Pricing is separate from editing on purpose.** `course.price.*` is not part
of `course.update`, because what a course EARNS is a different decision from
what it says — an academy can let a TA fix a typo without letting them halve
the price. `bundle.manage` has no `.own` variant at all: a bundle has no
owner, it can contain another instructor's courses, and pricing it decides
what that instructor earns, so it is an academy-level decision rather than an
author's.

---

## 5. Policies

**Built.** Fifteen policies, plus fifteen Gates for the things whose
authorization resolves through a parent course rather than through the model
itself — a section, an item, a quiz, an assignment and a live session are all
"may I do this to *this course*", so putting the logic on the Course keeps
course-scoped roles working without duplicating it on five models.

**A Gate rather than a policy when there is no model to point at.** Connecting
a payment gateway, reading platform analytics, exporting, publishing a webinar
and marking a roster are all academy-wide capabilities: a policy method would
need something passed to it that does not exist.

| Policy | Guards |
|---|---|
| `CoursePolicy` | viewAny, view, viewUnpublished, create, update, delete, publish, submitForReview, reviewSubmission, archive, manageInstructors, manageSettings |
| `CurriculumPolicy` | view, manage, reorder — via the `view-curriculum`, `manage-curriculum`, `reorder-curriculum` Gates |
| `QuizPolicy` | manage, grade, viewAttempts — via `manage-quiz`, `grade-quiz`, `view-quiz-attempts` |
| `AssignmentPolicy` | manage, grade, viewSubmissions — via `manage-assignment`, `grade-assignment`, `view-submissions` |
| `MediaPolicy` | view (⟶ `CourseAccess` for private), upload, delete |
| `CourseCategoryPolicy` | view, create, update, delete |
| `UserPolicy` / `RolePolicy` | view, update, assign, delete |
| `AcademyPolicy` | view, update — the academy administering ITSELF (`/admin/academy`), gated by `settings.view` / `settings.update`. NOT the platform registry, which is the operator's and sits behind the central flag. |
| `InstructorProfilePolicy` | view, review |
| `EnrollmentPolicy` (P9) | view, create, suspend, revoke, extend |
| `OrderPolicy` (P10) | view, pay (the owner only), refund (`order.refund`, built P16 — never the learner, even on their own order) — plus the `manage-gateways` Gate |
| `CertificatePolicy` (P11) | view, issue, revoke |
| `ReviewPolicy` (P12) | delete, moderate, reply |
| `DiscussionPolicy` (P12) | viewAny, view, create, reply, accept, moderate |
| `AnnouncementPolicy` (P12) | viewAny, view, manage |
| `CouponPolicy` (P16) | viewAny, view, create, update, delete — all `coupon.manage` (Admin, Super Admin); an instructor cannot discount their own course |
| `WebhookEndpointPolicy` (P16) | viewAny, view, create, update, delete — all `webhook.manage`, Super Admin only: an endpoint receives learners' names and emails |

Gates with no model: `manage-gateways`, `moderate-reviews`,
`view-platform-analytics`, `view-course-analytics`, `export-analytics`,
`manage-live-for-course`, `manage-webinars`, `mark-attendance`.

One more Gate has no policy of its own: **`view-grading-queue`** is
`QuizPolicy::viewAttempts ∪ AssignmentPolicy::viewSubmissions`, because the
grading queue is one list across both and opens for anyone who can mark either.
It then returns only the kinds that reader may actually open.

**Ownership of an attempt or a submission is checked in the controller, not a
policy**, and answers **404** rather than 403 — a policy that says "forbidden"
confirms the row exists, which is exactly what must not leak about somebody
else's work.

**`PayoutPolicy` was never built and will not be**: the academy is the merchant
of record (ADR-13), so `instructor_earnings` and `payouts` are an academy's
internal ledger rather than a platform surface. See ROADMAP Phase 10.

**THE MISTAKE THIS SECTION EXISTS TO PREVENT**, and it has now been made four
times: every instructor holds the `.own` keys GLOBALLY, so
`hasPermission('x.own', $course)` — which returns global ∪ scoped — is TRUE for
every course in the academy. Use `hasAnyScopedPermission()` when the question
is "do they staff THIS course?". It made every instructor staff on every course
in P3, and the same shape recurred in the discussion, analytics and live gates.
The regression tests are in `CourseScopedAccessTest`.

**No policy at all is the right answer sometimes.** The wishlist, the
notification inbox and the achievements screen have none: every query starts
from the caller's own id, so there is no other person's row to authorize
against. What that requires instead is that no query in those controllers ever
starts anywhere else — an id from the request narrows a set that is already
theirs, and never looks one up.

**Rules for policy code**
- A policy never queries a role name. It calls `$user->hasPermission($key, $resource)`.
- Ownership checks live in the policy; permission checks live in the registry.
- `Gate::before` grants Super Admin everything — the **only** blanket bypass in the system.
- Every policy method has a test asserting the denial path.

---

## 6. Access vs authorization — two different questions

These are separate and must never be conflated:

| Question | Answered by | Example |
|---|---|---|
| *May this user perform this operation?* | Policy + permissions | Can an instructor publish this course? |
| *May this user consume this content?* | `Enrollment\Queries\CourseAccess` | Is this student enrolled, unexpired, and past the drip date for lesson 12? |

`CourseAccess::for(?User, Course)` — and `forItem(?User, CourseItem)` — returns
an `AccessDecision` of `{granted, reason, source, enrollment, expiresAt}`. The
user is nullable because free preview content is reachable anonymously.
`source ∈ {owner, staff, enrollment, preview}` today; `subscription`,
`membership`, `bundle` and `manual` are added to that one class in P9/P10 and
nowhere else.

Media signing, the player, item content, downloads, quiz start and assignment
submission all call it. There is exactly one implementation, and a refusal the
caller could legitimately fix answers **423 Locked** with the reason, not 403.

---

## 7. Seeding & lifecycle

- Roles and permissions are seeded from a single `config/permissions.php` registry.
  A `permissions:sync` command adds new keys and reports orphans; it never silently
  removes a key that a role still uses.
- **Every self-registered account is a Student**, in the academy its signup
  link named. "The first registered user becomes Super Admin" was true before
  multi-tenancy and is not now: an academy's first account is its OWNER, created
  by `ProvisionTenant` with the Admin role, and `RegistrationMode` decides
  whether anyone may self-register after that. The permanent platform owner is
  a third thing again — see below.
- Instructor role is granted only after approval (`instructor_profiles.status = approved`).
- Course-scoped assignments carry `role_assignments.expires_at`. **The column
  exists and nothing sweeps it yet** — an expired row is not currently ignored
  at read time either, so treat expiry as unimplemented rather than partially
  implemented, and build both halves together in P9.
- `RoleAssigned` and `RoleRevoked` events are emitted. A general audit log
  (P19) will listen to them; today nothing does.

**Counts today:** 102 permission keys across 8 system roles, synced into every
academy's schema from `config/permissions.php` by `php artisan permissions:sync`
— and by `TenantDatabaseSeeder` when an academy is provisioned, because an
academy with no roles is one where nobody can do anything, including its owner.

### The platform owner

One permanent account, configured in `config/orbito.php` under `owner` and
created by `EnsurePlatformOwner` — from `DatabaseSeeder`, from
`php artisan orbito:ensure-owner`, and automatically at the end of every
central migration. Idempotent, so a deploy repairs it rather than duplicating
it.

It is the only account that holds **both** super-admin answers, which are
otherwise unrelated things (see `../CLAUDE.md` § Multi-tenancy):

| | What it is | Where it lives |
|---|---|---|
| `users.is_super_admin` | the platform operator — runs the academy registry | central row, a flag |
| `RoleKey::SuperAdmin` | everything on ONE academy's data, via `Gate::before` | that academy's schema |

The owner is granted the role in **every** academy: by `TenantDatabaseSeeder`
when one is provisioned, by `EnsurePlatformOwner` for the ones that already
exist, and again by `EnterAcademy` on the way in. Which academy they are
currently inside is `users.tenant_id`, moved by
`POST /admin/tenants/{tenant}/enter`.

**Three things are refused, in three independent places.** Delete, suspend and
demote each throw `PlatformOwnerProtected`:

| Where | Why it is not enough on its own |
|---|---|
| `UserPolicy` | keeps the button off the screen; but a policy the Gate never reaches protects nothing |
| the Actions (`SuspendUser`, `RevokeRoleFromUser`) | a console command authorizes nothing |
| `User::deleting` | the last line, for any path the other two do not cover |

`Gate::before` — the one blanket bypass in the system — has exactly one
exception, and this is it: when the subject of an ability is the platform
owner it falls **through** to the policy instead of granting. Without that,
any other Super Admin could delete the permanent account. It falls through
rather than denying, so the harmless abilities (view, export) still work.

`isPlatformOwner()` compares against the configured email rather than reading a
column, deliberately: a boolean somebody can set is a boolean somebody can
unset, and this is the account nobody can restore from inside the application.
Changing `PLATFORM_OWNER_EMAIL` therefore *moves* the protection; it does not
create a second protected account. The password is a seed — written once, at
creation, never rewritten, so a deploy cannot revert a changed one.
