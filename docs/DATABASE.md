# DATABASE.md — Proposed Database Architecture

MySQL 8, InnoDB, `utf8mb4_0900_ai_ci`. All timestamps UTC (`TIMESTAMP`/`DATETIME`).
All money is `amount_minor BIGINT` + `currency CHAR(3)`.
All tables get `id BIGINT UNSIGNED AUTO_INCREMENT`, `created_at`, `updated_at`.
Public-facing identifiers use a separate `uuid CHAR(36)` or `ulid` where an id must not
be guessable (certificates, orders, media).

> **Status: mostly built.** Phases 2–15 are migrated; §12's Content half and
> §14 onward remain a proposal. Column lists are indicative of shape and
> intent, not exhaustive — **the migrations are authoritative**.
>
> Where the built schema DIFFERS from the sketch below, the section says so and
> why. Those notes are the most useful thing in this file: they record a
> decision that was made once, under pressure, and would otherwise be
> re-litigated by whoever reads the sketch and not the code.

---

## 0. The central / tenant boundary (ADR-13)

**There is no single database.** One central schema holds the platform; each
academy gets its own schema, `orbito_lms_tenant_<uuid>`, containing every
table in sections 2–12 below.

| | Tables |
|---|---|
| **Central** (`database/migrations/`) | `tenants`, `users`, `sessions`, `password_reset_tokens`, `personal_access_tokens`, `user_social_links`, `plans`, `subscriptions`, `usage_counters`, `cache*`, `jobs*` |
| **Per academy** (`database/migrations/tenant/`) | everything else — including `roles`, `permissions`, `permission_role`, `role_assignments` and `instructor_profiles` |

Three consequences that decide how you write a query:

1. **No foreign key can span the boundary.** Every `user_id`, `owner_id`,
   `graded_by` and `reviewed_by` inside an academy is an *unenforced* column.
   The cascade a deleted account used to trigger is now
   `PurgeUserFromTenant`.
2. **No single statement can join across it.** `whereHas`, `has` and
   `orderBy(subquery)` all compile to one query. Resolve ids on one side,
   then `whereIn` on the other.
3. **Roles are per-academy.** `role_assignments.scope_id` points at courses,
   and a course id only means something inside one schema. Being an instructor
   at one academy says nothing anywhere else.

```sql
-- CENTRAL
tenants(id VARCHAR PK, slug UNIQUE, name, status ENUM(pending,active,suspended,rejected),
      is_active BOOL, logo_path, support_email, approved_at, approved_by,
      data JSON)                        -- stancl virtual columns
      INDEX (status, created_at), INDEX (is_active)

plans(id, slug UNIQUE, name, description,
      price_minor BIGINT, currency CHAR(3), billing_period,
      trial_days SMALLINT, grace_days SMALLINT,
      limits JSON, features JSON,       -- limits: {"max_courses": 50, ...}; null = uncapped
      is_active BOOL, position)

subscriptions(id, tenant_id UNIQUE, plan_id,
      status ENUM(trialing,active,past_due,canceled,expired),
      trial_ends_at, current_period_starts_at, current_period_ends_at, canceled_at,
      grace_days SMALLINT)              -- COPIED from the plan, not read through it
      INDEX (status, current_period_ends_at)   -- the nightly sweeper
```

`subscriptions.grace_days` is copied deliberately: changing a plan's grace
period must not retroactively re-open academies that already lapsed.

**`tenants.data` is where an academy's settings live** unless something filters
or sorts on them — the rule the model states for its own columns. Today it
holds `suspended_reason`, `rejected_reason` and **`registration_mode`**
(`open` | `invite` | `closed`; see `RegistrationMode`). A key that is absent
means the DEFAULT rather than a state, so a setting added later reaches every
academy that never touched the switch without a backfill — the same shape as
notification preferences. Promote one to a real column the day something needs
to query it.

`usage_counters` gained a leading `tenant_id`, NOT NULL with an `''` sentinel
for platform-wide rows — the same reason `owner_id` uses `0`. MySQL treats
NULLs as distinct in a unique index, so a nullable column there would let
those rows duplicate silently.

---

## 1. Identity

```sql
-- CENTRAL. `tenant_id` is the academy this account belongs to; NULL only for
-- a platform operator (`is_super_admin`), and for them it is which academy
-- they have ENTERED rather than a permanent home. Email is unique
-- PLATFORM-wide, so one person teaching at two academies needs two accounts —
-- the cost of central users, and what makes tenancy-from-user unambiguous.
--
-- Nothing a request body sends ever writes this column. Provisioning sets it
-- for an academy owner, registration sets it from the `academy` slug in the
-- signup link, and `EnterAcademy` moves an operator between academies. It was
-- NULL for every self-registered account until registration was told which
-- academy it was writing into.
users(id, uuid, tenant_id NULL, is_super_admin BOOL, name, email UNIQUE, email_verified_at, password, phone,
      avatar_media_id, cover_media_id, headline, bio, timezone DEFAULT 'UTC',
      locale DEFAULT 'en', status ENUM(active,pending,suspended,deleted),
      last_login_at, last_seen_at, remember_token, deleted_at)
      INDEX (status), INDEX (last_seen_at)

user_social_links(id, user_id, platform, url)                       UNIQUE(user_id, platform)

-- PER ACADEMY. Being an approved instructor is a fact about a person AT ONE
-- ACADEMY, granted and revoked by that academy's admins.
instructor_profiles(id, user_id UNIQUE, status ENUM(pending,approved,blocked),
      approved_at, approved_by, application_source, rejection_reason,
      commission_rate_bp INT NULL,        -- basis points; NULL = use platform default
      payout_currency CHAR(3), rating_avg DECIMAL(3,2) DEFAULT 0, rating_count INT DEFAULT 0,
      student_count INT DEFAULT 0, course_count INT DEFAULT 0)

-- PER ACADEMY, all four. `role_assignments.scope_id` points at courses, which
-- only exist inside one schema; `user_id` crosses the boundary unenforced.
roles(id, key UNIQUE, name, description, is_system BOOL, scope_kind ENUM(global,course))
permissions(id, key UNIQUE, group, description)
permission_role(role_id, permission_id)                             PRIMARY KEY(role_id, permission_id)

role_assignments(id, user_id, role_id, scope_type NULL, scope_id NULL, granted_by, expires_at)
      UNIQUE (user_id, role_id, scope_type, scope_id)
      INDEX (scope_type, scope_id)

personal_access_tokens(...)          -- Sanctum
user_devices(id, user_id, token_id, platform, name, last_used_at, ip, user_agent)
```

