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

| Policy | Guards |
|---|---|
| `CoursePolicy` | view, viewUnpublished, create, update, delete, publish, submitForReview, approve, archive, duplicate, manageInstructors |
| `SectionPolicy` / `CourseItemPolicy` | view, create, update, delete, reorder — all delegate to the parent course |
| `LessonPolicy` | view (⟶ `CourseAccess`), update |
| `QuizPolicy` | view, manage, attempt, grade, viewAttempts |
| `QuizAttemptPolicy` | view, answer, submit, grade — `answer`/`submit` require ownership **and** `in_progress` **and** not expired |
| `AssignmentPolicy` / `SubmissionPolicy` | manage, submit, view, grade |
| `EnrollmentPolicy` | view, create, suspend, delete |
| `OrderPolicy` | view, refund |
| `PayoutPolicy` | request, approve |
| `ReviewPolicy` | create (enrolled + not already reviewed), update (own, within window), moderate, reply |
| `DiscussionPolicy` | view, create, reply, moderate, resolve |
| `MediaPolicy` | view (⟶ `CourseAccess` for private), upload, delete |
| `CertificatePolicy` | view, download, revoke |
| `UserPolicy` / `RolePolicy` | view, update, assign, delete |

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

`CourseAccess::for(User, Course)` returns
`{granted, reason, source, expires_at}` where `source ∈ {owner, staff, enrollment,
subscription, membership, bundle, manual, preview}`. Media signing, the player, downloads,
and the quiz-start endpoint all call it. There is exactly one implementation.

---

## 7. Seeding & lifecycle

- Roles and permissions are seeded from a single `config/permissions.php` registry.
  A `permissions:sync` command adds new keys and reports orphans; it never silently
  removes a key that a role still uses.
- The first registered user becomes Super Admin. Subsequent users are Students.
- Instructor role is granted only after approval (`instructor_profiles.status = approved`).
- Course-scoped assignments expire via `role_assignments.expires_at`; a scheduled sweeper
  removes expired rows and emits `RoleAssignmentExpired`.
- Every role assignment change is written to the audit log with actor, target, and scope.
