# api/ — Laravel backend

**The authoritative engineering instructions for this repository live in `../CLAUDE.md`.**
Read that first. This file only notes what is specific to the backend workspace.

- Laravel 13, PHP 8.3+, API-only. There is no Blade UI and no Vite here —
  the SPA lives in `../web`.
- Domain code lives in `app/Domain/<Context>/`. See `../docs/ARCHITECTURE_PROPOSAL.md` §3.
- Run `composer check` before pushing (Pint + PHPStan + Pest). **Budget ~20
  minutes** — provisioning tests build real MySQL schemas. Run the files you
  touched first; save the full sweep for before a push.
- **The suite drops every schema matching the tenant prefix after every test.**
  It has its own (`TENANCY_DB_PREFIX` in `phpunit.xml`) precisely so it cannot
  reach your development academies. Do not remove that override.
- **Never run two suites at once** — including a targeted file beside the full
  run. Both drop the same prefix after every test, so each destroys the
  other's schemas and neither result means anything. Check
  `pgrep -af vendor/bin/pest` first. Before `composer test`/`check` disabled
  Composer's 300-second process timeout, a timed-out `composer check` reported
  failure and left Pest running unseen in the background.
- Anything that runs OUTSIDE the `tenant` middleware — login, register, a
  scheduled command, a webhook — must be tested with `tenancy()->end()` first.
  The harness leaves an academy open all test long and will otherwise pass code
  that dies in production. See `../docs/TESTING.md`.
