# Orbito LMS

A production-grade, API-first Learning Management System.

- **`api/`** — Laravel 13 backend (API only, no Blade UI)
- **`web/`** — React 19 + TypeScript SPA (Vite, Mantine, TanStack Query)
- **`docs/`** — architecture, audit and planning documents
- **`CLAUDE.md`** — engineering rules; read before writing code

**Status:** Phase 2 (foundation) complete. Product features begin in Phase 3.
See [`docs/ROADMAP.md`](docs/ROADMAP.md).

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

Then open <http://localhost:5173>. `/system` shows a live health check of the API,
database, cache and queue — the Phase 2 exit criterion.

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