**Why `role_assignments` and not `role_user`:** course-scoped roles (TA, reviewer,
course manager) fall out of the same table. See `ROLES_PERMISSIONS.md`.

---

## 2. Catalog

```sql
course_categories(id, parent_id, slug UNIQUE, name, description, image_media_id,
      position, is_active)                                          INDEX (parent_id, position)

course_tags(id, slug UNIQUE, name, usage_count)

courses(id, uuid, slug UNIQUE, title, subtitle, description LONGTEXT,
      thumbnail_media_id, intro_video_id,
      owner_id,                              -- primary instructor (users.id)
      category_id, level ENUM(beginner,intermediate,advanced,all),
      locale CHAR(5), status ENUM(draft,in_review,published,archived) DEFAULT 'draft',
      visibility ENUM(public,unlisted,private) DEFAULT 'public',
      completion_mode ENUM(flexible,strict) DEFAULT 'flexible',
      published_at, archived_at, coming_soon_at,
      -- pricing pointer (details live in commerce.products)
      pricing_model ENUM(free,one_time,subscription,mixed) DEFAULT 'free',
      -- denormalised, event-maintained, nightly-reconciled
      item_count INT DEFAULT 0, section_count INT DEFAULT 0,
      total_duration_seconds INT DEFAULT 0,
      enrollment_count INT DEFAULT 0,
      rating_avg DECIMAL(3,2) DEFAULT 0, rating_count INT DEFAULT 0,
      deleted_at)
      INDEX (status, visibility, published_at)
      INDEX (category_id, status)
      INDEX (owner_id, status)
      FULLTEXT (title, subtitle)

course_tag(course_id, tag_id)                                       PRIMARY KEY(course_id, tag_id)

course_instructors(id, course_id, user_id,
      role ENUM(owner,co_instructor,assistant) DEFAULT 'co_instructor',
      revenue_share_bp INT NULL, position)
      UNIQUE (course_id, user_id), INDEX (user_id)

course_details(course_id PK, objectives JSON, requirements JSON,
      target_audience JSON, materials JSON, faq JSON)
      -- long-form lists; validated against a schema, never free-form serialized PHP

course_settings(course_id PK, enable_qa BOOL, enable_reviews BOOL, enable_notes BOOL,
      enable_certificate BOOL, max_students INT NULL, enrollment_expires_days INT NULL,
      drip_mode ENUM(none,by_date,by_days,sequential) DEFAULT 'none',
      retake_allowed BOOL, reset_progress_allowed BOOL,
      video_completion_threshold TINYINT DEFAULT 90)

course_prerequisites(course_id, prerequisite_course_id, position)   PRIMARY KEY(course_id, prerequisite_course_id)
      INDEX (prerequisite_course_id)    -- "what does finishing this unlock?"
      -- BUILT (P9). Gates ENROLMENT only: adding a prerequisite to a live
      -- course must never evict the people already inside it.
```

**Note on `course_details`/`course_settings`:** split from `courses` so the hot list query
never reads cold JSON. `courses` stays narrow and index-friendly.

---

## 3. Curriculum — the ordered spine (ADR-01)

```sql
course_sections(id, course_id, title, description, position, deleted_at)
      INDEX (course_id, position)

course_items(id, uuid, course_id, section_id, position,
      type ENUM(lesson,quiz,assignment,resource,live_session),
      itemable_type, itemable_id,          -- polymorphic to the type table
      title,                               -- denormalised for cheap listing
      is_preview BOOL DEFAULT 0,
      is_published BOOL DEFAULT 1,
      duration_seconds INT DEFAULT 0,
      -- drip
      drip_available_at DATETIME NULL,
      drip_after_days INT NULL,
      drip_after_item_id BIGINT NULL,
      deleted_at)
      UNIQUE (itemable_type, itemable_id)
      INDEX (course_id, position)
      INDEX (section_id, position)
      INDEX (course_id, is_published, position)

lessons(id, content LONGTEXT, content_format ENUM(html,markdown),
      video_provider ENUM(none,upload,youtube,vimeo,external,embed,bunny,mux),
      video_media_id NULL, video_url NULL, video_duration_seconds INT,
      video_poster_media_id NULL, audio_media_id NULL, document_media_id NULL)

resources(id, title, description, media_id, external_url, download_allowed BOOL)

course_item_attachments(id, course_item_id, media_id, position)
      INDEX (course_item_id, position)
```

