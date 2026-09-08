# Orbito LMS

A production-grade, API-first Learning Management System.

- **`api/`** — Laravel 13 backend (API only, no Blade UI)
- **`web/`** — React 19 + TypeScript SPA (Vite, Mantine, TanStack Query)
- **`docs/`** — architecture, audit and planning documents
- **`CLAUDE.md`** — engineering rules; read before writing code

**Status: Phases 0–15 complete**, front and back, plus a multi-tenancy
retrofit — one database per academy.
[`docs/ROADMAP.md`](docs/ROADMAP.md) opens with exactly where things stand.

999 backend tests / 3,238 assertions · 219 frontend tests · PHPStan level 6.

An instructor can build and publish a course with lessons, resources, quizzes,
assignments and live sessions, price it, schedule cohorts, announce things,
answer questions, mark everything from one grading queue, and read analytics
built from an append-only event log.

A learner can find a course, buy it, learn through a player with video resume
and notes, take a timed quiz, hand in work and read the feedback, attend a live
class, ask a question, review the course, earn points and badges, and download
a verifiable certificate — with a calendar, an inbox and a dashboard tying it
together.

**Two integrations are written and unproven, and need credentials rather than
code:** `StripeGateway` has never contacted Stripe (commerce is complete and
tested against a fake gateway; no real money has moved), and the Zoom and
Google Meet providers have never contacted either service (the manual
provider — paste a link — works and is tested).

**Next:** Phase 16 — subscriptions, bundles, downloads, the blog and page
builder, multilingual and RTL, plan-limit enforcement, outbound webhooks.

---

## Requirements

| | Version |
|---|---|
| PHP | 8.3 – 8.5 (developed on 8.4) |
| Composer | 2.x |
| MySQL | 8.0+ |
| Redis | 6+ |
| Node | 22+ |

The `redis` PHP extension is optional — the app uses `predis/predis`, a pure-PHP
client, so no compilation is needed.

## Setup

```bash
# 1. Databases
mysql -uroot -p -e "CREATE DATABASE orbito_lms      CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -uroot -p -e "CREATE DATABASE orbito_lms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 2. Backend
cd api
composer install
cp .env.example .env      # then set DB_USERNAME / DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan db:seed       # roles, permissions, categories, tags — plus six
                          # demo accounts in local/testing only

# 3. Frontend
cd ../web
npm install
cp .env.example .env
```

## Running

```bash
# terminal 1 — API on :8000
cd api && php artisan serve

# terminal 2 — queue workers
cd api && php artisan horizon

# terminal 3 — SPA on :5173
cd web && npm run dev
```

Then open <http://localhost:5173>. `/system` shows a live health check of the
API, database, cache and queue.

`db:seed` is safe to re-run — everything in it is `firstOrCreate`. Outside
`local` and `testing` it stops after roles, permissions and the catalogue
taxonomy, so it never creates demo accounts in production.

The demo accounts (local only) all use the password `password`:

| Email | Role |
|---|---|
| `super@orbito.test` | Super Admin — the one blanket authorization bypass |
| `admin@orbito.test` | Platform Admin |
| `staff@orbito.test` | Support Staff |
| `instructor@orbito.test` | Instructor, approved |
| `applicant@orbito.test` | Student with a pending instructor application |
| `student@orbito.test` | Student |

Adding a permission key to `config/permissions.php` means running
`php artisan permissions:sync`; nothing reads the file at runtime. The same
applies to `config/gamification.php` and `php artisan gamification:sync` —
except that one only ever CREATES what is missing, so an academy's own tuning
survives a re-sync. Both are seeded into a new academy at provisioning.

## Scheduled work

`php artisan schedule:work` in development, a cron entry in production. None of
it is load-bearing for correctness — expiry, drip and progress are evaluated
live on every request — but skipping it means stale rollups, no reminders and
no leaderboards:

| Command | When | What |
|---|---|---|
| `quiz:sweep-expired` | 5 min | Closes attempts past their deadline |
| `live:remind` | 5 min | Reminders for sessions starting soon |
| `gamification:leaderboards` | hourly | Rebuilds the board snapshots |
| `analytics:rollup --days=1` | hourly | Keeps today's figures current |
| `enrollment:sweep-expired` | hourly | Lapses expired enrolments |
| `subscriptions:expire` | 02:30 | Degrades unpaid academies |
| `gamification:sync` | 02:50 | Creates any missing rules and badges |
| `progress:reconcile` · `usage:reconcile` · `engagement:reconcile` | 03:10–03:50 | Corrects drift in denormalised counters |
| `analytics:rollup --days=2` | 04:00 | Yesterday and today, after the reconcilers |
| `analytics:prune` | weekly | 400-day retention on the event log |

Every one of these walks all academies itself — the scheduler runs centrally
with no tenant open, which is what `RunsForEveryTenant` and
`ScheduledCommandTest` exist for.

## Checks

```bash
cd api && composer check     # Pint (format) + PHPStan (level 6) + Pest
cd web && npm run check      # oxlint + tsc + vitest
cd web && npm run e2e        # Playwright (needs Ubuntu 22.04+ or CI)
```

CI runs all of the above on every push and pull request
(`.github/workflows/ci.yml`).

## Documentation

Start at [`docs/README.md`](docs/README.md).

## Reference installations — read only

Tutor LMS 4.0.7 lives at
`/var/www/html/wordpress-project/tutor-lms-mobile/wp-content/plugins/tutor`.
It is a **read-only functional reference**. Never modify that plugin or its database.
