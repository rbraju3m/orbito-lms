# TUTOR_AUDIT.md — Tutor LMS Functional & Architectural Audit

**Audited installation:** `/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`
**Version:** Tutor LMS **4.0.7** (free core), Themeum. GPLv3.
**Audit date:** 2026-09-07
**Method:** static source inspection (861 PHP files, 27 MB) + read-only inspection of the
live database `tutor_lms_mobile` (prefix `wp_`). Nothing was modified.

---

## 0. Scope caveat — read this first

**Tutor LMS Pro is NOT installed on this machine.** The plugins directory contains only
the free `tutor` core plus an unrelated custom `tutor-lms-api` plugin. Verified:

- `find .. -maxdepth 2 -type d -name '*tutor*'` → `tutor`, `tutor-lms-api` only.
- `SHOW TABLES LIKE 'wp_tutor%'` returns 21 tables; **no** `wp_tutor_gradebooks`,
  `wp_tutor_gradebooks_results`, or `wp_tutor_email_queue` (the Pro-created tables).

Therefore, everything in this document about **Pro/Premium** features (certificates,
assignments engine, content drip, gradebook, Zoom, Google Meet, multi-instructor,
prerequisites, calendar, notifications, social login, email templates, reports,
subscriptions, bundles, content bank, AI Studio) is derived from:

1. The **extension seams left in the free core** — `Addons.php`, `Upgrader.php`'s
   `install_gradebook()` / `install_tutor_email_queue()`, `TUTOR_ASSIGNMENTS\Assignments`
   imports, `_tutor_zm_data` meta, `tutor_content_drip_assignment_deadline` filter,
   `tutor()->bundle_post_type`, `SELLING_OPTION_SUBSCRIPTION`, etc.
2. The vendor's own published feature list (`readme.txt`) and marketing pages.

Those items are marked **[inferred]** below. Anything unmarked was read in source or DB.
**Do not treat inferred items as verified behaviour.**

---

## 1. Architecture at a glance

| Aspect | How Tutor does it |
|---|---|
| Platform | WordPress plugin — no framework, no DI container of consequence, no ORM |
| Entry | `tutor.php` → `TUTOR\Tutor` singleton, `$GLOBALS['tutor']` |
| Data model | WP custom post types + `postmeta`/`usermeta` + 21 custom tables |
| API | 137 `wp_ajax_*` actions (the real API) + 12 read-only `register_rest_route` endpoints |
| Authorization | WP capabilities (`edit_tutor_course`, …) + ad-hoc `current_user_can()` checks |
| Frontend | Server-rendered PHP templates + jQuery, plus bundled React for course builder |
| Extensibility | ~1,200 `do_action`/`apply_filters` hooks — genuinely the strongest part |
| Background work | WP-Cron + a custom `wp_tutor_scheduler` table (added 3.8.0) |

### Code hotspots (lines / bytes)

| File | Size | Note |
|---|---|---|
| `classes/Utils.php` | **10,503 lines, 289 public methods, 274 KB** | god object |
| `classes/Course.php` | 3,586 lines, 107 KB | controller + model + view helpers |
| `classes/Quiz.php` | 69 KB | attempt lifecycle + grading + rendering |
| `classes/Options_V2.php` | 66 KB | the entire settings schema as one PHP array |
| `models/OrderModel.php` | 63 KB | |
| `includes/tutor-general-functions.php` | 58 KB | global functions |
| `components/InputField.php` | 48 KB | form field renderer |

**Takeaway:** functionality is excellent; structure is not. Business logic, persistence,
HTTP handling, and HTML rendering live in the same classes. This is the primary thing
Orbito must not reproduce.

---

## 2. Data model

### 2.1 Post types (verified via `register_post_type` + live DB)