**Reordering contract.** A single `PATCH /courses/{c}/curriculum/order` receives the
full ordered tree (`[{section_id, item_ids:[…]}, …]`), validated to be a permutation of
the existing set, applied in one transaction. This is the only write path for `position`.

---

## 4. Assessment

```sql
quizzes(id, title, description, instructions,
      time_limit_seconds INT NULL,
      time_expiry_policy ENUM(auto_submit,auto_abandon) DEFAULT 'auto_submit',
      attempts_allowed TINYINT NULL,        -- NULL = unlimited
      passing_score_percent TINYINT,
      grading_policy ENUM(highest,latest,first,average) DEFAULT 'highest',
      question_order ENUM(sorted,random) DEFAULT 'sorted',
      shuffle_answers BOOL DEFAULT 0,
      questions_per_attempt INT NULL,       -- random subset size
      questions_per_page TINYINT DEFAULT 1,
      hide_question_numbers BOOL DEFAULT 0,
      feedback_mode ENUM(deferred,reveal,retry) DEFAULT 'deferred',
      show_correct_answers_after ENUM(never,submission,pass,due_date) DEFAULT 'submission',
      negative_marking BOOL DEFAULT 0,
      allow_previous_button BOOL DEFAULT 1)

question_banks(id, owner_id, course_id NULL, title, description, is_shared BOOL)

questions(id, bank_id NULL, type ENUM(single_choice,multiple_choice,true_false,
        short_answer,long_answer,fill_blank,matching,ordering,image_choice,image_matching),
      title TEXT, body LONGTEXT, explanation LONGTEXT,
      points DECIMAL(8,2) DEFAULT 1, negative_points DECIMAL(8,2) DEFAULT 0,
      media_id NULL, settings JSON, deleted_at)
      INDEX (bank_id, type)
      -- `settings` is validated against a per-type JSON schema. Never PHP-serialized.

question_options(id, question_id, label TEXT, media_id NULL,
      is_correct BOOL DEFAULT 0, match_key VARCHAR(191) NULL, position)
      INDEX (question_id, position)

quiz_questions(id, quiz_id, question_id, position, points_override DECIMAL(8,2) NULL)
      UNIQUE (quiz_id, question_id), INDEX (quiz_id, position)

quiz_attempts(id, uuid, quiz_id, course_item_id, course_id, user_id, enrollment_id,
      attempt_number TINYINT,
      status ENUM(in_progress,submitted,grading,graded,abandoned,expired),
      started_at, expires_at, submitted_at, graded_at, graded_by NULL,
      total_points DECIMAL(9,2), earned_points DECIMAL(9,2), percent DECIMAL(5,2),
      result ENUM(pass,fail,pending) NULL,
      question_order JSON,                  -- the shuffled order actually served
      ip VARCHAR(45), user_agent VARCHAR(255))
      INDEX (user_id, quiz_id, status)
      INDEX (course_id, status)
      INDEX (status, expires_at)            -- for the expiry sweeper

quiz_attempt_answers(id, attempt_id, question_id, question_type,
      answer JSON,                          -- typed by question_type
      points_possible DECIMAL(8,2), points_earned DECIMAL(8,2),
      is_correct BOOL NULL,                 -- NULL = awaiting manual grading
      feedback TEXT, graded_by NULL, graded_at)
      UNIQUE (attempt_id, question_id)
      INDEX (attempt_id)

assignments(id, instructions LONGTEXT, total_points DECIMAL(8,2) DEFAULT 100,
      passing_points DECIMAL(8,2) NULL,          -- NULL = no pass or fail
      due_at DATETIME NULL, late_policy ENUM(reject,accept,penalise) DEFAULT 'accept',
      late_penalty_percent TINYINT DEFAULT 0,
      max_attempts TINYINT NULL DEFAULT 1,       -- NULL = unlimited
      allow_text BOOL DEFAULT 1, allow_files BOOL DEFAULT 1,
      max_file_size_kb INT, allowed_extensions JSON, max_files TINYINT DEFAULT 5)
      -- The file rules NARROW MediaCollection::Submission, never widen it.

assignment_attachments(id, assignment_id, media_id, position)
      UNIQUE (assignment_id, media_id), INDEX (assignment_id, position)

assignment_submissions(id, uuid, assignment_id, course_item_id, course_id,
      user_id, enrollment_id, attempt_number TINYINT,
      status ENUM(submitted,graded,returned),
      body LONGTEXT, submitted_at, is_late BOOL,
      points_raw DECIMAL(8,2) NULL,              -- what the grader awarded
      late_penalty_points DECIMAL(8,2) DEFAULT 0,-- what lateness removed
      points_earned DECIMAL(8,2) NULL,           -- the final figure
      passed BOOL NULL, feedback LONGTEXT,
      graded_by NULL, graded_at)
      UNIQUE (assignment_id, user_id, attempt_number)
      INDEX (course_id, status, submitted_at)    -- the grading queue's read
      INDEX (user_id, status), INDEX (course_item_id, user_id)

assignment_submission_files(id, submission_id, media_id, original_name, size_bytes)
      UNIQUE (submission_id, media_id)
      -- name and size are COPIED at submission time: the media row can be
      -- renamed or deleted later, and a graded submission must still say what
      -- was handed in.
```

**Deviations from the original plan, and why.** There is no `draft` status: a
row exists because the learner handed something in, and a server-side draft
would add an "is this really submitted?" question to every read path. There is
no separate `grading` status either — `submitted` *is* "waiting for a person",
which is what the shared grading queue selects on. `is_late` is decided by the
server at submission and frozen, so moving `due_at` afterwards cannot rewrite
history; the penalty is stored separately from the raw mark so a learner can
see both numbers.

