# api/ — Laravel backend

**The authoritative engineering instructions for this repository live in `../CLAUDE.md`.**
Read that first. This file only notes what is specific to the backend workspace.

- Laravel 13, PHP 8.3+, API-only. There is no Blade UI and no Vite here —
  the SPA lives in `../web`.
- Domain code lives in `app/Domain/<Context>/`. See `../docs/ARCHITECTURE_PROPOSAL.md` §3.
- Run `composer check` before pushing (Pint + PHPStan + Pest).