| Post type | Purpose | Parenting |
|---|---|---|
| `courses` | Course | — |
| `topics` | Section / chapter | `post_parent` = course |
| `lesson` | Lesson | `post_parent` = topic |
| `tutor_quiz` | Quiz | `post_parent` = topic |
| `tutor_assignments` | Assignment **[Pro]** | `post_parent` = topic |
| `tutor_enrolled` | **An enrollment stored as a post** | `post_parent` = course, `post_author` = student |
| `tutor_announcements` | Course announcement | |
| `course-bundle` | Bundle **[Pro, inferred]** | |

Taxonomies: `course-category` (hierarchical), `course-tag`.

Ordering within a topic uses `menu_order`. Course content is fetched with a
`post_parent IN (topics of course)` query filtered by
`tutor_course_contents_post_types` — extensible, but every curriculum read is a
multi-join `wp_posts` query.

### 2.2 Custom tables (21 present in the live DB)

Quiz engine:
```
wp_tutor_quiz_questions          question_id, quiz_id, question_title, question_description,
                                 answer_explanation, question_type, question_mark,
                                 question_settings (serialized), question_order
wp_tutor_quiz_question_answers   answer_id, belongs_question_id, belongs_question_type,
                                 answer_title, is_correct, image_id, answer_two_gap_match,
                                 answer_view_format, answer_settings, answer_order
wp_tutor_quiz_attempts           attempt_id, course_id, quiz_id, user_id, total_questions,
                                 total_answered_questions, total_marks, earned_marks,
                                 attempt_info (serialized), attempt_status, attempt_ip,
                                 attempt_started_at, attempt_ended_at,
                                 is_manually_reviewed, manually_reviewed_at, result
wp_tutor_quiz_attempt_answers    attempt_answer_id, user_id, quiz_id, question_id,
                                 quiz_attempt_id, given_answer, question_mark,
                                 achieved_mark, minus_mark, is_correct
```

Commerce (native, added 3.x):
```
wp_tutor_orders            + parent_id (subscription), transaction_id, order_type,
                             order_status, payment_status, subtotal_price, pre_tax_price,
                             tax_type/rate/amount, total_price, net_payment, coupon_*,
                             discount_*, fees, earnings, refund_amount, payment_method,
                             payment_payloads (LONGTEXT), created_at_gmt/created_by …
wp_tutor_order_items       order_id, item_id, regular_price, sale_price, discount_price, coupon_code
wp_tutor_ordermeta         EAV on orders
wp_tutor_order_itemmeta    EAV on order items
wp_tutor_coupons           coupon_type (code|automatic), discount_type ENUM(percentage,flat),
                             applies_to, total_usage_limit, per_user_usage_limit,
                             purchase_requirement, start/expire dates
wp_tutor_coupon_applications   coupon_code → reference_id (FK on the *code*, not the id)
wp_tutor_coupon_usages         coupon_code, user_id
wp_tutor_carts / wp_tutor_cart_items   (cart_items later ALTERed to add item_type, item_details JSON)
wp_tutor_customers         billing_* snapshot, one row per user
wp_tutor_earnings          per-order instructor/admin split, commission_type, deduct_fees_*
wp_tutor_withdraws         user_id, amount, method_data, status
wp_tutor_scheduler         type, reference_id, scheduled_at_gmt, status, payload
```

GDPR: `wp_tutor_legal_consents`, `wp_tutor_user_consents`, `wp_tutor_legal_consent_logs`.

**[Pro, inferred]** `wp_tutor_gradebooks`, `wp_tutor_gradebooks_results`,
`wp_tutor_email_queue` — creation code exists in `Upgrader.php` but is gated on Tutor Pro.

### 2.3 Meta keys (the de-facto schema)