---

## 5. Enrollment & Progress (ADR-02, ADR-03)

```sql
enrollments(id, uuid, course_id, user_id,
      status ENUM(active,completed,expired,suspended,cancelled) DEFAULT 'active',
      source ENUM(free,purchase,manual,subscription,bundle,membership,import),
      source_id BIGINT NULL,                -- order_id / subscription_id / admin user id
      cohort_id NULL,
      enrolled_at, starts_at NULL, expires_at NULL, completed_at NULL,
      suspended_at NULL, suspended_reason NULL, deleted_at)
      UNIQUE (course_id, user_id)
      INDEX (user_id, status)
      INDEX (course_id, status, enrolled_at)
      INDEX (status, expires_at)            -- expiry sweeper

course_progress(enrollment_id PK, course_id, user_id,
      completed_items INT DEFAULT 0, total_items INT DEFAULT 0,
      percent DECIMAL(5,2) DEFAULT 0,
      last_item_id NULL, last_activity_at NULL,
      started_at NULL, completed_at NULL,
      total_watch_seconds INT DEFAULT 0)
      INDEX (user_id, last_activity_at)     -- "continue learning"
      INDEX (course_id, percent)            -- cohort progress view

item_progress(id, enrollment_id, course_item_id, course_id, user_id,
      status ENUM(not_started,in_progress,completed) DEFAULT 'not_started',
      first_seen_at, completed_at NULL,
      watch_position_seconds INT DEFAULT 0, watch_max_seconds INT DEFAULT 0,
      view_count INT DEFAULT 0)
      UNIQUE (enrollment_id, course_item_id)
      INDEX (course_item_id, status)        -- per-item drop-off (analytics heatmap)
      INDEX (user_id, completed_at)

lesson_notes(id, user_id, course_item_id, course_id, body TEXT,
      video_timestamp_seconds INT NULL)
      INDEX (user_id, course_item_id)
```

**Invariant.** `course_progress.percent` is derived; a nightly
`progress:reconcile` command recomputes and reports drift. Drift > 0 is a bug alert.

---

## 6. Commerce

> **Partly superseded, and partly built.** This section was written before
> multi-tenancy, when the platform and the academy were one entity.
>
> **The academy is now the merchant of record** (ROADMAP Phase 10): it connects
> its own gateway credentials and the platform never touches learner money. So
> `instructor_earnings` and `payouts` below describe a platform obligation that
> no longer exists — they become an academy's INTERNAL ledger for paying its
> own instructors, and were deliberately not built.
>
> **What IS built** lives in `database/migrations/tenant/…_create_commerce_tables`
> — all tenant-side: `payment_gateway_accounts` (NEW, not below: the per-academy
> credentials this section had no concept of), `products`, `product_prices`,
> `carts`, `cart_items`, `orders`, `order_items`, `payments`, `payment_events`.
> **That migration has never been run.**
>
> Two departures worth knowing: `cart_items` stores **no price** (a figure
> captured at add-to-cart is exactly the stale price ADR-05 refuses to trust —
> the order re-reads it), and `orders.number` is random rather than sequential
> (a gap-free sequence tells every customer the academy's order count, and
> needs a lock this phase does not otherwise want).
>
> Not built: `customers`, `currencies`, `exchange_rates`, coupons, tax,
> invoices, refunds, earnings, payouts, `idempotency_keys`.

