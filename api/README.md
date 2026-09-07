# Orbito LMS — API

Laravel 13, PHP 8.3+, API-only. No Blade UI, no Vite: the SPA lives in `../web`.

Setup, running instructions and the full documentation index are in the
[repository README](../README.md) and [`../docs/`](../docs/README.md).

## Layout

```
app/
├── Domain/<Context>/     bounded contexts — Models, Actions, Data, Events,
│                         Listeners, Policies, Enums, Exceptions, Queries
├── Http/                 Controllers/Api/V1, Requests, Resources, Middleware
├── Support/              cross-cutting: API envelope, exception renderer,
│                         request id, logging
└── Providers/
```

See [`app/Domain/README.md`](app/Domain/README.md) for the dependency rule between
contexts, and [`../docs/ARCHITECTURE_PROPOSAL.md`](../docs/ARCHITECTURE_PROPOSAL.md)
for why it is shaped this way.

## Commands

```bash
composer check        # pint --test + phpstan + pest, in CI order
composer test
composer lint         # pint (writes)
composer analyse      # phpstan, level 6

php artisan serve     # API on :8000
php artisan horizon   # queue workers
```

## Conventions

Route → Controller → Form Request → Policy → Action → Event → Resource.
Controllers are ~20 lines. Business logic lives in Actions. Authorization lives in
Policies. Response shape lives in Resources. See [`../CLAUDE.md`](../CLAUDE.md).