Course (`postmeta`):
`_tutor_course_price_type` (free|paid|subscription), `tutor_course_price`,
`tutor_course_sale_price`, `tutor_course_selling_option` (one_time|subscription|both|membership|all),
`_tutor_course_product_id`, `_tutor_course_level`, `_tutor_course_benefits`,
`_tutor_course_requirements`, `_tutor_course_target_audience`,
`_tutor_course_material_includes`, `_course_duration`, `_tutor_enable_qa`,
`_tutor_is_public_course`, `_tutor_attachments`, `_tutor_course_settings` (**a serialized
blob** holding drip, prerequisites, enrollment expiry, max students, etc.),
`_tutor_course_enable_coming_soon`, `_video`.

Video meta `_video` is an array: `{source: html5|youtube|vimeo|external_url|embedded|shortcode,
source_video_id, poster, runtime}`.

User (`usermeta`):
`_is_tutor_instructor`, `_is_tutor_student`, `_tutor_instructor_status`,
`_tutor_instructor_approved`, `_tutor_instructor_course_id` (**multi-row: the
instructor↔course join table, stored in usermeta**), `_tutor_profile_bio`,
`_tutor_profile_job_title`, `_tutor_profile_photo`, `_tutor_cover_photo`,
`_tutor_profile_{facebook,twitter,linkedin,github,website}`, `_tutor_timezone`,
`tutor_last_login`, `_tutor_course_wishlist`, `_tutor_withdraw_method_data`,
`tutor_profile_view_mode`, and — critically —
**`_tutor_completed_lesson_id_{lesson_id}` : one usermeta row per completed lesson.**

Engagement uses `wp_comments` with `comment_type`:
`tutor_course_rating` (reviews, rating in `comment_meta`), `tutor_q_and_a` (Q&A),
`tutor_assignment` (assignment submissions **[Pro]**), `course_completed`.

---

## 3. Functional audit

### 3.1 Courses
CRUD via post type; statuses `draft`/`auto-draft`/`pending`/`publish`/`private`/`future`/`trash`.
"Pending" doubles as the instructor-submission review state. Fields: title, description,
excerpt, thumbnail (featured image), intro video, benefits ("What will I learn"),
requirements, target audience, materials included, duration, level, category, tag,
attachments, Q&A toggle, public/private, "coming soon".

Completion modes: `flexible` (student may mark complete) vs `strict` (all content required)
— a genuinely good idea worth keeping.

Price types: `free` | `paid` | `subscription`. Selling options: one_time, subscription,
both, membership, all.

**Gaps:** no course versioning, no scheduled publish beyond WP `future`, no
draft-vs-published separation (editing a live course edits it live), no co-instructor
model beyond the usermeta list, no per-course SEO fields.

### 3.2 Curriculum
Course → topics → (lesson | quiz | assignment). Two levels only; no nested sub-sections.
Reorder via `tutor_update_course_content_order` AJAX writing `menu_order`. Builder is a
React bundle (`assets/js/tutor-course-builder.js`) — the most modern part of the product.
Lesson preview via `_is_preview` meta.

**Gaps:** ordering lives in `menu_order` across a heterogeneous post table, so a single
reorder touches many rows and every "what's next?" query re-joins posts+meta. No
duplicate-section, no cross-course content reuse in free core (Pro adds a "Content Bank"
[inferred]).

### 3.3 Lessons
Content (WP editor or block editor), featured image, `_video` array, attachments,
per-lesson comments (toggleable). Video sources: self-hosted, YouTube, Vimeo, external
URL, embedded, shortcode. Watch position is synced via `sync_video_playback` AJAX.

### 3.4 Quiz engine (the strongest core feature)

Question types (verified constants in `models/QuizModel.php`):
`true_false`, `single_choice`, `multiple_choice`, `open_ended`, `fill_in_the_blank`,
`short_answer`, `matching`, `image_matching`, `image_answering`, `ordering`,
plus interactive types `draw_image`, `scale`, `pin_image`, `coordinates`, `puzzle`.