```sql
products(id, uuid, purchasable_type, purchasable_id,   -- course | bundle | download | plan | coaching
      slug UNIQUE, title, status ENUM(draft,active,inactive),
      tax_class_id NULL, is_taxable BOOL DEFAULT 1)
      UNIQUE (purchasable_type, purchasable_id)

product_prices(id, product_id, currency CHAR(3),
      amount_minor BIGINT, sale_amount_minor BIGINT NULL,
      sale_starts_at NULL, sale_ends_at NULL, is_default BOOL)
      UNIQUE (product_id, currency)

currencies(code CHAR(3) PK, name, symbol, minor_unit TINYINT, is_active, position)
exchange_rates(id, base CHAR(3), quote CHAR(3), rate DECIMAL(18,8), fetched_at)
      UNIQUE (base, quote, fetched_at)

carts(id, uuid, user_id NULL, session_token NULL, currency CHAR(3),
      coupon_id NULL, expires_at)
cart_items(id, cart_id, product_id, quantity, unit_amount_minor)
      UNIQUE (cart_id, product_id)

orders(id, uuid, number VARCHAR(32) UNIQUE, user_id NULL, customer_id,
      status ENUM(pending,awaiting_payment,paid,partially_refunded,refunded,cancelled,failed),
      currency CHAR(3),
      subtotal_minor, discount_minor, tax_minor, total_minor, refunded_minor,
      coupon_id NULL, coupon_code_snapshot VARCHAR(64) NULL,
      billing_snapshot JSON, placed_at, paid_at, cancelled_at, notes)
      INDEX (user_id, status), INDEX (status, placed_at), INDEX (number)

order_items(id, order_id, product_id,
      purchasable_type, purchasable_id,     -- snapshot, survives product deletion
      title_snapshot, quantity,
      unit_amount_minor, discount_minor, tax_minor, total_minor,
      instructor_id NULL, instructor_share_minor, platform_share_minor)
      INDEX (order_id), INDEX (purchasable_type, purchasable_id)

customers(id, user_id NULL, email, first_name, last_name, phone,
      country CHAR(2), state, city, postcode, address_line1, address_line2, tax_id)
      INDEX (user_id), INDEX (email)

payments(id, uuid, order_id, gateway VARCHAR(50),
      external_id VARCHAR(191), status ENUM(initiated,pending,captured,failed,cancelled),
      currency CHAR(3), amount_minor, fee_minor,
      initiated_at, captured_at, failed_at, failure_reason)
      UNIQUE (gateway, external_id)
      INDEX (order_id, status)

payment_events(id, payment_id NULL, gateway, external_event_id VARCHAR(191),
      type, payload JSON, signature_verified BOOL, processed_at, received_at)
      UNIQUE (gateway, external_event_id)   -- webhook idempotency

refunds(id, uuid, order_id, payment_id, amount_minor, currency, reason,
      status ENUM(pending,completed,failed), external_id,
      requested_by, requested_at, completed_at, revoke_access BOOL)

coupons(id, code UNIQUE, type ENUM(code,automatic), name, description,
      discount_type ENUM(percentage,fixed), discount_value_minor BIGINT,
      discount_percent DECIMAL(5,2), currency CHAR(3) NULL,
      applies_to ENUM(all,courses,bundles,categories,specific),
      min_purchase_minor NULL, min_quantity NULL,
      usage_limit INT NULL, usage_limit_per_user TINYINT NULL, used_count INT DEFAULT 0,
      starts_at, expires_at NULL, status ENUM(active,inactive,expired))
      INDEX (status, starts_at, expires_at)

coupon_targets(id, coupon_id, target_type, target_id)               INDEX (coupon_id)
coupon_redemptions(id, coupon_id, user_id, order_id, discount_minor, redeemed_at)
      INDEX (coupon_id, user_id)

tax_classes(id, name, is_default)
tax_rates(id, tax_class_id, country CHAR(2), state NULL, rate_percent DECIMAL(6,3),
      name, is_compound, priority)
      INDEX (country, state)

invoices(id, order_id UNIQUE, number VARCHAR(32) UNIQUE, issued_at,
      pdf_media_id NULL, snapshot JSON)

instructor_earnings(id, order_item_id UNIQUE, instructor_id, course_id, order_id,
      currency, gross_minor, commission_minor, fee_minor, net_minor,
      status ENUM(pending,available,paid,reversed),
      available_at, paid_at, payout_id NULL)
      INDEX (instructor_id, status), INDEX (status, available_at)

payouts(id, uuid, instructor_id, currency, amount_minor,
      method ENUM(bank,paypal,mobile_wallet), method_details_encrypted TEXT,
      status ENUM(requested,approved,processing,paid,rejected),
      requested_at, processed_at, processed_by, rejection_reason)

idempotency_keys(id, key UNIQUE, user_id, endpoint, request_hash,
      response_status, response_body JSON, created_at)
```

**Note.** Coupon FKs use `coupons.id`, never the code (a Tutor pitfall). The code is
snapshotted onto the order for display.

---

## 7. Certification

```sql
certificate_templates(id, name, orientation ENUM(landscape,portrait),
      background_media_id, layout JSON, is_default, is_active)

certificates(id, uuid, number VARCHAR(32) UNIQUE, template_id,
      user_id, course_id, enrollment_id UNIQUE,
      issued_at, expires_at NULL,
      status ENUM(issued,revoked), revoked_at, revoked_reason,
      pdf_media_id NULL,
      snapshot JSON,                        -- name/course/score at issue time
      verification_token CHAR(32) UNIQUE)
      INDEX (user_id), INDEX (course_id)
```
Verification page is public and reads `verification_token`, never the id.

---

## 8. Engagement

```sql
reviews(id, course_id, user_id, enrollment_id, rating TINYINT,   -- 1..5
      title, body TEXT, status ENUM(pending,published,rejected),
      instructor_reply TEXT NULL, replied_at, published_at)
      UNIQUE (course_id, user_id)
      INDEX (course_id, status, published_at)

discussions(id, course_id, course_item_id NULL, user_id,
      type ENUM(question,comment), title, body TEXT,
      status ENUM(open,answered,resolved,hidden),
      is_pinned BOOL, reply_count INT DEFAULT 0, last_reply_at,
      accepted_reply_id NULL)
      INDEX (course_id, type, status, last_reply_at)
      INDEX (course_item_id)

discussion_replies(id, discussion_id, parent_id NULL, user_id, body TEXT,
      is_instructor_reply BOOL, status ENUM(published,hidden))
      INDEX (discussion_id, created_at)

announcements(id, course_id, author_id, title, body, published_at, notify BOOL)
wishlists(id, user_id, course_id)                                   UNIQUE (user_id, course_id)
```

---

## 9. Media

```sql
media(id, uuid, owner_id, disk ENUM(public,private), path,
      collection VARCHAR(64),               -- avatars, thumbnails, lesson_video, submission …
      original_name, mime, extension, size_bytes BIGINT,
      width, height, duration_seconds,
      checksum CHAR(64), status ENUM(pending,ready,failed),
      attachable_type NULL, attachable_id NULL, meta JSON)
      INDEX (owner_id, collection), INDEX (attachable_type, attachable_id), INDEX (status)

media_variants(id, media_id, kind ENUM(thumb,preview,hls,transcode),
      path, mime, size_bytes, width, height, bitrate)
      INDEX (media_id, kind)

storage_usage(owner_id PK, bytes_used BIGINT, files_count INT, recalculated_at)
```

