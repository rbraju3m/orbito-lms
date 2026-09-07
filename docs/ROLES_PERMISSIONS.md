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
`coupon.manage`, `product.manage`, `tax.manage`, `payout.request`, `payout.approve`,
`earning.view.own`, `earning.view.any`, `gateway.manage`
**certification** — `certificate.view.own`, `certificate.view.any`, `certificate.issue`,
`certificate.revoke`, `certificate.template.manage`
**engagement** — `review.create`, `review.moderate`, `review.reply.own`, `review.delete`,
`discussion.create`, `discussion.reply`, `discussion.moderate`, `announcement.manage`
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
| analytics.view.own | ✔ | ✔ | — | ✔ | — | ✔ | — | — |
| analytics.view.platform / export | ✔ | ✔ | — | — | — | — | — | — |
| settings.view / update | ✔ | ✔ | — | — | — | — | — | — |
| audit.view / queue.manage / webhook.manage | ✔ | — | — | — | — | — | — | — |
| ai.use | ✔ | ✔ | — | ✔ | — | ✔ | — | — |
| ai.configure | ✔ | ✔ | — | — | — | — | — | — |

¹ Gated by the platform setting "instructors may publish directly"; otherwise
`course.review.submit` only.
² Only if the course allows self-reset.
³ Only for a course the student is enrolled in.
⁴ Only into the `submission` and `avatar` collections, with tighter size/type limits.

---

## 5. Policies

**Built (Phase 8).** Nine policies, plus seven Gates for the things whose
authorization resolves through a parent course rather than through the model
itself — a section, an item, a quiz and an assignment are all "may I do this to
*this course*", so putting the logic on the Course keeps course-scoped roles
working without duplicating it on four models.

| Policy | Guards |
|---|---|
| `CoursePolicy` | viewAny, view, viewUnpublished, create, update, delete, publish, submitForReview, reviewSubmission, archive, manageInstructors, manageSettings |
| `CurriculumPolicy` | view, manage, reorder — via the `view-curriculum`, `manage-curriculum`, `reorder-curriculum` Gates |
| `QuizPolicy` | manage, grade, viewAttempts — via `manage-quiz`, `grade-quiz`, `view-quiz-attempts` |
| `AssignmentPolicy` | manage, grade, viewSubmissions — via `manage-assignment`, `grade-assignment`, `view-submissions` |
| `MediaPolicy` | view (⟶ `CourseAccess` for private), upload, delete |
| `CourseCategoryPolicy` | view, create, update, delete |
| `UserPolicy` / `RolePolicy` | view, update, assign, delete |
| `InstructorProfilePolicy` | view, review |

One more Gate has no policy of its own: **`view-grading-queue`** is
`QuizPolicy::viewAttempts ∪ AssignmentPolicy::viewSubmissions`, because the
grading queue is one list across both and opens for anyone who can mark either.
It then returns only the kinds that reader may actually open.

**Ownership of an attempt or a submission is checked in the controller, not a
policy**, and answers **404** rather than 403 — a policy that says "forbidden"
confirms the row exists, which is exactly what must not leak about somebody
else's work.

**Planned:** `EnrollmentPolicy` (P9), `OrderPolicy` / `PayoutPolicy` (P10),
`ReviewPolicy` / `DiscussionPolicy` (P12), `CertificatePolicy` (P11).

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
- The first registered user becomes Super Admin. Subsequent users are Students.
- Instructor role is granted only after approval (`instructor_profiles.status = approved`).
- Course-scoped assignments carry `role_assignments.expires_at`. **The column
  exists and nothing sweeps it yet** — an expired row is not currently ignored
  at read time either, so treat expiry as unimplemented rather than partially
  implemented, and build both halves together in P9.
- `RoleAssigned` and `RoleRevoked` events are emitted. A general audit log
  (P19) will listen to them; today nothing does.

**Counts at Phase 8:** 98 permission keys across 8 system roles, synced from
`config/permissions.php` by `php artisan permissions:sync`.