Attempt lifecycle: `attempt_started` → `attempt_ended` | `review_required` | `attempt_timeout`.
Result: `pass` | `fail` | `pending`. Feedback modes: `default`, `reveal`, `retry`.
Settings (`tutor_quiz_option` post meta): time limit + unit, attempts allowed, passing
grade, max questions for answer, question layout/order, hide question number, char limits,
"when time expires" (auto-submit vs auto-abandon), final grade calculation.

Auto-grading covers choice/TF/fill-in/matching/ordering; `open_ended` and `short_answer`
route to manual review (`review_required`). Negative marking via `minus_mark`.

**Gaps:** `question_settings`/`answer_settings`/`attempt_info` are **serialized PHP blobs**
— unqueryable, unindexable, and a deserialization surface. No question bank in free core.
No per-question analytics. Grading logic is embedded in a 69 KB class alongside rendering.

### 3.5 Assignments **[Pro — inferred]**
Free core references `TUTOR_ASSIGNMENTS\Assignments`, post type `tutor_assignments`,
`_tutor_assignment_attachments` meta, and `comment_type = 'tutor_assignment'` for
submissions. Deadlines interact with content drip via
`tutor_content_drip_assignment_deadline`. Progress counts an assignment as complete when a
submission comment exists.

### 3.6 Enrollment
An enrollment is a **post** (`tutor_enrolled`) with `post_parent` = course,
`post_author` = student, `post_status` ∈ `completed` | `pending` | `cancel`.
Meta: `_tutor_enrolled_by_order_id`, `_tutor_enrolled_by_product_id`.
Free, paid (WooCommerce / EDD / native), and manual enrollment **[Pro for bulk]**.

**Gaps:** counting enrollments = counting posts; `wp_posts` becomes the largest table in
the system. No first-class expiry, seat limits, or suspension columns. No enrollment
history/audit.

### 3.7 Progress — the weakest area

- Lesson completion: `update_user_meta($user, "_tutor_completed_lesson_id_{$id}", time())`
  → **one usermeta row per student per lesson**.
- Course percentage: `Utils::get_course_completed_percent()` recomputes on every call —
  it loads all course contents, counts completed lesson metas, runs a `DISTINCT quiz_id`
  query over attempts, then loops assignments **one query per assignment**.
- A short-lived in-request `TutorCache` softens it; there is no persisted aggregate.

**Consequence:** rendering a "My Courses" list is O(courses × contents) queries.
This is the clearest thing Orbito must redesign (see `docs/DATABASE.md` §Progress).

### 3.8 Certificates **[Pro — inferred]**
Not present in the free core. Marketing describes a drag-and-drop certificate builder and
public certificate verification.

### 3.9 Reviews & Q&A
Reviews: `wp_comments` with `comment_type = tutor_course_rating`, rating value in comment
meta, optional admin approval before publish (`tutor_change_review_status`).
Q&A: `comment_type = tutor_q_and_a`, threaded via `comment_parent`, per-course toggle
`_tutor_enable_qa`, bulk actions, "Discussions" merges Q&A + lesson comments in 4.x.

**Gaps:** ratings in a text comment table means "average rating" is a `JOIN` + `AVG` over
`wp_commentmeta` on every course card. No aggregate column, no review helpfulness, no
instructor reply model distinct from a comment.

### 3.10 Monetization
Engines: **native** (built-in cart/checkout/orders), **WooCommerce**, **EDD**.
`CartFactory` → `NativeCart` | `WooCart` | `EddCart` is a clean seam.
`GatewayFactory::create()` → `GatewayBase` subclass; only **PayPal** ships in free core,
with an embedded vendored "Payment Hub" SDK (includes `brick/money`).
`GatewayBase` abstract surface: `get_root_dir_name()`, `get_payment_class()`,
`get_config_class()`, `setup_payment_and_redirect()`, `get_webhook_data()`,
`verify_webhook_signature()`, `make_recurring_payment()`, `make_refund()`.