---

## 10. Notifications & Analytics

```sql
-- TENANT. An inbox belongs to a person IN AN ACADEMY, not to an account: the
-- same person teaching in one and learning in another has two of each.
notifications(id CHAR(36) PK, type VARCHAR(64),
      notifiable_type VARCHAR(32), notifiable_id BIGINT,   -- morph alias + CENTRAL user id
      data JSON, read_at NULL, created_at, updated_at)
      INDEX (notifiable_type, notifiable_id, created_at)   -- the inbox
      INDEX (notifiable_type, notifiable_id, read_at)      -- the polled badge
      -- `type` stores the NotificationType key ('announcement.published'),
      -- not a PHP class name: a stored FQCN makes moving a class a data
      -- migration, for the same reason the morph map is enforced.
      -- `data` is the payload FROZEN at send time. A notification is a
      -- message, not a live view — editing the announcement afterwards must
      -- not rewrite the mail already sitting in somebody's inbox.

notification_preferences(id, user_id, event_key VARCHAR(64), channel VARCHAR(16), enabled)
      UNIQUE (user_id, event_key, channel)
      INDEX (user_id)
      -- OVERRIDES ONLY. No row means the type's default, so a new
      -- notification type ships without a backfill across every academy and
      -- a changed default actually reaches whoever never touched the switch.
      -- `channel` and `event_key` are plain strings, not enums: a retired
      -- type leaves rows behind, and reading somebody's preferences must not
      -- become fatal because one names a case that no longer exists.

-- TENANT, all of them. The log plus four rollups (ADR-08).
analytics_events(id BIGINT, name VARCHAR(64), occurred_at DATETIME(3),
      actor_id NULL, session_id CHAR(36) NULL,
      subject_type VARCHAR(32) NULL, subject_id NULL,
      course_id NULL, course_item_id NULL,
      properties JSON NULL, ip_hash CHAR(64) NULL, source VARCHAR(10),
      created_at)
      INDEX (name, occurred_at)
      INDEX (course_id, name, occurred_at)
      INDEX (actor_id, occurred_at)
      INDEX (occurred_at)                              -- retention prunes by age

analytics_daily_course(date, course_id, views, enrollments, completions,
      revenue_minor, currency, active_learners)        PRIMARY KEY(date, course_id)
      INDEX (course_id, date)                          -- "this course, last 90 days"
analytics_daily_platform(date, new_users, new_enrollments, completions,
      revenue_minor, currency, active_learners)        PRIMARY KEY(date)
analytics_daily_instructor(date, instructor_id, enrollments, revenue_minor,
      currency, rating_avg)                            PRIMARY KEY(date, instructor_id)
      INDEX (instructor_id, date)
analytics_item_funnel(course_item_id, course_id, started, completed,
      avg_seconds NULL, drop_off_rate, computed_at)    PRIMARY KEY(course_item_id)
      INDEX (course_id, drop_off_rate)                 -- the worst item, first
```

**`analytics_events` has NO FOREIGN KEYS, deliberately.** An event is a fact
about the past; `ON DELETE CASCADE` from `courses` would mean deleting a
course silently erases the history of everybody who took it, which is the one
thing a log exists to prevent. `subject_type` is a morph in shape but not a
relation — it stores a morph-map alias, so a report written years later still
reads it after a class moves namespace. That also leaves the door open to
partitioning, which MySQL forbids on a table with foreign keys.

**Partitioning was planned here and NOT built.** Monthly `PARTITION BY RANGE`
needs a DDL job adding next month's partition in every academy's schema
forever, and at one schema per academy the table is small enough that the
`occurred_at` index plus `analytics:prune` (weekly, 400-day window) is the
honest answer. Revisit when one academy's log outgrows its index, not before.

**A day is a UTC day.** Timestamps are stored in UTC and an academy has no
timezone of its own, so a rollup keyed on a shifting local day could not be
rebuilt deterministically — and rebuildability is what makes these four tables
disposable. `occurred_at` is when it HAPPENED, not when it was written: a
queued listener may land minutes later and a client beacon after a spell
offline, and bucketing by `created_at` would put both in the wrong day.

**The rollups read two sources, on purpose.** Behaviour comes from the log.
**Money comes from the orders ledger** — an order is not a course, and
splitting a payment across courses inside an event would give the platform
total and the per-course totals two definitions free to disagree. The one
other exception is `analytics_item_funnel`, which reads `item_progress`: a
funnel is the present state of every learner, not a day, and that table
already holds exactly it.

**Retention applies to the log only.** `actor_id` names a person and `ip_hash`
is a pseudonym rather than anonymisation, so both age out together. The
rollups are counts with nobody in them and are never pruned.

Canonical event names: `course_viewed`, `course_started`, `course_enrolled`,
`item_started`, `item_completed`, `quiz_started`, `quiz_submitted`, `quiz_passed`,
`assignment_submitted`, `assignment_graded`, `course_completed`, `certificate_issued`,
`order_placed`, `payment_completed`, `refund_issued`, `download_delivered`,
`search_performed`, `cart_abandoned`.

---

## 11. Gamification (P14 — built)

