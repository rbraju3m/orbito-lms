# web/ — React SPA

**The authoritative engineering instructions for this repository live in `../CLAUDE.md`.**
Read that first — §5 is the frontend conventions. This file only notes what is
specific to the web workspace.

- React 19 + TypeScript + Vite + Mantine 9 + TanStack Query 5 + React Router.
  There is no server rendering; the API is `../api`.
- Feature code lives in `src/features/<domain>/`. See
  `../docs/FRONTEND_ARCHITECTURE.md` for the route map and the folder shape
  every feature repeats.
- Run `npm run check` before pushing (oxlint + tsc + vitest). ~2 minutes.
  `npm run build` too if you touched anything imported by `AppLayout`.
- **`shared/ui` holds five components and has not grown in eight phases.** A
  wrapper earns its place by encoding a decision — what an empty screen says,
  that an error carries a retry and a request id — not by being used twice.
  See `../docs/DESIGN_SYSTEM.md` §3 before adding a sixth.
- **Measure anything you add to `AppLayout` with `npm run size`.** It builds,
  sums the first-paint JS — the entry script plus every `modulepreload`,
  gzip-9 — and fails above the 255 KB budget. It is the ONLY measurement:
  Node's and Python's zlib disagree by 0.3 KB at the same level. Mantine is one
  shared chunk, so a lazy route does not keep its imports off first paint.
- **A studio, admin or platform page goes in `src/app/routes/<area>.tsx`,**
  not `router.tsx`. Those tables are discovered on first visit
  (`patchRoutesOnNavigation`) and kept off first paint; `router.test.tsx`
  fails if one lands in the eager table. A learner page goes in `router.tsx`.
- The academy a request resolves against comes from the SESSION, not from
  anything the client sends. A platform operator can be inside no academy at
  all, in which case tenant routes answer 409 `no_academy_selected` — handle
  that state rather than assuming `session.academy` is set.