Revenue sharing: percentage split, fee deduction, minimum withdrawal amount, maturity
days, withdraw methods (bank/PayPal/e-check). Coupons: code or automatic, percentage or
flat, scoped to all/courses/bundles/specific/category, usage limits, purchase requirements.
Tax: `ecommerce/Tax.php`, country/state rates, tax-on-single vs tax-on-subscription.

**Gaps:** prices are `DECIMAL(13,2)` with a single site currency — no multi-currency.
Coupon FKs reference `coupon_code` (a mutable business key) rather than the id.
`payment_payloads LONGTEXT` stores raw gateway responses inline on the order row.

### 3.11 Roles & permissions
Two real roles: `administrator` and `tutor_instructor`; students are WP `subscriber`.
Custom capabilities (verified in the live DB's `wp_user_roles`):
```
edit_tutor_course / edit_tutor_courses / edit_others_tutor_courses / delete_tutor_course(s)
edit_tutor_lesson(s) / edit_others_tutor_lessons / delete_tutor_lesson(s)
edit_tutor_quiz(zes) / edit_others_tutor_quizzes / delete_tutor_quiz(zes)
edit_tutor_question(s) / edit_others_tutor_questions / delete_tutor_question(s)
```
Instructor approval workflow: `_tutor_instructor_status` ∈ pending/approved/blocked.
A "view mode" switch lets an instructor browse as a student (`tutor_profile_view_mode`).

**Gaps:** no staff role, no course-scoped roles (TA / reviewer / course manager),
no permission registry — capability strings are hard-coded across ~50 files.

### 3.12 Dashboards
Student nav: Home, Courses, Discussions, Account (Profile / Reviews / Billing / Settings),
Wishlist, Purchase history, My quiz attempts.
Instructor nav: Home, Courses, Create Course (hidden), Create Bundle (hidden),
Announcements, Quiz Attempts, Discussions, Withdrawals.
Admin: WP-admin pages + a settings UI defined by one 66 KB PHP array (`Options_V2`).

Instructor home sections are user-reorderable (`_tutor_instructor_home_sections_order`) —
a nice touch worth keeping.

### 3.13 The real API surface

**137 `wp_ajax_*` actions.** This *is* Tutor's application API. Representative sample:
`tutor_create_course`, `tutor_update_course`, `tutor_course_delete`, `tutor_course_list`,
`tutor_course_contents`, `tutor_save_topic`, `tutor_delete_topic`, `tutor_save_lesson`,
`tutor_update_course_content_order`, `tutor_quiz_builder_save`, `tutor_quiz_timeout`,
`tutor_quiz_abandon`, `tutor_review_quiz_answers`, `tutor_course_enrollment`,
`tutor_complete_course`, `tutor_reset_course_progress`, `tutor_place_rating`,
`tutor_qna_create_update`, `tutor_add_course_to_cart`, `tutor_apply_coupon`,
`tutor_order_refund`, `tutor_make_an_withdraw`, `tutor_payment_gateways`,
`tutor_option_save`, `tutor_user_photo_upload`.

**REST (12 routes, namespace `tutor/v1`, read-mostly):**
`/courses`, `/courses/{id}`, `/topics`, `/lessons`, `/course-announcement/{id}`,
`/quizzes`, `/quizzes/{id}`, `/quiz-question-answer/{id}`, `/quiz-attempt-details/{id}`,
`/author-information/{id}`, `/course-rating/{id}`, `/course-contents/{id}`.
Auth via an API key/secret pair (`tutor-api-key-secret` usermeta) — `RestAuth.php`.

**Gaps:** the AJAX surface is untyped (everything is a POST of `$_POST`), returns
HTML fragments in many cases, is nonce-coupled (hard to consume from mobile), and has no
versioning, no schema, no consistent error envelope, and no pagination contract.
The REST API covers maybe 10% of the product — a mobile app cannot be built on it.

### 3.14 Security posture
Good: nonce helper (`Utils::checking_nonce`), capability checks on most AJAX handlers,
`Input` sanitizer class, prepared statements via `QueryHelper`, GDPR consent tables.
Concerns: serialized PHP in `question_settings` / `attempt_info` / `_tutor_course_settings`;
raw gateway payloads on the order row; `attempt_ip` stored without a retention policy;
authorization is per-handler and easy to miss (no central policy layer);
`tutor_handle_api_calls` has a `nopriv` variant.

### 3.15 Performance posture
- `wp_posts` + `wp_postmeta` + `wp_usermeta` carry courses, curriculum, enrollments,
  submissions, reviews, Q&A and progress. Everything is a meta join.
- Progress recomputed per request (§3.7).
- Course cards need: enrollment count, average rating, price, instructor, duration —
  each a separate meta/comment query unless cached.
- Mitigations present: request-scoped `TutorCache`, index additions in `Upgrader`,
  a batch-processor for migrations.

---

## 4. What we should keep (ideas, not code)

1. **Two completion modes** — flexible vs strict.
2. **Rich question type catalogue**, including interactive types.
3. **Feedback modes** — default / reveal / retry.
4. **Cart engine abstraction** (`CartFactory`) and **gateway abstraction** (`GatewayFactory`).
5. **Marketplace economics** — earnings split, fee deduction, withdrawal maturity days.
6. **Instructor approval workflow** with pending/approved/blocked.
7. **View-as-student toggle** for instructors.
8. **Reorderable instructor dashboard sections.**
9. **Coupon model** — automatic vs code, applies-to scoping, purchase requirements.
10. **Course "coming soon"** state and lesson preview flags.
11. **Spotlight mode** (distraction-free builder) and a strong course-builder UX.
12. **The hook philosophy** — Orbito's equivalent is a rich domain-event catalogue.

## 5. What we must not reproduce

| Tutor pattern | Orbito replacement |
|---|---|
| `Utils.php` god object (10.5k lines) | One Action per operation, per bounded context |
| Curriculum as posts + `menu_order` | `course_sections` + a single polymorphic `course_items` spine |
| Enrollment as a post type | First-class `enrollments` table with status, expiry, source |
| Progress as one usermeta row per lesson | `item_progress` + denormalised `course_progress` aggregate |
| Progress recomputed per request | Event-driven aggregate updates |
| Reviews/Q&A/submissions in `wp_comments` | Dedicated `reviews`, `discussions`, `assignment_submissions` |
| Serialized PHP blobs for settings | Typed columns + validated JSON with a schema |
| Ratings averaged on read | `rating_avg` / `rating_count` columns, event-maintained |
| 137 untyped AJAX actions returning HTML | Versioned REST, JSON only, typed resources |
| Capability strings hard-coded everywhere | Permission registry + Policies + course-scoped roles |
| Single-currency `DECIMAL(13,2)` | Integer minor units + currency code + FX table |
| FK on `coupon_code` (mutable business key) | FK on surrogate ids |
| Raw gateway payloads on the order row | Separate `payments` / `payment_events` tables |

---

## 6. Verification appendix

Commands used (all read-only):
```
find . -name '*.php' | wc -l                      # 861
du -sh .                                          # 27M
grep -rn "CREATE TABLE" --include=*.php .         # schema
grep -rhoE "wp_ajax_(nopriv_)?[a-z0-9_]+" ...     # 137 unique actions
grep -n "register_rest_route" classes/RestAPI.php # 12 routes
mysql … "SHOW TABLES LIKE 'wp_tutor%'"            # 21 tables
mysql … "SELECT post_type, COUNT(*) FROM wp_posts GROUP BY post_type"
mysql … "SELECT meta_key … FROM wp_postmeta/wp_usermeta WHERE meta_key LIKE '%tutor%'"
wc -l classes/Utils.php                           # 10503
grep -c "public function" classes/Utils.php       # 289
```