```sql
-- TENANT, all of them. Points earned in one academy mean nothing in another.
gamification_rules(id, key UNIQUE, event_name, name, points INT,
      conditions JSON, is_active, cooldown_seconds, max_per_day)
      INDEX (event_name, is_active)

point_transactions(id, user_id, rule_id NULL, points INT, balance_after INT,
      source_type, source_id, reason, course_id NULL,
      dedupe_key NULL, awarded_at)
      UNIQUE (user_id, dedupe_key)          -- THE anti-farming constraint
      INDEX (user_id, awarded_at)
      INDEX (awarded_at)                    -- the leaderboard window
      INDEX (course_id, awarded_at)         -- ...per course

gamification_profiles(user_id PK, points_total INT,
      current_streak_days INT, longest_streak_days INT, last_active_date DATE,
      is_ranked BOOL)
      INDEX (points_total)

badges(id, key UNIQUE, name, description, icon_media_id, tier, criteria JSON, is_active)
user_badges(id, user_id, badge_id, awarded_at, source_type, source_id)
      UNIQUE (user_id, badge_id)            -- awarded once, by constraint

leaderboard_snapshots(id, scope ENUM(global,course), scope_id NULL,
      period ENUM(weekly,monthly,all_time), period_start DATE,
      entries JSON, computed_at)
      UNIQUE (scope, scope_id, period, period_start)
```

**`point_transactions.dedupe_key` is the anti-farming constraint.** A learner
who un-ticks and re-ticks a lesson fires `ItemCompleted` again; a once-per-
source rule computes `rule:source_type:source_id` and the unique index refuses
the second row. The awarding action CATCHES that violation rather than
checking first — a check-then-insert loses exactly the race a double click
creates. Repeatable rules pass NULL, and MySQL allows any number of NULLs in a
unique index, which is what lets one column serve both kinds.

**`streaks` was merged into `gamification_profiles`.** It was drawn as its own
table keyed on the same column; both are "this learner's standing", both are
touched by the same award, and two tables keyed alike is two locks and two
upserts in one transaction for no gain.

**`is_ranked` was added.** A leaderboard nobody can leave is a hostile
feature — not everybody wants their study habits ranked in front of
classmates. Opting out stops the publication and nothing else, and the builder
excludes at the SOURCE so the ranks close up rather than leaving a gap that
names the person who opted out.

**`course_id` was added to the ledger.** A course board cannot be derived from
the source: an enrolment, a reply and a lesson are three different tables, and
two would need a join across a morph to answer "which course?".

**`daily` and `cohort` were dropped.** A daily board resets before most people
have done anything; cohorts arrive in P15 and can add their own scope then.
`leaderboard_snapshots.entries` holds the whole board as JSON — written once,
read whole, never queried into — with display names FROZEN at build time,
because they live in the central users table and a join across the boundary
cannot work (§ Multi-tenancy).

---

## 12. Live learning (P15 — built) & Content (P16) — shape only

```sql
-- TENANT, all of them.
cohorts(id, uuid, course_id, name, starts_at, ends_at, timezone,
      capacity NULL, enrollment_deadline NULL, status)
      INDEX (course_id, status), INDEX (starts_at)

live_provider_accounts(id, provider UNIQUE, credentials ENCRYPTED, is_active)

live_sessions(id, uuid, course_id NULL, cohort_id NULL,
      provider ENUM(manual,zoom,google_meet), external_id NULL,
      join_url NULL, host_url NULL, host_id,
      title, description, starts_at, ends_at, timezone, status,
      recording_media_id NULL, reminder_sent_at NULL)
      INDEX (starts_at, status)
      INDEX (course_id, starts_at), INDEX (cohort_id, starts_at)

session_attendance(id, live_session_id, user_id, joined_at, left_at,
      duration_seconds, source ENUM(self,host,provider))
      UNIQUE (live_session_id, user_id)

webinars(id, uuid, slug UNIQUE, title, description, live_session_id NULL,
      capacity NULL, is_paid, product_id NULL, status)
webinar_registrations(id, webinar_id, user_id NULL, email, name, status, registered_at)
      UNIQUE (webinar_id, email)

enrollments.cohort_id NULL          -- which RUN they joined; null is self-paced

bundles(id, uuid, slug UNIQUE, title, subtitle, description,          -- BUILT P16
      thumbnail_media_id NULL, status ENUM(draft,published,archived), published_at NULL)
      INDEX (status, published_at)
bundle_items(id, bundle_id, course_id, position)                      -- BUILT P16
      UNIQUE (bundle_id, course_id), INDEX (bundle_id, position), INDEX (course_id)
order_item_allocations(id, order_item_id, course_id, amount_minor)    -- BUILT P16
      UNIQUE (order_item_id, course_id), INDEX (course_id)

-- DIFFERS FROM THE SKETCH: `bundle_items` names `course_id` rather than the
-- morph originally drawn here. A morph would anticipate bundles of downloads,
-- but a download has no enrolment to fan out to, so the grant branch would
-- still switch on type — the morph buys no polymorphism, only a table whose
-- columns are half-meaningless. It goes in when downloads land and it is real.
--
-- `order_item_allocations` was NOT in the sketch and is what keeps per-course
-- revenue honest: a bundle sells for less than its parts, so its money is
-- split across them at ORDER time (largest remainder, summing EXACTLY to the
-- line) and never recomputed. Without it a bundle counts in the platform
-- total and in no course figure at all. See docs/BUNDLES.md §4.
subscription_plans(id, product_id, interval ENUM(day,week,month,year), interval_count,
      trial_days, currency, amount_minor, status)
subscriptions(id, uuid, user_id, plan_id, gateway, external_id,
      status ENUM(trialing,active,past_due,cancelled,expired),
      current_period_start, current_period_end, cancel_at, cancelled_at)
downloads(id, uuid, slug UNIQUE, title, subtitle, description,        -- BUILT P16
      media_id NULL, thumbnail_media_id NULL, pricing_model ENUM(free,one_time),
      status ENUM(draft,published,archived), published_at NULL)
      INDEX (status, published_at), INDEX (media_id)
download_grants(id, download_id, user_id, source ENUM(purchase,free,manual),  -- BUILT P16
      order_id NULL, granted_at, revoked_at NULL)
      UNIQUE (download_id, user_id), INDEX (user_id, revoked_at)
analytics_daily_platform + download_revenue_minor                    -- BUILT P16

-- DIFFERS FROM THE SKETCH, which drew `download_deliveries(token,
-- downloads_count, expires_at)` and `downloads.download_limit`. Delivery is the
-- signed media URL certificates already use; a per-purchase token would have
-- been a second delivery mechanism that could not even count what it claimed
-- to, because `media.download` streams on the signature with no user. The row
-- is the ENTITLEMENT (`download_grants`), and `revoked_at` exists before
-- refunds do. `file_size_bytes` lives on the media row, where it already was.
--
-- `media_id` is NOT a restricting foreign key, on purpose: `DeleteMedia`
-- soft-deletes, which no foreign key sees. The guard is in the action.
-- See docs/DOWNLOADS.md §7.

posts(id, uuid, slug UNIQUE, author_id, title, excerpt, body LONGTEXT,
      cover_media_id, status ENUM(draft,published,archived), published_at,
      seo_title, seo_description)
post_categories / post_tags / post_category / post_tag

pages(id, slug UNIQUE, title, status, seo JSON)
page_blocks(id, page_id, type, position, props JSON)      -- the page-builder seam
leads(id, email, name, phone, source, page_id NULL, course_id NULL, meta JSON)
```

**A live session stores an instant AND a zone**, which is the opposite of
every other dated thing here. Analytics days, streaks and leaderboards are UTC
days because an academy has no timezone and a shifting local day cannot be
rebuilt. A session is the reverse: it happens at a real moment somebody has to
be awake for, so `starts_at` is UTC and `timezone` is the IANA zone it was
SCHEDULED in — "Tuesdays at 7pm Dhaka time" has to survive a daylight-saving
change somewhere else in the world.

**`live_sessions.course_item_id` was dropped from the sketch.** A session that
sits on the spine is reached the way every other itemable is —
`course_items.itemable_type/itemable_id` — and a second link pointing back
would be a second thing to keep in step, free to disagree.

**`host_url` was added and is `$hidden` on the model.** On Zoom the start link
opens the meeting AS the host; it is in no resource, and there is a test
asserting it never appears in a response.

**`session_attendance.source` was added.** A click on Join, a host marking a
roster and a provider's own report are different kinds of evidence, and a
compliance report that cannot tell them apart is one nobody can defend. A
host's mark overrides a click; a click never downgrades a host's mark.

**`reminder_sent_at` is a column, not a queue guard**, because the scheduler
may run on more than one host and "did we already?" has to be answerable from
the row. Rescheduling clears it, or everybody arrives on the old day holding
an email that told them so.

**`custom` became `manual`**, and it is not a fallback: the host schedules the
meeting wherever they already do and pastes the link, and it is the provider
that works today. Zoom and Google Meet are written and have never been
contacted — the same honest position `StripeGateway` was left in at the end of
P10.

---

## 13. Localisation (ADR-10)

```sql
translations(id, translatable_type, translatable_id, locale CHAR(5),
      field VARCHAR(64), value LONGTEXT)
      UNIQUE (translatable_type, translatable_id, locale, field)
      INDEX (locale, translatable_type)

locales(code CHAR(5) PK, name, native_name, direction ENUM(ltr,rtl), is_active, is_default)
```

---

## 14. Indexing & sizing principles

1. Every FK gets an index. Every `status` used in a filter gets a composite index that
   leads with the most selective column.
2. Composite index order = equality columns first, then range/sort.
   `(course_id, status, published_at)` serves `WHERE course_id=? AND status=? ORDER BY published_at`.
3. Growth tables — `analytics_events`, `item_progress`, `quiz_attempt_answers`,
   `notifications` — are the ones to watch. `analytics_events` is partitioned monthly
   with a retention policy; raw rows older than 13 months are dropped after rollup.
4. `item_progress` rows are created lazily (on first view), not eagerly at enrollment.
   For 10k students × 100 items that is the difference between 1M rows and the rows
   actually touched.
5. Soft deletes only where restore is a real product requirement (courses, items,
   sections, users). Everywhere else, hard delete.
6. **Never make a column nullable if it participates in a UNIQUE index you rely
   on.** MySQL treats NULLs as *distinct*, so `UNIQUE(owner_type, owner_id, metric)`
   with a nullable `owner_id` silently permits unlimited duplicate rows. Use a
   sentinel (`usage_counters.owner_id = 0` for platform-wide) instead. The same
   trap applies to `role_assignments`, which guards it in the model.
7. No EAV. If a field is worth storing it is worth a column or a schema-validated JSON
   document. `postmeta` is exactly what we are escaping.

## 15. Migration discipline

- One migration per logical change, always reversible.
- Additive first: add column → backfill in a job → switch reads → drop old column, across
  separate releases. Never a breaking migration in the same deploy as the code that needs it.
- Every index change gets a note in the migration explaining the query it serves.
- Seeders: roles/permissions, currencies, locales, tax classes, a demo course.
